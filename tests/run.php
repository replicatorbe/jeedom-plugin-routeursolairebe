<?php
/* Jeu d'essai hors ligne du Routeur Solaire.
 *
 *   php tests/run.php
 *
 * Aucune dépendance : ni Jeedom, ni base, ni boiler. Le moteur ignore Jeedom
 * exprès, et c'est ce qui rend ce fichier possible.
 *
 * Un routeur se trompe en silence : un relais qui claque toutes les deux
 * minutes sous les nuages, un boiler allumé sur le réseau à 18 h, une eau
 * froide un matin de novembre. Rien de tout cela ne lève d'erreur. Chaque
 * scénario rejoue une journée, pas de dix secondes en dix secondes, comme le
 * compteur P1.
 */

date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/../core/class/routeursolairebeEngine.class.php';

$ok = 0;
$ko = 0;

function verifie($_titre, $_obtenu, $_attendu) {
    global $ok, $ko;
    if ($_obtenu === $_attendu || (is_float($_obtenu) && is_numeric($_attendu) && abs($_obtenu - $_attendu) < 1e-6)) {
        $ok++;
        printf("  %-70s ok\n", $_titre);
        return;
    }
    $ko++;
    printf("  %-70s ÉCHEC : obtenu %s, attendu %s\n", $_titre, var_export($_obtenu, true), var_export($_attendu, true));
}

function verifieVrai($_titre, $_condition, $_detail = '') {
    global $ok, $ko;
    if ($_condition) {
        $ok++;
        printf("  %-70s ok\n", $_titre);
        return;
    }
    $ko++;
    printf("  %-70s ÉCHEC %s\n", $_titre, $_detail);
}

/*
 * Une maison : sa consommation et sa production, minute par minute, et le
 * routeur qui la regarde. Garde l'état d'un passage à l'autre comme le fait le
 * plugin avec son cache.
 */
class Maison {
    public $reglages;
    public $etat = array();
    public $now;
    public $dernier;
    public $simulation = true;
    public $allumages = array();
    public $journal = array();
    public $raisons = array();

    public function __construct($_reglages = array(), $_debut = '2026-06-15 07:00:00') {
        $this->reglages = routeursolairebeEngine::cleanSettings($_reglages);
        $this->now = strtotime($_debut);
    }

    public function passe($_reseau, $_boiler = null) {
        $this->dernier = routeursolairebeEngine::decide($this->reglages, array(
            'now'        => $this->now,
            'grid'       => $_reseau,
            'boiler'     => $_boiler,
            'simulation' => $this->simulation,
        ), $this->etat);
        $this->etat = $this->dernier['state'];
        if (in_array('on', $this->dernier['events'], true)) {
            $this->allumages[] = date('H:i', $this->now);
        }
        return $this->dernier['target'];
    }

    /* Rejoue une plage de temps : $_profil(horodatage) rend le compteur sans
     * boiler (le boiler simulé s'y ajoute tout seul). */
    public function rejoue($_jusqua, $_profil, $_pas = 10) {
        $fin = strtotime($_jusqua, $this->now);
        while ($this->now < $fin) {
            $this->passe($_profil($this->now));
            $this->journal[$this->dernier['code']] = true;
            $this->raisons[$this->dernier['code']] = $this->dernier['reason'];
            $this->now += $_pas;
        }
    }

    public function stats() {
        return $this->dernier['values']['stats'];
    }
}

/* L'heure décimale d'un horodatage. */
function heure($_ts) {
    return (int) date('G', $_ts) + (int) date('i', $_ts) / 60 + (int) date('s', $_ts) / 3600;
}

/* Une production en cloche, nulle hors 7 h – 20 h, pic à 13 h 30. */
function solaire($_ts, $_pic) {
    $h = heure($_ts);
    if ($h < 7 || $h > 20) {
        return 0.0;
    }
    return $_pic * pow(sin(M_PI * ($h - 7) / 13), 2);
}

/* ------------------------------------------------------------------ 1 ---
 * Les réglages tapés à la main. */
echo "\nRéglages\n";
verifie('2,5 à la virgule', routeursolairebeEngine::number('2,5'), 2.5);
verifie('−200 au signe moins typographique', routeursolairebeEngine::number('−200'), -200.0);
verifie('champ vide = null', routeursolairebeEngine::number(''), null);
verifie('7h30 en minutes', routeursolairebeEngine::minutes('7h30'), 450);
verifie('heure invalide = null', routeursolairebeEngine::minutes('25:00'), null);
$r = routeursolairebeEngine::cleanSettings(array('boiler_power' => '99999', 'forced_windows' => '22h-7h,11:00-17:00, n\'importe quoi',
                                                 'draws' => '7=2;20:00=2,5;x=3'));
verifie('puissance bornée', $r['boiler_power'], 10000);
verifie('plages relues et réécrites', $r['forced_windows'], '22:00-07:00, 11:00-17:00');
verifie('puisages relus et réécrits', $r['draws'], '07:00=2; 20:00=2.5');
verifie('mode inconnu = automatique', routeursolairebeEngine::cleanSettings(array('mode' => 'turbo'))['mode'], 'auto');
$r = routeursolairebeEngine::cleanSettings(array());
verifie('capacité d\'un 200 L de 12 à 60 °C', round(routeursolairebeEngine::tankCapacity($r), 2), 11.16);

echo "\nPlages et journées\n";
$w = routeursolairebeEngine::parseWindows('22:00-07:00');
verifie('23 h est dans la plage de nuit', date('Y-m-d H:i', routeursolairebeEngine::windowEnd(strtotime('2026-06-15 23:00'), $w)), '2026-06-16 07:00');
verifie('3 h aussi, fin le matin même', date('Y-m-d H:i', routeursolairebeEngine::windowEnd(strtotime('2026-06-16 03:00'), $w)), '2026-06-16 07:00');
verifie('midi n\'y est pas', routeursolairebeEngine::windowEnd(strtotime('2026-06-16 12:00'), $w), null);
verifie('la nuit appartient à la veille', routeursolairebeEngine::periodKey(strtotime('2026-06-16 03:00'), '07:00'), '2026-06-15');
verifie('la journée commence à 7 h', routeursolairebeEngine::periodKey(strtotime('2026-06-16 07:00'), '07:00'), '2026-06-16');

/* ------------------------------------------------------------------ 2 ---
 * Une belle journée de juin : 300 W de fond, 2,8 kW de pic. */
echo "\nBelle journée\n";
$m = new Maison();
$m->rejoue('+1442 minutes', function ($_ts) { return 300 - solaire($_ts, 2800); });
$y = $m->dernier['values']['yesterday'];
verifieVrai('la journée d\'hier est rangée au changement de journée', is_array($y) && $y['period'] == '2026-06-15');
verifieVrai('le boiler s\'allume au soleil, pas avant 9 h', count($m->allumages) > 0 && $m->allumages[0] >= '09:00', implode(',', $m->allumages));
verifieVrai('au plus 6 allumages', $y['cycles'] <= 6, 'cycles=' . $y['cycles']);
verifieVrai('le boiler a été plein', $y['full'] == 1);
verifieVrai('plus de 3 kWh solaires routés', $y['solar'] > 3, 'solaire=' . round($y['solar'], 2));
verifieVrai('presque tout vient du soleil (moins de 10 % du réseau)', $y['grid'] < 0.1 * $y['heated'], 'réseau=' . round($y['grid'], 2));
verifie('aucune chauffe forcée', round($y['forced'], 3), 0.0);
verifieVrai('un gain estimé', $y['savings'] > 0.5, 'gain=' . round($y['savings'], 2));

/* ------------------------------------------------------------------ 3 ---
 * Novembre couvert : 400 W de pic, jamais assez pour 2,4 kW. */
echo "\nJournée couverte\n";
$m = new Maison(array(), '2026-11-06 07:00:00');
$m->rejoue('+1442 minutes', function ($_ts) { return 350 - solaire($_ts, 400); });
$y = $m->dernier['values']['yesterday'];
verifie('aucun allumage au soleil', isset($m->journal['solar_start']), false);
verifieVrai('chauffe forcée la nuit', $y['forced'] > 3.9, 'forcée=' . round($y['forced'], 2));
verifieVrai('placée en fin de nuit, vers 4 h 50', count($m->allumages) == 1 && $m->allumages[0] >= '04:30' && $m->allumages[0] <= '05:00',
            implode(',', $m->allumages));
verifieVrai('l\'objectif est tenu', $y['heated'] >= 3.95, 'chauffé=' . round($y['heated'], 2));
verifieVrai('le gain est nul, la chauffe a été payée', $y['savings'] < 0.01 && $y['cost'] > 1);
verifieVrai('éteint dès l\'objectif atteint, sans attendre le seau', isset($m->journal['forced_done']) && !isset($m->journal['budget']));
verifieVrai('pas un kWh de trop', $y['heated'] < 4.1, 'chauffé=' . round($y['heated'], 2));

/* ------------------------------------------------------------------ 4 ---
 * Nuages passagers : le soleil disparaît deux minutes tous les quarts
 * d'heure. Le seau absorbe, le relais ne claque pas. */
echo "\nNuages passagers\n";
$m = new Maison();
$m->etat = routeursolairebeEngine::cleanState(array(), $m->reglages, $m->now);
$m->etat['sim_energy'] = 2.0; // boiler presque froid : il chauffera toute la journée
$m->rejoue('+11 hours', function ($_ts) {
    $nuage = ((int) date('i', $_ts)) % 15 < 2;
    return 300 - ($nuage ? 300 : 3400) * (heure($_ts) > 9 && heure($_ts) < 17 ? 1 : 0.1);
});
$s = $m->stats();
verifieVrai('un seul allumage malgré 30 nuages', $s['cycles'] == 1, 'cycles=' . $s['cycles'] . ' ' . implode(',', $m->allumages));
verifieVrai('plusieurs heures de chauffe', $s['on_s'] > 5 * 3600, routeursolairebeEngine::duration($s['on_s']));

/* ------------------------------------------------------------------ 5 ---
 * Un four de 3 kW allumé en pleine chauffe : coupure sans attendre le seau,
 * même pendant la durée minimale de marche. */
echo "\nPrélèvement fort\n";
$m = new Maison(array(), '2026-06-15 12:00:00');
$m->etat = routeursolairebeEngine::cleanState(array(), $m->reglages, $m->now);
$m->etat['sim_energy'] = 2.0;
$m->rejoue('+5 minutes', function ($_ts) { return -2600; });
verifie('allumé sur 2,6 kW de surplus', $m->dernier['target'], 1);
$m->rejoue('+2 minutes', function ($_ts) { return 1300; });
verifie('le four s\'allume : coupure', $m->dernier['target'], 0);
verifieVrai('pour prélèvement fort', isset($m->journal['hard_import']));

/* ------------------------------------------------------------------ 6 ---
 * Les durées minimales protègent le relais. */
echo "\nDurées minimales\n";
$m = new Maison(array('hard_import' => 20000, 'on_delay' => 0, 'smoothing' => 0), '2026-06-15 12:00:00');
$m->etat = routeursolairebeEngine::cleanState(array(), $m->reglages, $m->now);
$m->etat['sim_energy'] = 2.0;
$m->rejoue('+3 minutes', function ($_ts) { return -2600; });
verifie('allumé', $m->dernier['target'], 1);
$m->rejoue('+4 minutes', function ($_ts) { return 1000; });
verifie('le seau déborde mais la marche minimale tient', $m->dernier['code'], 'min_on');
$m->rejoue('+7 minutes', function ($_ts) { return 1000; });
verifie('après 10 minutes, il coupe', $m->dernier['target'], 0);
$m->rejoue('+30 seconds', function ($_ts) { return -2600; });
verifie('le surplus revient : l\'arrêt minimal tient', $m->dernier['code'], 'min_off');

/* ------------------------------------------------------------------ 7 ---
 * Compteur muet : pas de routage à l'aveugle. */
echo "\nCompteur muet\n";
$m = new Maison(array(), '2026-06-15 12:00:00');
$m->passe(null);
verifie('rien sans mesure', $m->dernier['code'], 'no_measure');
verifie('relais ouvert', $m->dernier['target'], 0);

/* ------------------------------------------------------------------ 8 ---
 * Un vrai boiler sans mesure de puissance : allumé, le compteur ne bouge
 * pas, donc son thermostat est ouvert. */
echo "\nBoiler réel, plein\n";
$m = new Maison(array(), '2026-06-15 12:00:00');
$m->simulation = false;
$m->rejoue('+3 minutes', function ($_ts) { return -2600; });
verifie('allumé', $m->dernier['target'], 1);
$m->rejoue('+1 minute', function ($_ts) { return -2580; });
verifie('le compteur n\'a pas monté : boiler plein', $m->dernier['values']['full'], 1);
verifie('la journée est faite', $m->dernier['values']['deficit'], 0.0);
$m = new Maison(array(), '2026-06-15 12:00:00');
$m->simulation = false;
$m->rejoue('+130 seconds', function ($_ts) { return -2600; });
verifie('allumé, le compteur suit', $m->dernier['target'], 1);
$m->rejoue('+1 minute', function ($_ts) { return -200; });
verifie('le compteur a monté de 2,4 kW : il chauffe', $m->dernier['values']['full'], 0);
$m = new Maison(array(), '2026-06-15 12:00:00');
$m->simulation = false;
$m->rejoue('+3 minutes', function ($_ts) { return -2600; });
$m->passe(-2600, 15);
$m->now += 40;
$m->passe(-2600, 15);
verifie('avec une mesure de puissance : 15 W, il est plein', $m->dernier['values']['full'], 1);

/* ------------------------------------------------------------------ 9 ---
 * Huit jours sans boiler plein : chauffe complète, placée en fin de nuit
 * avec le temps d'un boiler entier (200 L, 2,4 kW : 4 h 39, plus la marge, avant 07:00). */
echo "\nAnti-légionelle\n";
$m = new Maison(array(), '2026-11-06 21:50:00');
$m->etat = routeursolairebeEngine::cleanState(array(), $m->reglages, $m->now);
$m->etat['full_at'] = strtotime('-8 days', $m->now);
$m->etat['sim_energy'] = 3.0;
$m->rejoue('+30 minutes', function ($_ts) { return 300; });
verifie('prévue, pas encore lancée à 22 h 20', $m->dernier['code'], 'legionella_later');
verifieVrai('prévue à 01:50', strpos($m->dernier['reason'], '01:50') !== false);
$m->rejoue('+4 hours', function ($_ts) { return 300; });
verifie('lancée à 02:20', $m->dernier['code'], 'legionella');
$m->rejoue('+4 hours', function ($_ts) { return 300; });
verifie('jusqu\'à ce que le boiler soit plein', $m->dernier['values']['full'], 1);
verifieVrai('le compteur de jours repart', $m->dernier['values']['days_full'] < 1);
verifieVrai('plein avant 07:00, sans être coupé', !isset($m->journal['legionella_cut']));

/* Une plage trop courte coupe la chauffe complète : le journal le dit, et
 * elle reprend la nuit suivante. */
$m = new Maison(array('forced_windows' => '05:00-07:00'), '2026-11-06 21:50:00');
$m->etat = routeursolairebeEngine::cleanState(array(), $m->reglages, $m->now);
$m->etat['full_at'] = strtotime('-8 days', $m->now);
$m->etat['sim_energy'] = 0.0;
$m->rejoue('+10 hours', function ($_ts) { return 300; });
verifieVrai('coupée à 07:00 : le journal ne dit pas « terminée »', isset($m->journal['legionella_cut']) && !isset($m->journal['forced_done']));
$m->rejoue('+22 hours', function ($_ts) { return 300; });
verifie('reprise la nuit suivante', $m->dernier['code'], 'legionella');

/* Une chauffe ordinaire coupée par la fin de plage dit ce qui manquait à la
 * journée qui finit, pas l'objectif de celle qui commence. */
$m = new Maison(array('forced_windows' => '06:30-07:00'), '2026-11-06 21:50:00');
$m->rejoue('+10 hours', function ($_ts) { return 300; });
verifieVrai('coupée : « il manquait encore 2,8x kWh », pas 4', strpos($m->raisons['forced_cut'] ?? '', 'encore 2,8') !== false);

/* ------------------------------------------------------------------ 10 ---
 * Les modes imposés. */
echo "\nModes\n";
$m = new Maison(array('mode' => 'off'), '2026-06-15 12:00:00');
$m->rejoue('+10 minutes', function ($_ts) { return -3000; });
verifie('Arrêt : rien, même au soleil', $m->dernier['target'], 0);
$m = new Maison(array('mode' => 'on'), '2026-06-15 19:00:00');
$m->passe(500);
verifie('Marche forcée : allumé sans soleil', $m->dernier['target'], 1);

/* ------------------------------------------------------------------ 11 ---
 * Le soleil fait déjà la moitié : la nuit ne rattrape que le reste. */
echo "\nRattrapage partiel\n";
$m = new Maison(array('daily_target' => 6), '2026-10-20 07:00:00');
$m->etat = routeursolairebeEngine::cleanState(array(), $m->reglages, $m->now);
$m->etat['sim_energy'] = 1.0;
$m->rejoue('+1442 minutes', function ($_ts) { return 300 - solaire($_ts, 2900) * (heure($_ts) < 13 ? 1 : 0.1); });
$y = $m->dernier['values']['yesterday'];
verifieVrai('du solaire le matin', $y['solar'] > 1, 'solaire=' . round($y['solar'], 2));
verifieVrai('le reste en forcé', $y['forced'] > 0.5 && $y['forced'] < 5, 'forcée=' . round($y['forced'], 2));
verifieVrai('l\'objectif de 6 kWh est tenu', $y['heated'] >= 5.95 || $y['full'] == 1, 'chauffé=' . round($y['heated'], 2));

/* ------------------------------------------------------------------ 12 ---
 * Une vraie journée : le P1 du 6 novembre 2026, couvert, 370 W d'injection
 * au mieux. Le boiler de 2,4 kW n'a rien à y prendre ; un petit de 300 W, si. */
echo "\nJournée réelle du 6 novembre\n";
$mesures = array();
foreach (array_slice(file(__DIR__ . '/data/p1-2026-11-06.csv', FILE_IGNORE_NEW_LINES), 1) as $ligne) {
    list($h, $w) = explode(';', $ligne);
    $mesures[strtotime('2026-11-06 ' . $h)] = (float) $w;
}
$rejoue = function ($_reglages) use ($mesures) {
    $m = new Maison($_reglages, '2026-11-06 07:00:00');
    $courant = reset($mesures);
    $m->rejoue('+1442 minutes', function ($_ts) use ($mesures, &$courant) {
        if (isset($mesures[$_ts])) {
            $courant = $mesures[$_ts];
        }
        return $courant;
    }, 1);
    return $m;
};
$m = $rejoue(array());
$y = $m->dernier['values']['yesterday'];
verifie('2,4 kW : aucun allumage au soleil', isset($m->journal['solar_start']), false);
verifieVrai('l\'injection du jour retrouvée (0,39 kWh au compteur)', abs($y['exported'] - 0.39) < 0.05, 'injecté=' . round($y['exported'], 3));
$m = $rejoue(array('boiler_power' => 300, 'on_margin' => 0));
$y = $m->dernier['values']['yesterday'];
verifieVrai('300 W : du solaire routé', $y['solar'] > 0.1, 'solaire=' . round($y['solar'], 3));

echo "\n" . $ok . ' ok, ' . $ko . " échec(s)\n";
exit($ko > 0 ? 1 : 0);
