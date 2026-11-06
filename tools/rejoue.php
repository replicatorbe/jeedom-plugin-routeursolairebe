<?php
/* Rejoue des journées réelles dans le moteur du routeur, boiler simulé.
 *
 *   php tools/rejoue.php <trace.csv> [<trace.csv> ...] [réglage=valeur ...]
 *
 * Exemples :
 *   php tools/rejoue.php tests/data/p1-2026-11-06.csv
 *   php tools/rejoue.php /var/www/html/plugins/routeursolairebe/data/traces/eq618-2026-11-*.csv boiler_power=1500
 *   php tools/rejoue.php trace.csv on_margin=500 import_budget=200 depart=30 --detail
 *
 * Le fichier est une trace du plugin (colonne reseau_reel_W) ou tout CSV
 * « heure;reseau_reel_W » ; la date se lit dans le nom du fichier. Les
 * fichiers sont rejoués à la suite, dans l'ordre des dates, avec le même
 * boiler simulé : c'est ainsi qu'on voit si un réglage tient sur une semaine.
 *
 * « depart=60 » fixe la charge de départ du boiler simulé, en %. « --detail »
 * liste chaque bascule du relais avec sa raison.
 *
 * C'est l'outil des réglages : changer un seuil, rejouer la semaine, comparer
 * les kWh solaires, la chauffe forcée et le nombre d'allumages.
 */

date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/../core/class/routeursolairebeEngine.class.php';

$fichiers = array();
$reglages = array();
$depart = null;
$detail = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument == '--detail') {
        $detail = true;
    } elseif (strpos($argument, '=') !== false && !is_file($argument)) {
        list($cle, $valeur) = explode('=', $argument, 2);
        if ($cle == 'depart') {
            $depart = (float) $valeur;
        } else {
            $reglages[$cle] = $valeur;
        }
    } else {
        $fichiers[] = $argument;
    }
}
if (count($fichiers) == 0) {
    fwrite(STDERR, "Usage : php tools/rejoue.php <trace.csv> [...] [réglage=valeur ...] [depart=%] [--detail]\n");
    exit(2);
}

/* Les mesures de chaque fichier, datées par le nom du fichier. */
$mesures = array();
foreach ($fichiers as $fichier) {
    if (!preg_match('/(\d{4}-\d{2}-\d{2})/', basename($fichier), $m)) {
        fwrite(STDERR, "Pas de date dans le nom : $fichier\n");
        continue;
    }
    $jour = $m[1];
    $lignes = file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $entete = str_getcsv(ltrim(array_shift($lignes), "\xEF\xBB\xBF"), ';', '"', '');
    $colHeure = array_search('heure', $entete);
    $colReseau = array_search('reseau_reel_W', $entete);
    if ($colHeure === false || $colReseau === false) {
        fwrite(STDERR, "Colonnes heure / reseau_reel_W absentes : $fichier\n");
        continue;
    }
    foreach ($lignes as $ligne) {
        $champs = str_getcsv($ligne, ';', '"', '');
        $valeur = routeursolairebeEngine::number(isset($champs[$colReseau]) ? $champs[$colReseau] : '');
        $ts = strtotime($jour . ' ' . $champs[$colHeure]);
        if ($ts !== false) {
            $mesures[$ts] = $valeur;
        }
    }
}
ksort($mesures);
if (count($mesures) == 0) {
    fwrite(STDERR, "Aucune mesure.\n");
    exit(1);
}

$s = routeursolairebeEngine::cleanSettings($reglages);
$etat = array();
$debut = array_key_first($mesures);
if ($depart !== null) {
    $etat = routeursolairebeEngine::cleanState(array(), $s, $debut);
    $etat['sim_energy'] = routeursolairebeEngine::tankCapacity($s) * max(0, min(100, $depart)) / 100;
}

/* Le moteur passe à chaque mesure, comme l'écouteur, et au moins chaque
 * minute, comme le cron. Après la dernière mesure, on va jusqu'à la fin de la
 * journée d'eau chaude pour voir la chauffe forcée de la nuit. */
$fin = strtotime('+1 day', routeursolairebeEngine::at(array_key_last($mesures), routeursolairebeEngine::minutes($s['day_start'])));
if ($fin - array_key_last($mesures) > 86400) {
    $fin -= 86400;
}
$fin += 60;
$journees = array();
$bascules = array();
$derniere = null;
$ts = $debut;
$cles = array_keys($mesures);
$i = 0;
$decision = null;
while ($ts <= $fin) {
    while ($i < count($cles) && $cles[$i] <= $ts) {
        $derniere = $mesures[$cles[$i]];
        $i++;
    }
    $decision = routeursolairebeEngine::decide($s, array('now' => $ts, 'grid' => $derniere, 'simulation' => true), $etat);
    $etat = $decision['state'];
    if (in_array('rollover', $decision['events'], true)) {
        $journees[] = $decision['values']['yesterday'];
    }
    if (in_array('on', $decision['events'], true) || in_array('off', $decision['events'], true)) {
        $bascules[] = date('d/m H:i:s', $ts) . ($decision['target'] ? '  ON  ' : '  off ') . $decision['reason'];
    }
    $suivant = ($i < count($cles)) ? min($cles[$i], $ts + 60) : $ts + 60;
    $ts = max($ts + 1, $suivant);
}

echo "Réglages : ";
foreach ($reglages as $cle => $valeur) {
    echo "$cle=" . $s[$cle] . ' ';
}
echo (count($reglages) == 0 ? "par défaut" : "") . "\n\n";
printf("%-11s %8s %8s %8s %8s %8s %4s %7s %7s %s\n", 'journée', 'chauffé', 'soleil', 'réseau', 'forcé', 'injecté', 'cyc', 'chauffe', 'gain', 'plein');
$total = routeursolairebeEngine::freshStats();
foreach ($journees as $j) {
    printf("%-11s %8.2f %8.2f %8.2f %8.2f %8.2f %4d %7s %6.2f€ %s\n", $j['period'], $j['heated'], $j['solar'], $j['grid'],
           $j['forced'], $j['exported'], $j['cycles'], routeursolairebeEngine::duration($j['on_s']), $j['savings'], $j['full'] ? 'oui' : '');
    foreach (array('heated', 'solar', 'grid', 'forced', 'exported', 'savings', 'cycles') as $cle) {
        $total[$cle] += $j[$cle];
    }
}
if (count($journees) > 1) {
    printf("%-11s %8.2f %8.2f %8.2f %8.2f %8.2f %4d %7s %6.2f€\n", 'total', $total['heated'], $total['solar'], $total['grid'],
           $total['forced'], $total['exported'], $total['cycles'], '', $total['savings']);
}
printf("\nBoiler simulé à la fin : %.0f %%\n", $decision['values']['tank']);
if ($detail) {
    echo "\nBascules :\n  " . implode("\n  ", $bascules) . "\n";
}
