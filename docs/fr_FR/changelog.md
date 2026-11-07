# Changelog

## 0.2

- **Chauffe complète tous les 2 jours sans boiler plein** (7 auparavant) : un
  objectif du jour plus court que la consommation réelle vidait le boiler en
  une semaine grise. Elle se place en fin de nuit au lieu de partir dès que le
  délai tombait, à une heure qui glissait d'une nuit à l'autre.
- **Journal plus juste** : une chauffe coupée par la fin de la plage le dit,
  avec ce qui manquait, au lieu d'annoncer « la journée a son eau chaude ».
- **tools/rejoue.php** : colonne de charge minimale du boiler simulé.

## 0.1

- **Première version** : un routeur solaire tout ou rien pour un boiler
  électrique. Allumage sur surplus avec marge et délai, extinction au seau de
  prélèvement, durées minimales de marche et d'arrêt, coupure sur prélèvement
  fort.
- **Eau chaude garantie** : objectif quotidien en kWh, rattrapage en fin de
  plage d'heures creuses, chauffe complète anti-légionelle.
- **Mode simulation** : un boiler virtuel (volume, thermostat, pertes,
  puisages) tant que le vrai n'est pas branché, et des traces CSV minute par
  minute pour régler les seuils.
