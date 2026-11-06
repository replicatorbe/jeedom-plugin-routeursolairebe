# Routeur Solaire BE

Le soleil de midi part sur le réseau pour quelques centimes, et le boiler
chauffe la nuit au prix plein. Ce plugin inverse les choses : il allume le
boiler quand les panneaux injectent, et ne chauffe au réseau que ce que le
soleil n'a pas fait.

## Principe

Le boiler n'a pas de gradateur : un relais l'allume ou l'éteint, à pleine
puissance. Le routeur ne l'allume donc que quand le surplus couvre presque
toute sa résistance.

**Le surplus** est ce que la maison injecte, plus ce que le boiler consomme
déjà. Sans ce second terme, le boiler allumé ferait disparaître le surplus qui
l'a allumé, et se couperait aussitôt. Il est lissé sur une minute.

**L'allumage** : quand le surplus dépasse la puissance du boiler moins une
marge (2 400 − 200 = 2 200 W par défaut) pendant deux minutes.

**L'extinction** : une fois allumé, chaque wattheure prélevé tombe dans un
seau, chaque wattheure injecté en ressort. Le boiler s'éteint quand le seau
déborde (100 Wh par défaut). Un nuage de deux minutes le remplit à peine, et
le soleil revenu le vide ; un ciel qui se couvre pour de bon le fait déborder.
Un prélèvement plus fort que ce qu'explique le boiler seul (un four) le coupe
sans attendre.

**Le relais** est protégé par une marche minimale (10 min) et un arrêt
minimal (5 min).

**Le boiler plein** : avec une mesure de la puissance du boiler, c'est direct.
Sans elle, le routeur regarde le compteur à l'allumage : s'il ne monte pas
d'au moins un tiers de la résistance, le thermostat du boiler est ouvert.

## L'eau chaude garantie

La journée d'eau chaude commence le matin (7 h par défaut). Chaque journée a
un objectif en kWh (4 par défaut). Si le soleil ne l'a pas atteint, et que le
boiler n'a pas été plein, le routeur rattrape la différence dans une **plage
de chauffe forcée** (22:00-07:00 par défaut), et se place à la fin de la
plage : l'eau est chaude au réveil, sans avoir refroidi toute la nuit.

En Wallonie, les heures creuses couvrent aussi 11 h – 17 h depuis 2026 : on
peut ajouter cette plage (« 22:00-07:00, 11:00-17:00 »), mais elle prendrait
au réseau ce que le soleil de l'après-midi aurait peut-être fait. Jamais de
17 h à 22 h, c'est la pointe.

**Anti-légionelle** : si le boiler n'a pas été plein depuis 7 jours, il
chauffe jusqu'au bout dès le début de la plage de nuit (8 h au plus). Réglez
le thermostat du boiler à 60 °C au moins.

## Le mode simulation

Tant que les deux commandes du relais ne sont pas choisies, ou que la case
« Boiler simulé » est cochée, le routeur ne commande rien. Il décide comme en
vrai, mais sur le compteur augmenté d'un **boiler virtuel** : un réservoir
qui chauffe à pleine puissance quand son relais virtuel est fermé, perd un peu
de chaleur, se vide aux heures des douches (« 07:00=2; 20:00=2.5 », en kWh),
et dont le thermostat coupe quand il est plein.

C'est ce qui permet de régler les seuils, et de choisir la puissance du
boiler, sur les vraies journées de la maison.

## Les traces

Le routeur écrit une ligne par minute, et une à chaque bascule du relais,
dans `data/traces/eq<id>-<date>.csv` : compteur réel, compteur avec le boiler,
surplus, puissance du boiler, relais, seau, charge du boiler simulé, kWh du
jour, raison. Le fichier `eq<id>-journees.csv` donne le bilan de chaque
journée. Ils se téléchargent depuis l'onglet « Routeur » de l'équipement, et
sont gardés 90 jours.

Le journal du plugin, au niveau « Info », note chaque bascule avec sa raison,
chaque boiler plein et le bilan de chaque journée.

## Le gain estimé

Un kWh routé est un kWh qu'on n'injecte pas pour quelques centimes, et qu'on
ne prélève pas plus tard au prix plein. Le gain d'un kWh routé est donc la
différence entre le **prix du kWh prélevé** et la **valeur d'un kWh injecté**.

- Sans compensation : la valeur d'un kWh injecté est le prix de rachat du
  fournisseur (quelques centimes).
- Avec compensation (Wallonie, installation d'avant 2024) et tarif prosumer
  proportionnel au prélèvement : la valeur d'un kWh injecté est la part
  énergie seule, puisque les frais de réseau se paient sur tout ce qui est
  prélevé. Chaque kWh autoconsommé est un kWh de moins soumis à ces frais.

## Les commandes

| Commande | Rôle |
|---|---|
| État, Raison | ce que fait le routeur, et pourquoi |
| Boiler commandé | l'ordre du relais (virtuel en simulation) |
| Surplus disponible | le surplus lissé, en W |
| Réseau avec boiler | le compteur tel qu'il serait avec le boiler simulé |
| Puissance boiler | la puissance du boiler, simulée ou mesurée |
| Charge du boiler simulé | en % |
| Chauffé / Solaire routé / Prélevé / Chauffe forcée du jour | en kWh |
| Encore injecté du jour | ce que le routeur n'a pas pu utiliser |
| Gain du jour | en € |
| Allumages / Temps de chauffe du jour | pour surveiller le contacteur |
| Mode, Choisir le mode | Automatique, Marche forcée, Arrêt |

## Le matériel

Une résistance de 2 à 3 kW tire 9 à 13 A : il faut un **contacteur modulaire
de 20 à 25 A**, piloté par un relais domotique, jamais le relais seul. Une
mesure de la puissance du boiler (Shelly Pro EM, ou le Shelly qui pilote le
contacteur s'il mesure) rend la détection du boiler plein exacte. Un boiler
raccordé au compteur exclusif nuit n'est pas alimenté le jour : il faut le
repasser sur le compteur jour, à faire valider par un électricien.
