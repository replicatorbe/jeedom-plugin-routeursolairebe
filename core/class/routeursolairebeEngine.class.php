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

/*
 * Le cerveau du routeur, sans Jeedom.
 *
 * Il reçoit la puissance au compteur, l'état qu'il a laissé au passage
 * précédent et les réglages, et rend une décision : boiler allumé ou non, et
 * pourquoi. Il ne lit aucune commande et n'envoie aucun ordre : c'est ce qui
 * permet de rejouer des journées entières hors ligne (tests/run.php), et de
 * régler les seuils avant même que le boiler existe.
 *
 * Convention de signe, partout dans ce fichier : une puissance réseau positive
 * est prélevée, négative est injectée. C'est celle du compteur P1 HomeWizard.
 *
 * Le boiler n'a pas de gradateur : un relais, tout ou rien. Tout l'art est donc
 * de ne l'allumer que quand le soleil couvre presque toute sa résistance, et de
 * ne pas l'éteindre au premier nuage.
 */
class routeursolairebeEngine {

    const MODE_AUTO = 'auto';
    const MODE_ON = 'on';
    const MODE_OFF = 'off';
    const MODES = array(self::MODE_AUTO, self::MODE_ON, self::MODE_OFF);

    /*
     * Les réglages et leurs valeurs de départ. Ce sont des valeurs courantes
     * pour un boiler de 200 L sur 3 kWc ; le mode simulation est là pour les
     * ajuster sur les vraies journées de la maison.
     */
    const DEFAULTS = array(
        /* Le boiler. */
        'boiler_power'    => 2400,   // W, la plaque de la résistance
        /* L'allumage sur surplus. */
        'on_margin'       => 200,    // W de prélèvement accepté à l'allumage
        'on_delay'        => 120,    // s de surplus suffisant avant d'allumer
        /* L'extinction : le seau de prélèvement. */
        'import_budget'   => 100,    // Wh prélevés tolérés avant d'éteindre
        'hard_import'     => 3500,   // W : au-delà, on coupe sans attendre le seau
        'hard_delay'      => 60,     // s de prélèvement fort avant de couper
        /* La protection du relais. */
        'min_on'          => 10,     // min
        'min_off'         => 5,      // min
        'smoothing'       => 60,     // s, constante du lissage du surplus
        /* La garantie d'eau chaude. */
        'daily_target'    => 4.0,    // kWh à chauffer par journée
        'forced_margin'   => 30,     // min de marge avant la fin de la plage
        'legionella_days' => 2,      // jours sans boiler plein avant une chauffe complète (0 = jamais)
        'legionella_max'  => 8,      // h de chauffe complète au plus
        /* Le boiler simulé. */
        'tank_volume'     => 200,    // L
        'tank_setpoint'   => 60,     // °C, le thermostat du boiler
        'cold_water'      => 12,     // °C, l'eau du réseau
        'standby_loss'    => 60,     // W de pertes à chaud
        /* Les prix, pour le gain estimé. */
        'price_import'    => 0.30,   // €/kWh prélevé
        'price_injection' => 0.05,   // €/kWh injecté : ce qu'il rapporte vraiment
        /* La mesure. */
        'grid_max_age'    => 120,    // s : au-delà, la mesure du compteur est périmée
    );

    const BOUNDS = array(
        'boiler_power'    => array(300, 10000),
        'on_margin'       => array(-2000, 3000),
        'on_delay'        => array(0, 1800),
        'import_budget'   => array(0, 2000),
        'hard_import'     => array(100, 20000),
        'hard_delay'      => array(0, 600),
        'min_on'          => array(0, 120),
        'min_off'         => array(0, 120),
        'smoothing'       => array(0, 900),
        'daily_target'    => array(0, 40),
        'forced_margin'   => array(0, 240),
        'legionella_days' => array(0, 60),
        'legionella_max'  => array(1, 24),
        'tank_volume'     => array(30, 1000),
        'tank_setpoint'   => array(40, 85),
        'cold_water'      => array(2, 25),
        'standby_loss'    => array(0, 500),
        'price_import'    => array(0, 2),
        'price_injection' => array(-1, 2),
        'grid_max_age'    => array(15, 3600),
    );

    /* Les réglages textuels : heures et plages. */
    const TEXT_DEFAULTS = array(
        'day_start'      => '07:00',
        'forced_windows' => '22:00-07:00',
        'draws'          => '07:00=2; 20:00=2.5',
    );

    /* Un intervalle plus long que celui-ci entre deux passages n'est pas
     * compté : Jeedom arrêté une heure ne doit pas inscrire une heure de
     * chauffe. */
    const MAX_STEP = 300;

    /* À l'allumage, le compteur doit monter d'au moins cette part de la
     * puissance du boiler ; sinon son thermostat est ouvert : il est plein. */
    const FULL_RATIO = 0.3;
    const PROBE_DELAY = 30;

    /* Le thermostat simulé se referme quand le boiler a perdu cette part de
     * sa charge : quelques degrés, comme un vrai. */
    const SIM_HYSTERESIS = 0.06;

    /* ============================================================ RÉGLAGES */

    /* « 2,5 », « 2.5 », « −5 » : un nombre, ou null. */
    public static function number($_value) {
        if (is_int($_value) || is_float($_value)) {
            return (float) $_value;
        }
        $text = str_replace(array(',', '−', ' ', "\xc2\xa0"), array('.', '-', '', ''), trim((string) $_value));
        if ($text === '' || !is_numeric($text)) {
            return null;
        }
        return (float) $text;
    }

    /* « 7:00 », « 07h00 », « 7 » : des minutes depuis minuit, ou null. */
    public static function minutes($_value) {
        if (!preg_match('/^\s*(\d{1,2})(?:\s*[:hH]\s*(\d{1,2})?)?\s*$/', (string) $_value, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        if ($h > 24 || $i > 59 || ($h == 24 && $i > 0)) {
            return null;
        }
        return ($h * 60 + $i) % 1440;
    }

    public static function formatMinutes($_minutes) {
        return sprintf('%02d:%02d', intdiv($_minutes, 60), $_minutes % 60);
    }

    /* « 22:00-07:00, 11:00-17:00 » : des plages [début, fin] en minutes. */
    public static function parseWindows($_text) {
        $windows = array();
        foreach (preg_split('/[,;]+/', (string) $_text) as $part) {
            if (trim($part) === '') {
                continue;
            }
            $ends = explode('-', $part);
            if (count($ends) != 2) {
                continue;
            }
            $start = self::minutes($ends[0]);
            $end = self::minutes($ends[1]);
            if ($start === null || $end === null || $start == $end) {
                continue;
            }
            $windows[] = array($start, $end);
        }
        return $windows;
    }

    /* « 07:00=2; 20:00=2.5 » : des puisages [minute, kWh]. */
    public static function parseDraws($_text) {
        $draws = array();
        foreach (preg_split('/[;]+/', (string) $_text) as $part) {
            $pair = explode('=', $part);
            if (count($pair) != 2) {
                continue;
            }
            $minute = self::minutes($pair[0]);
            $kwh = self::number($pair[1]);
            if ($minute === null || $kwh === null || $kwh <= 0) {
                continue;
            }
            $draws[] = array($minute, min(50.0, $kwh));
        }
        usort($draws, function ($_a, $_b) { return $_a[0] - $_b[0]; });
        return $draws;
    }

    /*
     * Défauts et bornes : une valeur absente prend son défaut, une valeur
     * hors bornes est ramenée dedans. Les textes sont réécrits proprement,
     * pour que la page montre ce que le moteur a compris.
     */
    public static function cleanSettings($_raw) {
        $clean = array();
        foreach (self::DEFAULTS as $key => $default) {
            $value = isset($_raw[$key]) ? self::number($_raw[$key]) : null;
            if ($value === null) {
                $value = $default;
            }
            $value = max(self::BOUNDS[$key][0], min(self::BOUNDS[$key][1], $value));
            $clean[$key] = is_int($default) ? (int) round($value) : (float) $value;
        }
        /* L'eau ne se chauffe pas en dessous de l'eau du réseau. */
        if ($clean['tank_setpoint'] <= $clean['cold_water'] + 10) {
            $clean['tank_setpoint'] = $clean['cold_water'] + 10;
        }
        $start = self::minutes(isset($_raw['day_start']) ? $_raw['day_start'] : '');
        $clean['day_start'] = self::formatMinutes(($start === null) ? self::minutes(self::TEXT_DEFAULTS['day_start']) : $start);

        $text = isset($_raw['forced_windows']) ? (string) $_raw['forced_windows'] : self::TEXT_DEFAULTS['forced_windows'];
        $clean['forced_windows'] = implode(', ', array_map(function ($_w) {
            return self::formatMinutes($_w[0]) . '-' . self::formatMinutes($_w[1]);
        }, self::parseWindows($text)));

        $text = isset($_raw['draws']) ? (string) $_raw['draws'] : self::TEXT_DEFAULTS['draws'];
        $clean['draws'] = implode('; ', array_map(function ($_d) {
            return self::formatMinutes($_d[0]) . '=' . rtrim(rtrim(number_format($_d[1], 2, '.', ''), '0'), '.');
        }, self::parseDraws($text)));

        $clean['mode'] = (isset($_raw['mode']) && in_array($_raw['mode'], self::MODES, true)) ? $_raw['mode'] : self::MODE_AUTO;
        return $clean;
    }

    /* La charge d'un boiler plein, en kWh : 1,163 Wh par litre et par degré. */
    public static function tankCapacity($_settings) {
        return $_settings['tank_volume'] * 1.163 * ($_settings['tank_setpoint'] - $_settings['cold_water']) / 1000;
    }

    /* =============================================================== TEMPS */

    /* Un horodatage à une minute donnée du jour de $_ts. */
    public static function at($_ts, $_minute) {
        return strtotime(date('Y-m-d', $_ts) . ' ' . self::formatMinutes($_minute) . ':00');
    }

    /*
     * La journée d'eau chaude : elle commence à « day_start » (7 h), pas à
     * minuit. La chauffe de nuit appartient ainsi à la journée qu'elle
     * prépare, celle de la veille au soir : elle rattrape ce que le soleil
     * n'a pas fait.
     */
    public static function periodKey($_ts, $_dayStart) {
        $minute = self::minutes($_dayStart);
        $start = self::at($_ts, $minute);
        return date('Y-m-d', ($_ts >= $start) ? $_ts : strtotime('-1 day', $_ts));
    }

    /* La fin de la plage de chauffe forcée en cours, ou null hors plage. */
    public static function windowEnd($_ts, $_windows) {
        $minute = (int) date('G', $_ts) * 60 + (int) date('i', $_ts);
        foreach ($_windows as $w) {
            list($start, $end) = $w;
            if ($start < $end) {
                if ($minute >= $start && $minute < $end) {
                    return self::at($_ts, $end);
                }
            } elseif ($minute >= $start) {
                return self::at(strtotime('+1 day', $_ts), $end);
            } elseif ($minute < $end) {
                return self::at($_ts, $end);
            }
        }
        return null;
    }

    /* ================================================================ ÉTAT */

    public static function freshStats() {
        return array(
            'heated'   => 0.0,  // kWh entrés dans le boiler
            'solar'    => 0.0,  // dont venus du surplus (prélèvement évité)
            'grid'     => 0.0,  // dont prélevés
            'forced'   => 0.0,  // dont en chauffe forcée
            'on_s'     => 0,    // secondes de relais fermé
            'cycles'   => 0,    // allumages
            'savings'  => 0.0,  // € gagnés sur le surplus
            'cost'     => 0.0,  // € de prélèvement pour le boiler
            'exported' => 0.0,  // kWh encore injectés malgré le routeur
            'full'     => 0,    // le boiler a été plein dans la journée
        );
    }

    /* L'état laissé par le passage précédent, complété de ce qui manque. */
    public static function cleanState($_state, $_settings, $_now) {
        $state = is_array($_state) ? $_state : array();
        $defaults = array(
            'relay'        => 0,
            'on_at'        => 0,
            'off_at'       => 0,
            'last_at'      => null,
            'avail'        => null,
            'above_since'  => null,
            'hard_since'   => null,
            'bucket'       => 0.0,
            'full'         => 0,
            'full_at'      => $_now,  // inconnu au départ : on part du principe qu'il est chaud
            'probe'        => null,
            'forced'       => '',
            'legio_since'  => 0,
            'forced_left'  => 0.0,    // ce qui restait à la dernière minute de chauffe forcée
            'period'       => null,
            'stats'        => self::freshStats(),
            'yesterday'    => null,
            'boiler_w'     => 0.0,
            'grid_w'       => null,
            'sim_energy'   => self::tankCapacity($_settings) * 0.6,
            'sim_closed'   => 1,
        );
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $state)) {
                $state[$key] = $value;
            }
        }
        $state['stats'] = array_merge(self::freshStats(), is_array($state['stats']) ? $state['stats'] : array());
        return $state;
    }

    /* ============================================================ DÉCISION */

    /*
     * Un passage du routeur.
     *
     * $_inputs :
     *   now        horodatage
     *   grid       puissance au compteur en W (+ prélevée, − injectée), ou null si muette
     *   boiler     puissance mesurée du boiler en W, ou null sans mesure
     *   simulation true : le boiler est simulé et son relais est virtuel
     *
     * Rend array(target, state, reason, code, status, values, events).
     */
    public static function decide($_settings, $_inputs, $_state) {
        $s = $_settings;
        $now = (int) $_inputs['now'];
        $sim = !empty($_inputs['simulation']);
        $gridReal = isset($_inputs['grid']) ? $_inputs['grid'] : null;
        $measured = isset($_inputs['boiler']) ? $_inputs['boiler'] : null;
        $st = self::cleanState($_state, $s, $now);
        $power = (float) $s['boiler_power'];
        $capacity = self::tankCapacity($s);
        $events = array();

        $dt = ($st['last_at'] === null) ? 0 : max(0, min(self::MAX_STEP, $now - (int) $st['last_at']));

        /* 1. L'intervalle écoulé, compté avec ce qui tournait pendant : la
         *    puissance du boiler et le compteur laissés au passage précédent. */
        if ($dt > 0) {
            self::account($st, $s, $dt);
        }

        /* 2. Une nouvelle journée d'eau chaude : on range celle d'hier. */
        $period = self::periodKey($now, $s['day_start']);
        if ($st['period'] !== $period) {
            if ($st['period'] !== null) {
                $st['yesterday'] = array_merge(array('period' => $st['period']), $st['stats']);
                $events[] = 'rollover';
            }
            $st['period'] = $period;
            $st['stats'] = self::freshStats();
        }

        /* 3. Le boiler simulé vit son intervalle : chauffe, pertes, douches. */
        if ($sim && $dt > 0) {
            $wasClosed = $st['sim_closed'];
            self::simulate($st, $s, $dt, $now, $capacity, $events);
            if ($wasClosed && !$st['sim_closed']) {
                $st['full_at'] = $now;
                $st['stats']['full'] = 1;
                $events[] = 'full';
            }
        }

        /* 4. La puissance du boiler en ce moment, avec le relais tel qu'il est. */
        if ($sim) {
            $boilerNow = ($st['relay'] && $st['sim_closed']) ? $power : 0.0;
        } elseif ($measured !== null) {
            $boilerNow = max(0.0, (float) $measured);
        } else {
            $boilerNow = ($st['relay'] && !$st['full']) ? $power : 0.0;
        }
        /* Le compteur tel qu'il serait avec le boiler simulé branché. */
        $grid = ($gridReal === null) ? null : ($sim ? $gridReal + $boilerNow : (float) $gridReal);

        /*
         * 5. Le surplus disponible pour le boiler : ce qu'on injecte, plus ce
         *    que le boiler consomme déjà. Sans ce second terme, le boiler
         *    allumé ferait disparaître le surplus qui l'a allumé, et se
         *    couperait aussitôt. Lissé, pour qu'une bouilloire ou un nuage de
         *    vingt secondes ne décide de rien.
         */
        $avail = null;
        if ($grid !== null) {
            $raw = -$grid + $boilerNow;
            if ($st['avail'] === null || $s['smoothing'] <= 0) {
                $avail = $raw;
            } else {
                $alpha = ($dt > 0) ? 1 - exp(-$dt / $s['smoothing']) : 0;
                $avail = $st['avail'] + $alpha * ($raw - $st['avail']);
            }
            $st['avail'] = $avail;
        }

        /* 6. Le boiler plein, sans sonde. */
        self::detectFull($st, $s, $now, $sim, $grid, $measured, $events);
        $fullNow = $sim ? !$st['sim_closed'] : (bool) $st['full'];
        if ($fullNow && !$st['stats']['full']) {
            $st['stats']['full'] = 1;
        }

        /* 7. Décider. */
        $previous = (int) $st['relay'];
        list($target, $code, $detail) = self::choose($st, $s, $now, $grid, $avail, $fullNow, $dt);

        /* 8. Protéger le relais : durées minimales de marche et d'arrêt. */
        if ($target != $previous) {
            if ($previous == 1 && !in_array($code, array('mode_off', 'hard_import', 'forced_done', 'forced_cut', 'legionella_cut'), true)
                && $now - (int) $st['on_at'] < $s['min_on'] * 60) {
                $detail = array('left' => $s['min_on'] * 60 - ($now - (int) $st['on_at']), 'wanted' => $code);
                $target = 1;
                $code = 'min_on';
            } elseif ($previous == 0 && $code != 'mode_on' && $st['off_at'] > 0
                      && $now - (int) $st['off_at'] < $s['min_off'] * 60) {
                $detail = array('left' => $s['min_off'] * 60 - ($now - (int) $st['off_at']), 'wanted' => $code);
                $target = 0;
                $code = 'min_off';
            }
        }

        /* 9. La transition. */
        if ($target != $previous) {
            if ($target == 1) {
                $st['on_at'] = $now;
                $st['bucket'] = 0.0;
                $st['hard_since'] = null;
                $st['stats']['cycles']++;
                $st['probe'] = ($grid === null) ? null : array('at' => $now, 'before' => $grid);
                if (!$sim && $measured === null) {
                    /* Allumé, il chauffe jusqu'à preuve du contraire. */
                    $st['full'] = 0;
                }
                $events[] = 'on';
            } else {
                $st['off_at'] = $now;
                $st['above_since'] = null;
                $st['probe'] = null;
                $events[] = 'off';
            }
        }
        $st['relay'] = $target;

        /* 10. Ce que le boiler consommera jusqu'au prochain passage, et le
         *     compteur qui va avec : c'est sur eux que se comptera l'intervalle. */
        if ($sim) {
            $boilerAfter = ($target && $st['sim_closed']) ? $power : 0.0;
        } elseif ($measured !== null) {
            $boilerAfter = ($target) ? max(0.0, (float) $measured) : 0.0;
        } else {
            $boilerAfter = ($target && !$st['full']) ? $power : 0.0;
        }
        $gridAfter = ($gridReal === null) ? null : ($sim ? $gridReal + $boilerAfter : $grid);
        $st['boiler_w'] = $boilerAfter;
        $st['grid_w'] = $gridAfter;
        $st['last_at'] = $now;

        $deficit = self::deficit($st, $s);
        $values = array(
            'grid'        => $gridAfter,
            'grid_real'   => $gridReal,
            'avail'       => $avail,
            'boiler'      => $boilerAfter,
            'bucket'      => $st['bucket'],
            'full'        => $fullNow ? 1 : 0,
            'tank'        => $sim ? round(100 * $st['sim_energy'] / $capacity, 1) : null,
            'tank_kwh'    => $sim ? $st['sim_energy'] : null,
            'capacity'    => $capacity,
            'deficit'     => $deficit,
            'days_full'   => round(($now - (int) $st['full_at']) / 86400, 1),
            'threshold'   => $power - $s['on_margin'],
            'stats'       => $st['stats'],
            'yesterday'   => $st['yesterday'],
        );
        return array(
            'target' => $target,
            'state'  => $st,
            'code'   => $code,
            'reason' => self::reasonText($code, $detail, $values, $s),
            'status' => self::status($code, $target),
            'values' => $values,
            'events' => $events,
        );
    }

    /*
     * La décision brute, avant la protection du relais : le mode imposé,
     * puis la chauffe forcée, puis le soleil.
     */
    private static function choose(&$st, $s, $now, $grid, $avail, $fullNow, $dt) {
        $power = (float) $s['boiler_power'];
        if ($s['mode'] == self::MODE_OFF) {
            $st['forced'] = '';
            return array(0, 'mode_off', array());
        }
        if ($s['mode'] == self::MODE_ON) {
            $st['forced'] = '';
            return array(1, 'mode_on', array());
        }

        /* La chauffe forcée : rattraper ce que le soleil n'a pas fait. */
        $wasForced = ($st['forced'] !== '');
        $wasLegionella = ($st['forced'] === 'legionella');
        $forced = self::forced($st, $s, $now, $fullNow);
        if ($forced !== null) {
            return $forced;
        }
        /* La chauffe forcée vient de finir : on éteint, sauf si le soleil est
         * déjà là pour prendre le relais. Sans cela, le seau se remplirait
         * d'un prélèvement qui n'a rien à voir avec un nuage. */
        $threshold = $power - $s['on_margin'];
        if ($wasForced && $st['relay'] == 1 && ($avail === null || $avail < $threshold)) {
            /* Fini, ou coupé par la fin de la plage avant d'avoir fini : ce
             * n'est pas la même nouvelle, le journal doit le dire. */
            if ($wasLegionella && !$fullNow) {
                return array(0, 'legionella_cut', array());
            }
            /* À la fin de plage, la journée a souvent déjà basculé : ce qui
             * manquait se lit dans la dernière minute de chauffe. */
            $windowOpen = self::windowEnd($now, self::parseWindows($s['forced_windows'])) !== null;
            if (!$windowOpen && !$fullNow && $st['forced_left'] > 0.05) {
                return array(0, 'forced_cut', array('deficit' => $st['forced_left']));
            }
            return array(0, 'forced_done', array());
        }

        if ($grid === null) {
            $st['above_since'] = null;
            return array(0, 'no_measure', array());
        }

        if ($st['relay'] == 0) {
            if ($avail >= $threshold) {
                if ($st['above_since'] === null) {
                    $st['above_since'] = $now;
                }
                $since = $now - (int) $st['above_since'];
                if ($since >= $s['on_delay']) {
                    return array(1, 'solar_start', array('since' => $since));
                }
                return array(0, 'waiting_surplus', array('since' => $since, 'left' => $s['on_delay'] - $since));
            }
            $st['above_since'] = null;
            return array(0, 'no_surplus', array());
        }

        /*
         * Allumé : le seau. Chaque wattheure prélevé y tombe, chaque
         * wattheure injecté en ressort. Un nuage court le remplit à peine et
         * le soleil revenu le vide ; un ciel qui se couvre pour de bon le fait
         * déborder. C'est la manière des routeurs MK2, appliquée à un relais.
         */
        $st['bucket'] = max(0.0, $st['bucket'] + $grid * $dt / 3600);
        if ($grid > $s['hard_import']) {
            if ($st['hard_since'] === null) {
                $st['hard_since'] = $now;
            }
            if ($now - (int) $st['hard_since'] >= $s['hard_delay']) {
                return array(0, 'hard_import', array('grid' => $grid));
            }
        } else {
            $st['hard_since'] = null;
        }
        if ($st['bucket'] > $s['import_budget']) {
            return array(0, 'budget', array('bucket' => $st['bucket']));
        }
        return array(1, $fullNow ? 'solar_full' : 'solar_running', array('bucket' => $st['bucket']));
    }

    /* Ce qui reste à chauffer dans la journée, en kWh : rien si le boiler a
     * été plein, puisque le thermostat a alors dit lui-même que c'était fait. */
    public static function deficit($_st, $_s) {
        if (!empty($_st['stats']['full'])) {
            return 0.0;
        }
        return max(0.0, $_s['daily_target'] - $_st['stats']['heated']);
    }

    /*
     * La chauffe forcée, ou null.
     *
     * Elle ne tourne que dans une plage de chauffe forcée — les heures creuses
     * de nuit — et elle s'y place à la fin, juste ce qu'il faut avant la fin
     * de plage : l'eau est chaude au réveil, sans avoir refroidi toute la nuit.
     *
     * Quand le boiler n'aura pas été plein depuis « legionella_days » jours
     * à la fin de la plage, elle chauffe jusqu'au bout : un boiler qui
     * n'atteint jamais sa consigne finit par abriter des légionelles, et un
     * objectif du jour trop court finit par le vider. Cette chauffe complète
     * se place, elle aussi, en fin de plage, avec le temps d'un boiler entier.
     */
    private static function forced(&$st, $s, $now, $fullNow) {
        $end = self::windowEnd($now, self::parseWindows($s['forced_windows']));
        if ($end === null) {
            $st['forced'] = '';
            $st['legio_since'] = 0;
            return null;
        }
        $days = ($now - (int) $st['full_at']) / 86400;
        $due = $s['legionella_days'] > 0 && ($end - (int) $st['full_at']) >= $s['legionella_days'] * 86400;
        if ($due && !$fullNow) {
            $hours = min($s['legionella_max'], self::tankCapacity($s) / ($s['boiler_power'] / 1000));
            $start = (int) ($end - $hours * 3600 - $s['forced_margin'] * 60);
            if (!$st['legio_since'] && $now < $start) {
                $st['forced'] = '';
                return array(0, 'legionella_later', array('days' => $days, 'start' => $start));
            }
            if (!$st['legio_since']) {
                $st['legio_since'] = $now;
            }
            if ($now - (int) $st['legio_since'] < $s['legionella_max'] * 3600) {
                $st['forced'] = 'legionella';
                return array(1, 'legionella', array('days' => $days, 'end' => $end));
            }
            /* Le temps maximum est passé sans boiler plein : on n'insiste pas
             * cette nuit, la chauffe ordinaire reprend ses droits. */
        }
        $deficit = self::deficit($st, $s);
        if ($deficit <= 0.05 || $fullNow) {
            $st['forced'] = '';
            return null;
        }
        $needed = $deficit / ($s['boiler_power'] / 1000) * 3600;
        $start = (int) ($end - $needed - $s['forced_margin'] * 60);
        /* Une chauffe commencée va jusqu'au bout, même si un recalcul la
         * placerait un peu plus tard. */
        if ($st['forced'] === 'deficit' || $now >= $start) {
            $st['forced'] = 'deficit';
            $st['forced_left'] = $deficit;
            return array(1, 'forced', array('deficit' => $deficit, 'end' => $end));
        }
        $st['forced'] = '';
        return array(0, 'forced_later', array('deficit' => $deficit, 'start' => $start));
    }

    /*
     * Le boiler plein, sans sonde.
     *
     * Simulé, son thermostat le dit lui-même. Réel, avec une mesure de sa
     * puissance (Shelly), c'est direct. Sans mesure, on regarde le compteur
     * à l'allumage : s'il ne monte pas d'au moins un tiers de la résistance
     * dans les trente secondes, c'est que le thermostat du boiler est ouvert.
     */
    private static function detectFull(&$st, $s, $now, $sim, $grid, $measured, &$events) {
        if ($sim || !$st['relay']) {
            return;
        }
        $power = (float) $s['boiler_power'];
        if ($now - (int) $st['on_at'] < self::PROBE_DELAY) {
            return;
        }
        $was = (int) $st['full'];
        if ($measured !== null) {
            $st['full'] = ($measured < self::FULL_RATIO * $power) ? 1 : 0;
        } elseif (is_array($st['probe']) && $grid !== null) {
            $rise = $grid - $st['probe']['before'];
            $st['full'] = ($rise < self::FULL_RATIO * $power) ? 1 : 0;
            $st['probe'] = null;
        } else {
            return;
        }
        if ($st['full'] && !$was) {
            $st['full_at'] = $now;
            $st['stats']['full'] = 1;
            $events[] = 'full';
        }
    }

    /*
     * Le boiler simulé : une réserve d'énergie entre 0 et sa capacité. Il
     * chauffe à pleine puissance tant que son relais virtuel est fermé et que
     * son thermostat le permet, perd un peu de chaleur, et se vide aux heures
     * des douches.
     */
    private static function simulate(&$st, $s, $dt, $now, $capacity, &$events) {
        $energy = $st['sim_energy'];
        if ($st['relay'] && $st['sim_closed']) {
            $energy += $s['boiler_power'] * $dt / 3600000;
        }
        $energy -= $s['standby_loss'] * $dt / 3600000 * ($energy / $capacity);
        $last = $now - $dt;
        foreach (self::parseDraws($s['draws']) as $draw) {
            foreach (array($last, $now) as $day) {
                $ts = self::at($day, $draw[0]);
                if ($ts > $last && $ts <= $now) {
                    $energy -= $draw[1];
                    $events[] = 'draw';
                    break;
                }
            }
        }
        $energy = max(0.0, $energy);
        if ($energy >= $capacity) {
            $energy = $capacity;
            $st['sim_closed'] = 0;
        } elseif ($energy <= $capacity * (1 - self::SIM_HYSTERESIS)) {
            $st['sim_closed'] = 1;
        }
        $st['sim_energy'] = $energy;
    }

    /*
     * Compte l'intervalle écoulé. La puissance du boiler pendant
     * l'intervalle est celle laissée par le passage précédent ; la part
     * solaire est ce que la maison aurait injecté sans lui.
     */
    private static function account(&$st, $s, $dt) {
        $boiler = (float) $st['boiler_w'];
        $grid = $st['grid_w'];
        $stats = &$st['stats'];
        if ($st['relay']) {
            $stats['on_s'] += $dt;
        }
        if ($grid === null) {
            return;
        }
        $hours = $dt / 3600;
        if ($boiler > 0) {
            $solarW = min($boiler, max(0.0, $boiler - $grid));
            $gridW = $boiler - $solarW;
            $stats['heated'] += $boiler * $hours / 1000;
            $stats['solar'] += $solarW * $hours / 1000;
            $stats['grid'] += $gridW * $hours / 1000;
            if ($st['forced'] !== '') {
                $stats['forced'] += $boiler * $hours / 1000;
            }
            $stats['savings'] += $solarW * $hours / 1000 * ($s['price_import'] - $s['price_injection']);
            $stats['cost'] += $gridW * $hours / 1000 * $s['price_import'];
        }
        if ($grid < 0) {
            $stats['exported'] += -$grid * $hours / 1000;
        }
    }

    /* ============================================================= LIBELLÉS */

    const STATUS = array(
        'mode_off' => 'off', 'mode_on' => 'forced', 'forced' => 'forced', 'legionella' => 'forced',
        'forced_later' => 'idle', 'forced_done' => 'idle', 'forced_cut' => 'idle',
        'legionella_later' => 'idle', 'legionella_cut' => 'idle', 'no_measure' => 'alert', 'solar_start' => 'solar',
        'waiting_surplus' => 'waiting', 'no_surplus' => 'idle', 'hard_import' => 'idle',
        'budget' => 'idle', 'solar_running' => 'solar', 'solar_full' => 'full',
        'min_on' => 'waiting', 'min_off' => 'waiting',
    );

    public static function status($_code, $_target) {
        if ($_code == 'min_on') {
            return 'solar';
        }
        return isset(self::STATUS[$_code]) ? self::STATUS[$_code] : ($_target ? 'solar' : 'idle');
    }

    public static function stateLabel($_status) {
        $labels = array(
            'off'     => 'Arrêt imposé',
            'forced'  => 'Chauffe forcée',
            'solar'   => 'Chauffe solaire',
            'full'    => 'Boiler plein',
            'waiting' => 'En attente',
            'idle'    => 'Au repos',
            'alert'   => 'Compteur muet',
        );
        return isset($labels[$_status]) ? $labels[$_status] : $_status;
    }

    public static function modeLabel($_mode) {
        $labels = array(self::MODE_AUTO => 'Automatique', self::MODE_ON => 'Marche forcée', self::MODE_OFF => 'Arrêt');
        return isset($labels[$_mode]) ? $labels[$_mode] : $_mode;
    }

    public static function watts($_w) {
        return ($_w === null) ? '—' : number_format(round($_w), 0, ',', ' ') . ' W';
    }

    public static function kwh($_kwh) {
        return number_format((float) $_kwh, 2, ',', ' ') . ' kWh';
    }

    public static function duration($_seconds) {
        $seconds = max(0, (int) round($_seconds));
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min' . (($seconds % 60) ? ' ' . sprintf('%02d', $seconds % 60) : '');
        }
        return intdiv($seconds, 3600) . ' h ' . sprintf('%02d', intdiv($seconds % 3600, 60));
    }

    /* La raison, en une phrase qu'on comprend sans ouvrir le code. */
    public static function reasonText($_code, $_detail, $_values, $_s) {
        $avail = self::watts($_values['avail']);
        $threshold = self::watts($_values['threshold']);
        switch ($_code) {
            case 'mode_off':
                return 'Arrêt demandé';
            case 'mode_on':
                return 'Marche forcée demandée';
            case 'no_measure':
                return 'Compteur muet ou mesure trop ancienne : pas de routage sans mesure';
            case 'solar_start':
                return sprintf('Surplus %s ≥ %s depuis %s : allumage', $avail, $threshold, self::duration($_detail['since']));
            case 'waiting_surplus':
                return sprintf('Surplus %s ≥ %s, encore %s avant d\'allumer', $avail, $threshold, self::duration($_detail['left']));
            case 'no_surplus':
                return sprintf('Surplus %s, il en faut %s', $avail, $threshold);
            case 'solar_running':
                return sprintf('Chauffe sur surplus (%s disponibles, prélevé toléré %d/%d Wh)', $avail,
                               round($_detail['bucket']), $_s['import_budget']);
            case 'solar_full':
                return sprintf('Boiler plein, relais laissé fermé tant qu\'il y a du surplus (%s)', $avail);
            case 'budget':
                return sprintf('Plus assez de soleil : %d Wh prélevés, au-delà des %d tolérés', round($_detail['bucket']),
                               $_s['import_budget']);
            case 'hard_import':
                return sprintf('Prélèvement fort (%s) : coupure immédiate', self::watts($_detail['grid']));
            case 'forced':
                return sprintf('Chauffe forcée : il manque %s pour la journée, à faire avant %s',
                               self::kwh($_detail['deficit']), date('H:i', $_detail['end']));
            case 'forced_done':
                return 'Chauffe forcée terminée : la journée a son eau chaude';
            case 'forced_later':
                return sprintf('Il manque %s : chauffe forcée prévue à %s', self::kwh($_detail['deficit']),
                               date('H:i', $_detail['start']));
            case 'forced_cut':
                return sprintf('Fin de la plage de chauffe forcée : il manquait encore %s', self::kwh($_detail['deficit']));
            case 'legionella':
                return sprintf('Chauffe complète : pas de boiler plein depuis %s jours', number_format($_detail['days'], 1, ',', ''));
            case 'legionella_later':
                return sprintf('Pas de boiler plein depuis %s jours : chauffe complète prévue à %s',
                               number_format($_detail['days'], 1, ',', ''), date('H:i', $_detail['start']));
            case 'legionella_cut':
                return 'Chauffe complète arrêtée avant le boiler plein (fin de plage ou durée maximale) : elle reprendra la nuit prochaine';
            case 'min_on':
                return sprintf('Durée minimale de marche : encore %s', self::duration($_detail['left']));
            case 'min_off':
                return sprintf('Durée minimale d\'arrêt : encore %s', self::duration($_detail['left']));
        }
        return $_code;
    }
}
