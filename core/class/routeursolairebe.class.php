<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/routeursolairebeEngine.class.php';

/*
 * Un équipement = un boiler routé : le compteur qu'il écoute, le relais qu'il
 * commande, ses réglages.
 *
 * Cette classe fait le lien avec Jeedom et rien d'autre : elle lit le
 * compteur, appelle le moteur (routeursolairebeEngine), envoie l'ordre au
 * relais et tient les commandes à jour. Toute la décision est dans le moteur,
 * et c'est voulu : elle s'éprouve hors ligne, pas ici.
 *
 * Aucun démon : un écouteur sur la puissance du compteur fait passer le
 * routeur à chaque mesure (toutes les 10 s avec un P1 HomeWizard), et le cron
 * du coeur le fait passer chaque minute, même si le compteur se tait.
 *
 * Tant qu'aucun relais n'est choisi, ou si la case « Simulation » est cochée,
 * le boiler est simulé : le routeur décide exactement comme il le ferait en
 * vrai, mais sur le compteur augmenté de la consommation d'un boiler virtuel,
 * et n'envoie aucun ordre. Les traces de chaque journée permettent de régler
 * les seuils avant que le boiler existe.
 */
class routeursolairebe extends eqLogic {

    /* Les décisions de la page : au plus ce nombre d'entrées. */
    const JOURNAL_SIZE = 40;

    /* Les traces CSV : une ligne par minute, gardées ce nombre de jours. */
    const TRACE_DAYS = 90;

    /* Les infos qui bougent à chaque mesure ne sont publiées qu'à ce rythme :
     * une ligne d'historique toutes les 10 s pour le surplus, le réseau
     * virtuel et le boiler, c'est 26 000 lignes par jour pour rien. */
    const PUBLISH_EVERY = 60;

    const WIDGET_INFOS = array('state', 'status', 'reason', 'relay', 'surplus', 'grid', 'boiler_power', 'tank',
                               'heated_today', 'solar_today', 'forced_today', 'savings_today', 'deficit', 'mode',
                               'simulation', 'cycles_today');

    /* ==================================================================== CRON */

    public static function cron() {
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            try {
                $eqLogic->evaluate('cron');
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    public static function cronDaily() {
        self::purgeTraces();
    }

    /* L'écouteur : le compteur a publié une nouvelle mesure. */
    public static function pull($_options) {
        $eqLogic = self::byId($_options['eqLogic_id']);
        if (!is_object($eqLogic) || $eqLogic->getIsEnable() != 1) {
            return;
        }
        try {
            $eqLogic->evaluate('event');
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    /* ===================================================== CYCLE DE VIE eqLogic */

    public function preSave() {
        /* Les réglages passent par le moteur, qui leur impose défauts et
         * bornes : « 2,4 » vaut 2.4, une plage mal tapée est réécrite. */
        $raw = array();
        foreach (array_merge(array_keys(routeursolairebeEngine::DEFAULTS), array_keys(routeursolairebeEngine::TEXT_DEFAULTS)) as $key) {
            $value = $this->getConfiguration($key, null);
            if ($value !== null && $value !== '') {
                $raw[$key] = $value;
            }
        }
        $raw['mode'] = $this->getConfiguration('mode', routeursolairebeEngine::MODE_AUTO);
        $clean = routeursolairebeEngine::cleanSettings($raw);
        foreach ($clean as $key => $value) {
            $this->setConfiguration($key, $value);
        }
        if ($this->getConfiguration('simulation', '') === '') {
            $this->setConfiguration('simulation', 1);
        }
        $resend = routeursolairebeEngine::number($this->getConfiguration('relay_resend', ''));
        $this->setConfiguration('relay_resend', ($resend === null) ? 5 : max(0, min(60, (int) $resend)));
        if ($this->getDisplay('width') == '') {
            $this->setDisplay('width', '280px');
        }
    }

    public function postSave() {
        $this->createCommands();
        $this->updateListener();
        if ($this->getIsEnable() != 1) {
            /* Désactivé, il ne passe plus au cron : un boiler laissé allumé le
             * resterait. On coupe avant de se taire. */
            $this->shutdown(__('équipement désactivé', __FILE__));
            return;
        }
        try {
            $this->evaluate('save');
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    public function preRemove() {
        $this->shutdown(__('équipement supprimé', __FILE__));
        $listener = listener::byClassAndFunction(__CLASS__, 'pull', array('eqLogic_id' => intval($this->getId())));
        if (is_object($listener)) {
            $listener->remove();
        }
        foreach (array('state', 'decision', 'published', 'sent', 'journal', 'saved') as $name) {
            cache::delete($this->rtKey($name));
        }
        foreach (array('state', 'journal') as $name) {
            config::remove($this->rtKey($name), __CLASS__);
        }
    }

    /* L'écouteur suit le compteur, et la mesure du boiler s'il y en a une. */
    private function updateListener() {
        $listener = listener::byClassAndFunction(__CLASS__, 'pull', array('eqLogic_id' => intval($this->getId())));
        $ids = array_values(array_unique(array_filter(array($this->cmdId('grid_cmd'), $this->cmdId('boiler_power_cmd')))));
        if ($this->getIsEnable() != 1 || count($ids) == 0) {
            if (is_object($listener)) {
                $listener->remove();
            }
            return;
        }
        if (!is_object($listener)) {
            $listener = new listener();
            $listener->setClass(__CLASS__);
            $listener->setFunction('pull');
            $listener->setOption(array('eqLogic_id' => intval($this->getId())));
        }
        $listener->emptyEvent();
        foreach ($ids as $id) {
            $listener->addEvent($id);
        }
        $listener->save();
    }

    /* ============================================================== COMMANDES */

    public function createCommands() {
        $modes = array();
        foreach (routeursolairebeEngine::MODES as $mode) {
            $modes[] = $mode . '|' . routeursolairebeEngine::modeLabel($mode);
        }
        $definitions = array(
            array('logicalId' => 'state', 'name' => __('État', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'visible' => 1),
            array('logicalId' => 'status', 'name' => __('État (code)', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'visible' => 0),
            array('logicalId' => 'reason', 'name' => __('Raison', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'visible' => 1),
            array('logicalId' => 'relay', 'name' => __('Boiler commandé', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'visible' => 1, 'historized' => 1),
            array('logicalId' => 'surplus', 'name' => __('Surplus disponible', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'visible' => 1, 'historized' => 1, 'unite' => 'W'),
            array('logicalId' => 'grid', 'name' => __('Réseau avec boiler', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'visible' => 0, 'historized' => 1, 'unite' => 'W'),
            array('logicalId' => 'boiler_power', 'name' => __('Puissance boiler', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'visible' => 0, 'historized' => 1, 'unite' => 'W'),
            array('logicalId' => 'tank', 'name' => __('Charge du boiler simulé', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'visible' => 0, 'historized' => 1, 'unite' => '%'),
            array('logicalId' => 'full', 'name' => __('Boiler plein', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'visible' => 0, 'historized' => 1),
            array('logicalId' => 'deficit', 'name' => __('Reste à chauffer', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'visible' => 0, 'unite' => 'kWh'),
            array('logicalId' => 'heated_today', 'name' => __('Chauffé du jour', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'visible' => 1, 'historized' => 1, 'unite' => 'kWh'),
            array('logicalId' => 'solar_today', 'name' => __('Solaire routé du jour', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'visible' => 1, 'historized' => 1, 'unite' => 'kWh'),
            array('logicalId' => 'grid_today', 'name' => __('Prélevé pour le boiler du jour', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'visible' => 0, 'historized' => 1, 'unite' => 'kWh'),
            array('logicalId' => 'forced_today', 'name' => __('Chauffe forcée du jour', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'visible' => 0, 'historized' => 1, 'unite' => 'kWh'),
            array('logicalId' => 'exported_today', 'name' => __('Encore injecté du jour', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'visible' => 0, 'historized' => 1, 'unite' => 'kWh'),
            array('logicalId' => 'savings_today', 'name' => __('Gain du jour', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'visible' => 1, 'historized' => 1, 'unite' => '€'),
            array('logicalId' => 'cycles_today', 'name' => __('Allumages du jour', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'visible' => 0, 'historized' => 1),
            array('logicalId' => 'on_time_today', 'name' => __('Temps de chauffe du jour', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'visible' => 0, 'historized' => 1, 'unite' => 'min'),
            array('logicalId' => 'days_since_full', 'name' => __('Jours depuis boiler plein', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'visible' => 0, 'unite' => 'j'),
            array('logicalId' => 'simulation', 'name' => __('Simulation', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'visible' => 0),
            array('logicalId' => 'mode', 'name' => __('Mode', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'visible' => 0),
            array('logicalId' => 'set_mode', 'name' => __('Choisir le mode', __FILE__),
                  'type' => 'action', 'subType' => 'select', 'visible' => 1, 'value' => 'mode',
                  'list' => implode(';', $modes)),
            array('logicalId' => 'refresh', 'name' => __('Rafraîchir', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'visible' => 0),
        );

        $order = 0;
        foreach ($definitions as $definition) {
            $cmd = $this->getCmd(null, $definition['logicalId']);
            if (!is_object($cmd)) {
                $cmd = new routeursolairebeCmd();
                $cmd->setLogicalId($definition['logicalId']);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName($definition['name']);
                $cmd->setIsVisible($definition['visible']);
                $cmd->setIsHistorized(isset($definition['historized']) ? $definition['historized'] : 0);
                if (isset($definition['unite'])) {
                    $cmd->setUnite($definition['unite']);
                }
            }
            $cmd->setType($definition['type']);
            $cmd->setSubType($definition['subType']);
            if (isset($definition['list'])) {
                $cmd->setConfiguration('listValue', $definition['list']);
            }
            $cmd->setOrder($order++);
            $cmd->save();
        }
        /* L'action liée à son info : la liste du tableau de bord affiche ainsi
         * le mode en cours. */
        foreach ($definitions as $definition) {
            if (!isset($definition['value'])) {
                continue;
            }
            $cmd = $this->getCmd(null, $definition['logicalId']);
            $info = $this->getCmd(null, $definition['value']);
            if (is_object($cmd) && is_object($info) && $cmd->getValue() != $info->getId()) {
                $cmd->setValue($info->getId());
                $cmd->save();
            }
        }
    }

    /* ================================================================ LECTURES */

    public function rtKey($_name) {
        return 'routeursolairebe::' . $_name . '::' . $this->getId();
    }

    /* L'identifiant d'une commande choisie dans la page, ou null. */
    public function cmdId($_key) {
        if (preg_match('/#(\d+)#/', (string) $this->getConfiguration($_key, ''), $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /*
     * Lit une commande info numérique : sa valeur, ou null si elle est
     * absente, illisible ou plus ancienne que $_maxAge secondes. Une mesure
     * périmée n'est jamais remplacée par zéro : zéro au compteur, c'est
     * « ni surplus ni prélèvement », pas « je ne sais pas ».
     */
    public static function readNumber($_cmdId, $_maxAge = 0) {
        if ($_cmdId === null) {
            return null;
        }
        try {
            $cmd = cmd::byId($_cmdId);
            if (!is_object($cmd) || $cmd->getType() != 'info') {
                return null;
            }
            $value = $cmd->execCmd();
            if ($value === '' || $value === null || !is_numeric($value)) {
                return null;
            }
            if ($_maxAge > 0) {
                $collected = strtotime((string) $cmd->getCollectDate());
                if ($collected !== false && $collected > 0 && time() - $collected > $_maxAge) {
                    return null;
                }
            }
            return (float) $value;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function settings() {
        $raw = array();
        foreach (array_merge(array_keys(routeursolairebeEngine::DEFAULTS), array_keys(routeursolairebeEngine::TEXT_DEFAULTS), array('mode')) as $key) {
            $value = $this->getConfiguration($key, null);
            if ($value !== null && $value !== '') {
                $raw[$key] = $value;
            }
        }
        return routeursolairebeEngine::cleanSettings($raw);
    }

    /* Le boiler est simulé tant qu'il n'a pas ses deux commandes de relais,
     * ou tant que la case est cochée. */
    public function isSimulation() {
        return $this->getConfiguration('simulation', 1) == 1
            || $this->cmdId('on_cmd') === null || $this->cmdId('off_cmd') === null;
    }

    /* La puissance au compteur, positive quand la maison prélève. */
    public function gridPower($_settings) {
        $value = self::readNumber($this->cmdId('grid_cmd'), $_settings['grid_max_age']);
        if ($value === null) {
            return null;
        }
        return ($this->getConfiguration('grid_invert', 0) == 1) ? -$value : $value;
    }

    /* ================================================================== ÉTAT */

    /*
     * L'état du moteur : dans le cache pour aller vite — il est relu à chaque
     * mesure du compteur — et recopié en base à chaque changement du relais,
     * et au moins toutes les dix minutes. Un cache vidé ne doit pas faire
     * oublier les kWh déjà chauffés aujourd'hui, ni le dernier boiler plein.
     */
    private function loadState() {
        $state = cache::byKey($this->rtKey('state'))->getValue(null);
        if (!is_array($state)) {
            $state = json_decode((string) config::byKey($this->rtKey('state'), __CLASS__, ''), true);
        }
        return is_array($state) ? $state : array();
    }

    private function storeState($_state, $_persist) {
        cache::set($this->rtKey('state'), $_state);
        if ($_persist) {
            config::save($this->rtKey('state'), json_encode($_state), __CLASS__);
            cache::set($this->rtKey('saved'), time());
        }
    }

    /* ============================================================ ÉVALUATION */

    /*
     * Un passage du routeur : lire, décider, agir, afficher.
     *
     * Protégé par un verrou de fichier : le cron et l'écouteur peuvent tomber
     * dans la même seconde, et deux passages simultanés compteraient deux fois
     * le même intervalle.
     */
    public function evaluate($_origin = 'cron') {
        if ($this->getIsEnable() != 1) {
            return null;
        }
        $lock = @fopen(jeedom::getTmpFolder(__CLASS__) . '/eq' . $this->getId() . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            log::add(__CLASS__, 'warning', $this->getHumanName() . ' : ' . __('verrou indisponible, évaluation sans verrou', __FILE__));
            if ($lock !== false) {
                fclose($lock);
            }
            return $this->evaluateLocked($_origin);
        }
        try {
            return $this->evaluateLocked($_origin);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function evaluateLocked($_origin) {
        $settings = $this->settings();
        $simulation = $this->isSimulation();
        $now = time();
        $inputs = array(
            'now'        => $now,
            'grid'       => $this->gridPower($settings),
            'boiler'     => $simulation ? null : self::readNumber($this->cmdId('boiler_power_cmd'), 300),
            'simulation' => $simulation,
        );
        $state = $this->loadState();
        $previous = isset($state['relay']) ? (int) $state['relay'] : 0;
        $decision = routeursolairebeEngine::decide($settings, $inputs, $state);
        $changed = ($decision['target'] != $previous);

        $savedAt = (int) cache::byKey($this->rtKey('saved'))->getValue(0);
        $this->storeState($decision['state'], $changed || count($decision['events']) > 0 || $now - $savedAt >= 600);

        if ($this->cmdId('grid_cmd') === null) {
            $decision['status'] = 'alert';
            $decision['reason'] = __('Choisissez la puissance du compteur (onglet Mesures et boiler)', __FILE__);
        }
        $this->handleEvents($decision, $simulation);
        if ($changed) {
            $label = $simulation ? __('[simulation] ', __FILE__) : '';
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . $label
                . ($decision['target'] ? __('boiler allumé', __FILE__) : __('boiler éteint', __FILE__)) . ' — ' . $decision['reason']);
            $this->journal(($decision['target'] ? __('Allumé', __FILE__) : __('Éteint', __FILE__)) . ' — ' . $decision['reason'],
                           $decision['target'] ? 'on' : 'off');
        } else {
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' [' . $_origin . '] ' . $this->traceText($decision) . ' — ' . $decision['reason']);
        }

        $this->apply($decision, $changed, $simulation);

        /* Une ligne de trace par minute, et à chaque bascule du relais. */
        if ($_origin == 'cron' || $changed) {
            $this->trace($decision, $simulation);
        }

        $decision['at'] = $now;
        $decision['simulation'] = $simulation;
        $decision['production'] = self::readNumber($this->cmdId('production_cmd'), 900);
        cache::set($this->rtKey('decision'), $decision);

        $publishedAt = (int) cache::byKey($this->rtKey('published'))->getValue(0);
        $full = $_origin != 'event' || $changed || $now - $publishedAt >= self::PUBLISH_EVERY;
        $this->refreshInfo($decision, $settings, $simulation, $full);
        if ($full) {
            cache::set($this->rtKey('published'), $now);
        }
        return $decision;
    }

    /* Ce que le moteur signale en passant : boiler plein, douche simulée,
     * nouvelle journée. */
    private function handleEvents($_decision, $_simulation) {
        foreach (array_unique($_decision['events']) as $event) {
            switch ($event) {
                case 'full':
                    log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('boiler plein', __FILE__));
                    $this->journal(__('Boiler plein : le thermostat du boiler a coupé', __FILE__), 'full');
                    break;
                case 'rollover':
                    $y = $_decision['values']['yesterday'];
                    if (is_array($y)) {
                        $text = $this->summaryText($y);
                        log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . $text);
                        $this->journal($text, 'day');
                        $this->traceDay($y, $_simulation);
                    }
                    break;
            }
        }
    }

    public function summaryText($_day) {
        return sprintf(__('Journée du %s : %s chauffés, dont %s de soleil et %s forcés ; %d allumage(s), %s de chauffe, gain %s €, encore %s injectés%s', __FILE__),
            date('d/m', strtotime($_day['period'])),
            routeursolairebeEngine::kwh($_day['heated']), routeursolairebeEngine::kwh($_day['solar']),
            routeursolairebeEngine::kwh($_day['forced']), $_day['cycles'],
            routeursolairebeEngine::duration($_day['on_s']), number_format($_day['savings'], 2, ',', ''),
            routeursolairebeEngine::kwh($_day['exported']),
            $_day['full'] ? __(', boiler plein', __FILE__) : '');
    }

    private function traceText($_decision) {
        $v = $_decision['values'];
        return sprintf('réseau %s, surplus %s, boiler %s, seau %d Wh%s',
            routeursolairebeEngine::watts($v['grid']), routeursolairebeEngine::watts($v['avail']),
            routeursolairebeEngine::watts($v['boiler']), round($v['bucket']),
            ($v['tank'] === null) ? '' : ', boiler simulé ' . $v['tank'] . ' %');
    }

    /* ================================================================ RELAIS */

    /*
     * Envoie l'ordre au relais, à la bascule, puis à nouveau toutes les
     * « relay_resend » minutes : un ordre perdu en route, ou un relais
     * remis à zéro par une coupure, se rattrape tout seul. En simulation,
     * rien ne part.
     */
    private function apply($_decision, $_changed, $_simulation) {
        if ($_simulation) {
            return;
        }
        $sent = cache::byKey($this->rtKey('sent'))->getValue(null);
        $resend = (int) $this->getConfiguration('relay_resend', 5) * 60;
        $target = (int) $_decision['target'];
        $due = !is_array($sent) || $sent['target'] !== $target || $_changed
            || ($resend > 0 && time() - (int) $sent['at'] >= $resend);
        if (!$due) {
            return;
        }
        if ($this->execAction($this->cmdId($target ? 'on_cmd' : 'off_cmd'))) {
            cache::set($this->rtKey('sent'), array('target' => $target, 'at' => time()));
        }
    }

    public function execAction($_cmdId) {
        if ($_cmdId === null) {
            return false;
        }
        try {
            $cmd = cmd::byId($_cmdId);
            if (!is_object($cmd) || $cmd->getType() != 'action') {
                log::add(__CLASS__, 'error', $this->getHumanName() . ' : '
                    . __('commande introuvable ou qui n\'est pas une action :', __FILE__) . ' #' . $_cmdId . '#');
                return false;
            }
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' → ' . $cmd->getHumanName());
            $cmd->execCmd();
            return true;
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
            return false;
        }
    }

    /* Coupe le vrai boiler avant que le routeur se taise. */
    public function shutdown($_why) {
        if ($this->isSimulation()) {
            return;
        }
        $state = $this->loadState();
        if (!empty($state['relay'])) {
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('boiler éteint', __FILE__) . ' — ' . $_why);
        }
        $this->execAction($this->cmdId('off_cmd'));
        cache::delete($this->rtKey('sent'));
        if (is_array($state) && count($state) > 0) {
            $state['relay'] = 0;
            $state['off_at'] = time();
            $this->storeState($state, true);
        }
    }

    /* ================================================================== MODE */

    /*
     * Change le mode. Enregistré sur une copie relue en base, sans preSave ni
     * postSave : l'objet qui appelle a pu être chargé avant que la page soit
     * enregistrée, et l'enregistrer effacerait ce qu'on vient d'y régler.
     */
    public function changeMode($_mode) {
        if (!in_array($_mode, routeursolairebeEngine::MODES, true)) {
            throw new Exception(__('Mode inconnu :', __FILE__) . ' ' . $_mode);
        }
        $fresh = self::byId($this->getId());
        if (!is_object($fresh)) {
            return;
        }
        if ($fresh->getConfiguration('mode') != $_mode) {
            $fresh->setConfiguration('mode', $_mode);
            $fresh->save(true);
            $this->journal(__('Mode', __FILE__) . ' : ' . routeursolairebeEngine::modeLabel($_mode), 'mode');
        }
        $this->setConfiguration('mode', $_mode);
        $fresh->evaluate('command');
    }

    /* ============================================================== AFFICHAGE */

    /*
     * Publie les infos. Le texte et le relais à chaque passage ; les
     * puissances et les compteurs au plus une fois par minute.
     *
     * Surtout pas nommée setCmd() : à l'enregistrement, utils::a2o() appelle
     * « set » + chaque clé reçue, et la page envoie toujours une clé « cmd ».
     */
    private function refreshInfo($_decision, $_settings, $_simulation, $_full) {
        $v = $_decision['values'];
        $this->publishCmd('relay', (int) $_decision['target']);
        $this->publishCmd('status', $_decision['status']);
        $this->publishCmd('state', routeursolairebeEngine::stateLabel($_decision['status']));
        $this->publishCmd('reason', $_decision['reason']);
        $this->publishCmd('mode', $_settings['mode']);
        $this->publishCmd('simulation', $_simulation ? 1 : 0);
        $this->publishCmd('full', (int) $v['full']);
        if (!$_full) {
            return;
        }
        $s = $v['stats'];
        if ($v['avail'] !== null) {
            $this->publishCmd('surplus', round($v['avail']));
        }
        if ($v['grid'] !== null) {
            $this->publishCmd('grid', round($v['grid']));
        }
        $this->publishCmd('boiler_power', round($v['boiler']));
        if ($v['tank'] !== null) {
            $this->publishCmd('tank', $v['tank']);
        }
        $this->publishCmd('deficit', round($v['deficit'], 2));
        $this->publishCmd('heated_today', round($s['heated'], 3));
        $this->publishCmd('solar_today', round($s['solar'], 3));
        $this->publishCmd('grid_today', round($s['grid'], 3));
        $this->publishCmd('forced_today', round($s['forced'], 3));
        $this->publishCmd('exported_today', round($s['exported'], 3));
        $this->publishCmd('savings_today', round($s['savings'], 3));
        $this->publishCmd('cycles_today', (int) $s['cycles']);
        $this->publishCmd('on_time_today', round($s['on_s'] / 60));
        $this->publishCmd('days_since_full', $v['days_full']);
    }

    private function publishCmd($_logicalId, $_value) {
        $this->checkAndUpdateCmd($_logicalId, $_value);
    }

    /* =============================================================== JOURNAL */

    /*
     * Le journal des décisions, pour la page : les bascules du relais avec
     * leur raison, les boilers pleins, le bilan de chaque journée. En base :
     * le journal du plugin tourne à 500 lignes.
     */
    public function journal($_text, $_kind = 'info') {
        $entries = $this->journalEntries();
        array_unshift($entries, array('at' => time(), 'kind' => $_kind, 'text' => (string) $_text));
        $entries = array_slice($entries, 0, self::JOURNAL_SIZE);
        cache::set($this->rtKey('journal'), $entries);
        config::save($this->rtKey('journal'), json_encode($entries, JSON_UNESCAPED_UNICODE), __CLASS__);
    }

    public function journalEntries() {
        $entries = cache::byKey($this->rtKey('journal'))->getValue(null);
        if (!is_array($entries)) {
            $entries = json_decode((string) config::byKey($this->rtKey('journal'), __CLASS__, ''), true);
        }
        return is_array($entries) ? $entries : array();
    }

    public function journalForDisplay() {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        return array_map(function ($_entry) use ($today, $yesterday) {
            $day = date('Y-m-d', $_entry['at']);
            if ($day == $today) {
                $when = date('H:i', $_entry['at']);
            } elseif ($day == $yesterday) {
                $when = __('hier', __FILE__) . ' ' . date('H:i', $_entry['at']);
            } else {
                $when = date('d/m H:i', $_entry['at']);
            }
            return array('when' => $when, 'kind' => $_entry['kind'], 'text' => $_entry['text']);
        }, $this->journalEntries());
    }

    /* ================================================================ TRACES */

    /*
     * Les traces : un fichier CSV par jour et par équipement, une ligne par
     * minute. C'est avec elles qu'on règle le routeur : le journal du plugin
     * est coupé à 500 lignes, l'historique de Jeedom est moyenné à l'heure
     * après une journée. Dans data/traces, que le déploiement ne touche pas.
     */
    public static function traceFolder() {
        return __DIR__ . '/../../data/traces';
    }

    private function traceFile($_day) {
        return self::traceFolder() . '/eq' . $this->getId() . '-' . $_day . '.csv';
    }

    private function writeCsv($_file, $_header, $_row) {
        $folder = dirname($_file);
        if (!is_dir($folder) && !@mkdir($folder, 0775, true)) {
            return;
        }
        $new = !file_exists($_file);
        $handle = @fopen($_file, 'a');
        if ($handle === false) {
            return;
        }
        if ($new) {
            fputcsv($handle, $_header, ';', '"', '');
        }
        fputcsv($handle, $_row, ';', '"', '');
        fclose($handle);
    }

    private static function csvNumber($_value, $_decimals = 0) {
        return ($_value === null) ? '' : number_format((float) $_value, $_decimals, ',', '');
    }

    private function trace($_decision, $_simulation) {
        $v = $_decision['values'];
        $this->writeCsv($this->traceFile(date('Y-m-d')),
            array('heure', 'reseau_reel_W', 'reseau_avec_boiler_W', 'surplus_W', 'boiler_W', 'relais', 'seau_Wh',
                  'charge_boiler_pct', 'chauffe_kWh', 'solaire_kWh', 'force_kWh', 'simulation', 'code', 'raison'),
            array(date('H:i:s'), self::csvNumber($v['grid_real']), self::csvNumber($v['grid']), self::csvNumber($v['avail']),
                  self::csvNumber($v['boiler']), (int) $_decision['target'], self::csvNumber($v['bucket']),
                  self::csvNumber($v['tank'], 1), self::csvNumber($v['stats']['heated'], 3),
                  self::csvNumber($v['stats']['solar'], 3), self::csvNumber($v['stats']['forced'], 3),
                  $_simulation ? 1 : 0, $_decision['code'], $_decision['reason']));
    }

    /* Le bilan de chaque journée, dans un fichier à part : une ligne par jour. */
    private function traceDay($_day, $_simulation) {
        $this->writeCsv(self::traceFolder() . '/eq' . $this->getId() . '-journees.csv',
            array('journee', 'chauffe_kWh', 'solaire_kWh', 'preleve_kWh', 'force_kWh', 'encore_injecte_kWh',
                  'allumages', 'chauffe_min', 'gain_EUR', 'cout_EUR', 'boiler_plein', 'simulation'),
            array($_day['period'], self::csvNumber($_day['heated'], 3), self::csvNumber($_day['solar'], 3),
                  self::csvNumber($_day['grid'], 3), self::csvNumber($_day['forced'], 3),
                  self::csvNumber($_day['exported'], 3), (int) $_day['cycles'], round($_day['on_s'] / 60),
                  self::csvNumber($_day['savings'], 2), self::csvNumber($_day['cost'], 2), (int) $_day['full'],
                  $_simulation ? 1 : 0));
    }

    public static function purgeTraces() {
        $limit = time() - self::TRACE_DAYS * 86400;
        foreach ((array) glob(self::traceFolder() . '/eq*-????-??-??.csv') as $file) {
            if (is_file($file) && filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }

    /* Les fichiers de trace de cet équipement, du plus récent au plus ancien. */
    public function traceList() {
        $files = array();
        foreach ((array) glob(self::traceFolder() . '/eq' . $this->getId() . '-*.csv') as $file) {
            $files[] = substr(basename($file), strlen('eq' . $this->getId() . '-'), -4);
        }
        rsort($files);
        return $files;
    }

    /* Le contenu d'une trace : le nom passe par une liste blanche, jamais
     * tel que le navigateur l'envoie. */
    public function traceContent($_name) {
        if (!in_array($_name, $this->traceList(), true)) {
            throw new Exception(__('Trace introuvable', __FILE__));
        }
        return (string) file_get_contents(self::traceFolder() . '/eq' . $this->getId() . '-' . $_name . '.csv');
    }

    /* ================================================================= PAGE */

    /* La dernière décision, pour le panneau « En ce moment » de la page. */
    public function status() {
        $decision = cache::byKey($this->rtKey('decision'))->getValue(null);
        $result = array('journal' => $this->journalForDisplay(), 'traces' => $this->traceList(),
                        'mode' => $this->getConfiguration('mode', routeursolairebeEngine::MODE_AUTO));
        if (!is_array($decision)) {
            return $result;
        }
        $v = $decision['values'];
        $s = $v['stats'];
        return array_merge($result, array(
            'at'         => date('H:i:s', $decision['at']),
            'status'     => $decision['status'],
            'state'      => routeursolairebeEngine::stateLabel($decision['status']),
            'reason'     => $decision['reason'],
            'relay'      => (int) $decision['target'],
            'simulation' => $decision['simulation'] ? 1 : 0,
            'rows'       => array(
                array(__('Compteur', __FILE__), routeursolairebeEngine::watts($v['grid_real'])),
                array(__('Compteur avec le boiler', __FILE__), routeursolairebeEngine::watts($v['grid'])),
                array(__('Surplus disponible (lissé)', __FILE__), routeursolairebeEngine::watts($v['avail'])
                      . ' / ' . __('seuil', __FILE__) . ' ' . routeursolairebeEngine::watts($v['threshold'])),
                array(__('Production', __FILE__), routeursolairebeEngine::watts($decision['production'])),
                array(__('Boiler', __FILE__), routeursolairebeEngine::watts($v['boiler'])
                      . ($v['full'] ? ' — ' . __('plein', __FILE__) : '')),
                array(__('Charge du boiler simulé', __FILE__), ($v['tank'] === null) ? '—'
                      : $v['tank'] . ' % (' . routeursolairebeEngine::kwh($v['tank_kwh']) . ' / ' . routeursolairebeEngine::kwh($v['capacity']) . ')'),
                array(__('Prélevé toléré (seau)', __FILE__), round($v['bucket']) . ' Wh'),
                array(__('Chauffé aujourd\'hui', __FILE__), routeursolairebeEngine::kwh($s['heated'])
                      . ' (' . __('soleil', __FILE__) . ' ' . routeursolairebeEngine::kwh($s['solar'])
                      . ', ' . __('forcé', __FILE__) . ' ' . routeursolairebeEngine::kwh($s['forced']) . ')'),
                array(__('Reste à chauffer', __FILE__), routeursolairebeEngine::kwh($v['deficit'])),
                array(__('Allumages / chauffe', __FILE__), $s['cycles'] . ' / ' . routeursolairebeEngine::duration($s['on_s'])),
                array(__('Gain estimé du jour', __FILE__), number_format($s['savings'], 2, ',', '') . ' €'),
                array(__('Encore injecté aujourd\'hui', __FILE__), routeursolairebeEngine::kwh($s['exported'])),
                array(__('Dernier boiler plein', __FILE__), sprintf(__('il y a %s jour(s)', __FILE__), number_format($v['days_full'], 1, ',', ''))),
            ),
            'yesterday'  => is_array($v['yesterday']) ? $this->summaryText($v['yesterday']) : '',
        ));
    }

    /* ================================================================ TUILE */

    public function toHtml($_version = 'dashboard') {
        if (jeedom::versionAlias($_version) !== 'dashboard') {
            return parent::toHtml($_version);
        }
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }
        $version = jeedom::versionAlias($_version);
        $ids = array();
        $state = array();
        foreach (array_merge(self::WIDGET_INFOS, array('set_mode', 'refresh')) as $logicalId) {
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                continue;
            }
            $ids[$logicalId] = (string) $cmd->getId();
            if ($cmd->getType() == 'info') {
                $value = $cmd->execCmd();
                $state[$logicalId] = ($value === null) ? '' : (string) $value;
            }
        }
        $modes = array();
        foreach (routeursolairebeEngine::MODES as $mode) {
            $modes[$mode] = routeursolairebeEngine::modeLabel($mode);
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $replace['#rs_ids#'] = htmlspecialchars(json_encode($ids), ENT_QUOTES);
        $replace['#rs_state#'] = htmlspecialchars(json_encode($state, $flags), ENT_QUOTES);
        $replace['#rs_modes#'] = htmlspecialchars(json_encode($modes, $flags), ENT_QUOTES);
        $replace['#refresh_id#'] = isset($ids['refresh']) ? $ids['refresh'] : '';
        $template = getTemplate('core', $version, 'routeursolairebe', __CLASS__);
        $html = translate::exec($template, 'plugins/routeursolairebe/core/template/' . $version . '/routeursolairebe.html');
        return $this->postToHtml($_version, template_replace($replace, $html));
    }
}

/*
 * La classe de commande est obligatoire, même réduite à son execute() : sans
 * elle, le coeur refuse de créer un équipement du plugin.
 */
class routeursolairebeCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        switch ($this->getLogicalId()) {
            case 'set_mode':
                /* Un widget mobile tire parfois l'action sans valeur : on
                 * ignore ce tir à blanc. */
                if (!isset($_options['select']) || $_options['select'] === '') {
                    return;
                }
                $eqLogic->changeMode($_options['select']);
                return;
            case 'refresh':
                $eqLogic->evaluate('command');
                return;
        }
    }
}
