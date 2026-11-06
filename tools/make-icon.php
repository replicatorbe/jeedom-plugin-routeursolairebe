<?php
/* Fabrique l'icône du plugin.
 *
 *   php tools/make-icon.php
 *
 * Dessinée ici plutôt que déposée en binaire opaque : on peut la relire et la
 * refaire. Le dessin est fait en 1024 puis réduit en 256, ce qui donne les
 * bords lissés que GD ne produit pas sur un remplissage direct.
 *
 * Un soleil en haut à gauche, un boiler devant lui, et un éclair qui va de
 * l'un à l'autre : le plugin tient en cette image, le soleil qui chauffe
 * l'eau au lieu de partir sur le réseau.
 */

const TAILLE = 256;
const ECHELLE = 4;

$grand = imagecreatetruecolor(TAILLE * ECHELLE, TAILLE * ECHELLE);
imagesavealpha($grand, true);
imagealphablending($grand, false);
imagefill($grand, 0, 0, imagecolorallocatealpha($grand, 0, 0, 0, 127));
imagealphablending($grand, true);

$jaune  = imagecolorallocate($grand, 0xF5, 0xB0, 0x1E);
$orange = imagecolorallocate($grand, 0xF2, 0x7A, 0x1A);
$bleu   = imagecolorallocate($grand, 0x2B, 0x8C, 0xD8);
$rouge  = imagecolorallocate($grand, 0xD6, 0x33, 0x2B);
$blanc  = imagecolorallocate($grand, 0xFF, 0xFF, 0xFF);
$gris   = imagecolorallocate($grand, 0x36, 0x44, 0x4B);

$e = function ($_valeur) { return (int) round($_valeur * ECHELLE); };

/* Le soleil : un disque et ses rayons. */
for ($i = 0; $i < 12; $i++) {
    $angle = $i * M_PI / 6;
    $x = 78 + cos($angle) * 66;
    $y = 78 + sin($angle) * 66;
    imagefilledellipse($grand, $e($x), $e($y), $e(18), $e(18), $jaune);
}
imagefilledellipse($grand, $e(78), $e(78), $e(96), $e(96), $jaune);
imagefilledellipse($grand, $e(78), $e(78), $e(70), $e(70), $orange);

/* Le boiler : une cuve arrondie, cerclée de gris, l'eau froide en bas et
 * chaude en haut. */
imagefilledrectangle($grand, $e(118), $e(96), $e(222), $e(232), $gris);
imagefilledellipse($grand, $e(170), $e(96), $e(104), $e(44), $gris);
imagefilledellipse($grand, $e(170), $e(232), $e(104), $e(44), $gris);
imagefilledrectangle($grand, $e(128), $e(100), $e(212), $e(166), $rouge);
imagefilledellipse($grand, $e(170), $e(100), $e(84), $e(32), $rouge);
imagefilledrectangle($grand, $e(128), $e(166), $e(212), $e(228), $bleu);
imagefilledellipse($grand, $e(170), $e(228), $e(84), $e(32), $bleu);

/* L'éclair, en blanc sur la cuve. */
$eclair = array(178, 112, 148, 172, 170, 172, 158, 218, 196, 150, 174, 150, 188, 112);
imagefilledpolygon($grand, array_map($e, $eclair), $blanc);

$icone = imagecreatetruecolor(TAILLE, TAILLE);
imagesavealpha($icone, true);
imagealphablending($icone, false);
imagefill($icone, 0, 0, imagecolorallocatealpha($icone, 0, 0, 0, 127));
imagecopyresampled($icone, $grand, 0, 0, 0, 0, TAILLE, TAILLE, TAILLE * ECHELLE, TAILLE * ECHELLE);

$cible = __DIR__ . '/../plugin_info/routeursolairebe_icon.png';
imagepng($icone, $cible);
echo "Écrit : $cible\n";
