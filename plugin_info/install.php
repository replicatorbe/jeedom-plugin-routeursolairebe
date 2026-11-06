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

require_once __DIR__ . '/../../../core/php/core.inc.php';
/* La désinstallation passe ici alors que le plugin peut déjà être désactivé :
 * l'autoload ne chargerait alors plus sa classe. */
require_once __DIR__ . '/../core/class/routeursolairebe.class.php';

function routeursolairebe_install() {
}

function routeursolairebe_update() {
    /* Les routeurs déjà créés n'ont pas les commandes ajoutées depuis. Un
     * équipement à la fois, sous son propre try : un routeur en défaut ne doit
     * pas priver les autres de leur mise à jour. */
    foreach (eqLogic::byType('routeursolairebe') as $eqLogic) {
        try {
            $eqLogic->createCommands();
        } catch (Throwable $e) {
            log::add('routeursolairebe', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}

function routeursolairebe_remove() {
    /* Désinstaller le plugin ne doit pas laisser le boiler allumé : plus
     * personne ne viendrait l'éteindre. */
    foreach (eqLogic::byType('routeursolairebe') as $eqLogic) {
        try {
            $eqLogic->shutdown(__('plugin désinstallé', __FILE__));
        } catch (Throwable $e) {
            log::add('routeursolairebe', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
    message::removeAll('routeursolairebe');
}
