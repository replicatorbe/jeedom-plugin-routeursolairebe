# Routeur Solaire BE — plugin Jeedom

Chauffer l'eau du boiler avec le soleil qu'on injecte sur le réseau, et
garantir quand même l'eau chaude quand il n'y en a pas.

## Ce qu'il apporte

- Un routeur **tout ou rien** : le boiler est commandé par un relais (un
  contacteur piloté par un Shelly, par exemple), sans gradateur.
- Il écoute la **puissance au compteur** à chaque mesure, et allume le boiler
  quand le surplus couvre presque toute sa résistance.
- Un **seau de prélèvement** plutôt qu'un simple délai : un nuage passager ne
  l'éteint pas, un ciel qui se couvre pour de bon, si.
- Le **boiler plein** est deviné sans sonde, quand son thermostat a coupé.
- L'**eau chaude garantie** : ce que le soleil n'a pas chauffé est rattrapé en
  heures creuses, en fin de nuit, avec une chauffe complète si le boiler n'a
  pas été plein depuis deux jours.
- Un **mode simulation** qui fait tourner le même cerveau sur un boiler
  virtuel, avant même d'avoir le boiler, et trace chaque minute dans un CSV.

## Ce qu'il ne fait pas

- Pas de gradateur ni de pilotage proportionnel.
- Pas de prévision météo : la chauffe forcée se décide sur ce que le soleil a
  réellement fait.

Documentation : [docs/fr_FR/index.md](docs/fr_FR/index.md).

## Développement

```bash
php tests/run.php            # le moteur de décision, hors ligne
php tests/check-classes.php  # les pièges du coeur, contre le Jeedom installé
php tools/make-icon.php      # refait l'icône
```

Toute la décision est dans `core/class/routeursolairebeEngine.class.php`, qui
ne connaît pas Jeedom ; `core/class/routeursolairebe.class.php` lit le
compteur, appelle le moteur et envoie les ordres.

## Licence

AGPL.
