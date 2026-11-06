<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
/* Le moteur porte les valeurs par défaut affichées en exemple : chargé ici,
 * l'autoload du coeur ne le connaît pas. */
require_once __DIR__ . '/../../core/class/routeursolairebe.class.php';
$plugin = plugin::byId('routeursolairebe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
$d = routeursolairebeEngine::DEFAULTS;
$t = routeursolairebeEngine::TEXT_DEFAULTS;

/*
 * Un champ qui désigne une commande d'un autre plugin : la zone de texte, et
 * le bouton qui ouvre le sélecteur du coeur. Le coeur traduit tout seul les
 * « #[Pièce][Équipement][Commande]# » en identifiants à l'enregistrement, et
 * inversement à l'ouverture : un renommage ne casse donc rien.
 */
function routeursolairebeCmdField($_key, $_label, $_type, $_help) {
	?>
	<div class="form-group">
		<label class="col-sm-4 control-label"><?php echo $_label; ?>
			<sup><i class="fas fa-question-circle" title="<?php echo $_help; ?>"></i></sup>
		</label>
		<div class="col-sm-7">
			<div class="input-group">
				<input type="text" class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="<?php echo $_key; ?>">
				<span class="input-group-btn">
					<a class="btn btn-default rsPickCmd" data-key="<?php echo $_key; ?>" data-type="<?php echo $_type; ?>" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>
					<a class="btn btn-default roundedRight rsClearCmd" data-key="<?php echo $_key; ?>" title="{{Vider}}"><i class="fas fa-times"></i></a>
				</span>
			</div>
		</div>
	</div>
	<?php
}

/* Un réglage. En texte et non en « number » : un navigateur en français vide
 * en silence « 2,4 » dans un champ numérique. Le serveur relit la virgule. */
function routeursolairebeField($_key, $_label, $_unit, $_placeholder, $_help) {
	?>
	<div class="form-group">
		<label class="col-sm-5 control-label"><?php echo $_label; ?>
			<sup><i class="fas fa-question-circle" title="<?php echo $_help; ?>"></i></sup>
		</label>
		<div class="col-sm-5">
			<div class="input-group">
				<input type="text" class="eqLogicAttr form-control<?php echo ($_unit !== '') ? ' roundedLeft' : ''; ?>" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" placeholder="<?php echo $_placeholder; ?>">
				<?php if ($_unit !== '') { ?>
					<span class="input-group-addon roundedRight"><?php echo $_unit; ?></span>
				<?php } ?>
			</div>
		</div>
	</div>
	<?php
}
?>
<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter un boiler}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>
		<legend><i class="fas fa-solar-panel"></i> {{Mes boilers}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucun boiler pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Cliquez sur « Ajouter un boiler » et nommez-le, par exemple « Boiler ».}}</li>';
			echo '<li>{{Onglet « Mesures et boiler » : choisissez la puissance du compteur (P1), négative quand vous injectez.}}</li>';
			echo '<li>{{Sans relais choisi, le boiler est simulé : le routeur décide comme en vrai, n\'envoie rien, et trace ses décisions pour régler les seuils.}}</li>';
			echo '</ol>';
			echo '</div>';
		}
		?>
		<div class="eqLogicThumbnailContainer">
			<?php
			foreach ($eqLogics as $eqLogic) {
				$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
				echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
				echo '<img src="' . $plugin->getPathImgIcon() . '">';
				echo '<br>';
				echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
				echo '</div>';
			}
			?>
		</div>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-solar-panel"></i><span class="hidden-xs"> {{Routeur}}</span></a></li>
			<li role="presentation"><a href="#sourcetab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-plug"></i><span class="hidden-xs"> {{Mesures et boiler}}</span></a></li>
			<li role="presentation"><a href="#settingstab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-sliders-h"></i><span class="hidden-xs"> {{Réglages}}</span></a></li>
			<li role="presentation"><a href="#guaranteetab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-shower"></i><span class="hidden-xs"> {{Eau chaude garantie}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ============================================ ROUTEUR ============================================ -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nom}}</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Boiler}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Objet parent}}</label>
								<div class="col-sm-7">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach (jeeObject::buildTree(null, false) as $object) {
											echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Activer}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
								<label class="col-sm-2 control-label">{{Visible}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-hand-pointer"></i> {{Marche}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Mode}}
									<sup><i class="fas fa-question-circle" title="{{Appliqué tout de suite, sans enregistrer. Automatique : le soleil, puis la chauffe forcée de nuit s'il manque de l'eau chaude. Marche forcée : allumé en permanence. Arrêt : éteint, plus aucune chauffe, même forcée.}}"></i></sup>
								</label>
								<div class="col-sm-7">
									<select class="form-control" id="sel_routeursolairebeMode">
										<?php foreach (routeursolairebeEngine::MODES as $mode) { ?>
											<option value="<?php echo $mode; ?>"><?php echo routeursolairebeEngine::modeLabel($mode); ?></option>
										<?php } ?>
									</select>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
				<div class="col-lg-6">
					<legend>
						<i class="fas fa-heartbeat"></i> {{En ce moment}}
						<a class="btn btn-default btn-xs pull-right" id="bt_routeursolairebeRefresh"><i class="fas fa-sync"></i> {{Évaluer maintenant}}</a>
					</legend>
					<div id="div_routeursolairebeStatus"></div>
					<legend style="margin-top:15px;"><i class="fas fa-history"></i> {{Journal des décisions}}</legend>
					<div id="div_routeursolairebeJournal" style="max-height:300px;overflow-y:auto;"></div>
					<legend style="margin-top:15px;"><i class="fas fa-file-csv"></i> {{Traces}}</legend>
					<div class="form-inline">
						<select class="form-control input-sm" id="sel_routeursolairebeTrace" style="min-width:200px;"></select>
						<a class="btn btn-default btn-sm" id="bt_routeursolairebeTrace"><i class="fas fa-download"></i> {{Télécharger}}</a>
					</div>
					<span class="help-block">{{Une ligne par minute : compteur, surplus, boiler, relais, raison. Le fichier « journees » donne le bilan de chaque journée. Séparateur point-virgule, décimales à la virgule : il s'ouvre tel quel dans un tableur en français.}}</span>
				</div>
			</div>

			<!-- ======================================= MESURES ET BOILER ======================================= -->
			<div role="tabpanel" class="tab-pane" id="sourcetab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tachometer-alt"></i> {{Compteur}}</legend>
							<?php
							routeursolairebeCmdField('grid_cmd', '{{Puissance au compteur}}', 'info',
								'{{La puissance échangée avec le réseau, en watts : positive quand la maison prélève, négative quand elle injecte. Avec un P1 HomeWizard, c\'est la commande « Puissance ». Le routeur passe à chaque nouvelle mesure.}}');
							?>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Inverser le signe}}</label>
								<div class="col-sm-7">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="grid_invert">
									<span class="help-block" style="margin:0;">{{Cochez si votre compteur donne l'injection en positif.}}</span>
								</div>
							</div>
							<?php
							routeursolairebeCmdField('production_cmd', '{{Production solaire}}', 'info',
								'{{Facultatif, pour l\'affichage seulement : la décision se prend sur le compteur, qui seul dit ce qui reste après la consommation de la maison.}}');
							?>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-flask"></i> {{Simulation}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Boiler simulé}}</label>
								<div class="col-sm-7">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="simulation" checked>
									<span class="help-block" style="margin:0;">{{Le routeur décide comme en vrai, sur le compteur augmenté d'un boiler virtuel, et n'envoie aucun ordre. Toujours actif tant que les deux commandes du relais ne sont pas choisies.}}</span>
								</div>
							</div>
							<?php
							routeursolairebeField('tank_volume', '{{Volume}}', 'L', $d['tank_volume'], '{{Le volume du boiler simulé.}}');
							routeursolairebeField('tank_setpoint', '{{Thermostat du boiler}}', '°C', $d['tank_setpoint'], '{{La température à laquelle le thermostat du boiler coupe. Le boiler simulé est plein à cette température.}}');
							routeursolairebeField('cold_water', '{{Eau froide}}', '°C', $d['cold_water'], '{{La température de l\'eau qui entre dans le boiler.}}');
							routeursolairebeField('standby_loss', '{{Pertes à chaud}}', 'W', $d['standby_loss'], '{{La chaleur que perd un boiler plein, en continu. 50 à 80 W pour un 200 L récent.}}');
							routeursolairebeField('draws', '{{Puisages}}', '', $t['draws'], '{{Les douches et vaisselles simulées : heure=kWh, séparés par des points-virgules. Une douche de 5 minutes, c\'est environ 1 kWh.}}');
							?>
						</fieldset>
					</form>
				</div>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-fire"></i> {{Boiler}}</legend>
							<?php
							routeursolairebeField('boiler_power', '{{Puissance de la résistance}}', 'W', $d['boiler_power'], '{{La puissance inscrite sur la plaque du boiler. Le routeur ne l\'allume que quand le surplus la couvre presque entièrement.}}');
							routeursolairebeCmdField('on_cmd', '{{Relais : marche}}', 'action',
								'{{La commande qui ferme le contacteur du boiler. Un boiler de 2 à 3 kW se commande par un contacteur de 20 à 25 A, piloté par un relais domotique, jamais par le relais seul.}}');
							routeursolairebeCmdField('off_cmd', '{{Relais : arrêt}}', 'action', '{{La commande qui ouvre le contacteur du boiler.}}');
							routeursolairebeCmdField('boiler_power_cmd', '{{Puissance mesurée du boiler}}', 'info',
								'{{Facultatif mais recommandé : la puissance réellement consommée par le boiler (Shelly Pro EM, prise avec mesure). Elle dit sans erreur si son thermostat a coupé. Sans elle, le routeur le devine au compteur, à l\'allumage.}}');
							routeursolairebeField('relay_resend', '{{Renvoyer l\'ordre toutes les}}', 'min', 5, '{{Le relais reçoit à nouveau son ordre à cet intervalle : un ordre perdu se rattrape. 0 désactive le renvoi.}}');
							?>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ============================================ RÉGLAGES ============================================ -->
			<div role="tabpanel" class="tab-pane" id="settingstab">
				<br>
				<div class="alert alert-info" style="margin:0 5px 10px 5px;">
					{{Le boiler s'allume quand le surplus (l'injection, plus ce que le boiler consomme déjà) couvre sa puissance moins la marge, pendant le délai d'allumage. Une fois allumé, chaque wattheure prélevé tombe dans un seau, chaque wattheure injecté en ressort : il s'éteint quand le seau déborde. Un nuage court ne l'éteint donc pas, un ciel qui se couvre pour de bon, si. Les valeurs proposées conviennent pour commencer ; les traces de la simulation disent ensuite s'il faut les changer.}}
				</div>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-sun"></i> {{Allumage}}</legend>
							<?php
							routeursolairebeField('on_margin', '{{Prélèvement accepté à l\'allumage}}', 'W', $d['on_margin'], '{{Avec 2 400 W de résistance et 200 W de marge, le boiler s\'allume dès 2 200 W de surplus, quitte à prélever 200 W. Plus la marge est grande, plus il chauffe au soleil, et plus il prélève.}}');
							routeursolairebeField('on_delay', '{{Délai d\'allumage}}', 's', $d['on_delay'], '{{Le surplus doit tenir ce temps avant d\'allumer : une éclaircie de 30 secondes ne suffit pas.}}');
							routeursolairebeField('smoothing', '{{Lissage du surplus}}', 's', $d['smoothing'], '{{Constante de la moyenne glissante du surplus. 0 pour aucune.}}');
							?>
						</fieldset>
					</form>
				</div>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-cloud"></i> {{Extinction}}</legend>
							<?php
							routeursolairebeField('import_budget', '{{Prélèvement toléré (seau)}}', 'Wh', $d['import_budget'], '{{Les wattheures prélevés acceptés avant d\'éteindre, le soleil revenu les rembourse. 100 Wh, c\'est un nuage complet de 2 minutes et demie sur un boiler de 2,4 kW, ou 3 centimes.}}');
							routeursolairebeField('hard_import', '{{Coupure immédiate au-delà de}}', 'W', $d['hard_import'], '{{Un prélèvement plus fort que ce qu\'expliquerait le boiler seul — un four, une plaque — l\'éteint sans attendre le seau ni la durée minimale de marche.}}');
							routeursolairebeField('hard_delay', '{{pendant}}', 's', $d['hard_delay'], '{{Le temps que ce prélèvement fort doit durer.}}');
							routeursolairebeField('min_on', '{{Marche minimale}}', 'min', $d['min_on'], '{{Une fois allumé, le boiler le reste au moins ce temps. Protège le contacteur.}}');
							routeursolairebeField('min_off', '{{Arrêt minimal}}', 'min', $d['min_off'], '{{Une fois éteint, le boiler le reste au moins ce temps.}}');
							?>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ======================================= EAU CHAUDE GARANTIE ======================================= -->
			<div role="tabpanel" class="tab-pane" id="guaranteetab">
				<br>
				<div class="alert alert-info" style="margin:0 5px 10px 5px;">
					{{La journée d'eau chaude commence à l'heure choisie, le matin. Si à la fin de la journée le soleil n'a pas chauffé l'objectif — et que le boiler n'a pas été plein — le routeur rattrape la différence dans une plage de chauffe forcée, en heures creuses, et se place à la fin de la plage : l'eau est chaude au réveil sans avoir refroidi toute la nuit. En hiver, c'est le cas presque tous les jours.}}
				</div>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-shower"></i> {{Objectif}}</legend>
							<?php
							routeursolairebeField('daily_target', '{{Chauffer au moins}}', '{{kWh par jour}}', $d['daily_target'], '{{L\'énergie à mettre dans le boiler chaque journée. 4 à 6 kWh pour un ménage de 3 ou 4 personnes. Un boiler plein vaut l\'objectif atteint.}}');
							routeursolairebeField('day_start', '{{Début de la journée}}', '', $t['day_start'], '{{L\'heure à laquelle commence une journée d\'eau chaude : la chauffe de la nuit qui précède appartient à la veille.}}');
							routeursolairebeField('forced_windows', '{{Plages de chauffe forcée}}', '', $t['forced_windows'], '{{Les heures creuses où rattraper ce qui manque, séparées par des virgules, par exemple « 22:00-07:00 ». Jamais de 17 h à 22 h : c\'est la pointe.}}');
							routeursolairebeField('forced_margin', '{{Finir avant la fin de plage de}}', 'min', $d['forced_margin'], '{{La marge gardée avant la fin de la plage.}}');
							?>
						</fieldset>
					</form>
				</div>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-bacterium"></i> {{Anti-légionelle}}</legend>
							<?php
							routeursolairebeField('legionella_days', '{{Chauffe complète après}}', '{{jours}}', $d['legionella_days'], '{{Si le boiler n\'a pas été plein depuis ce nombre de jours, il chauffe jusqu\'au bout dès le début de la plage de nuit. Réglez son thermostat à 60 °C au moins. 0 désactive.}}');
							routeursolairebeField('legionella_max', '{{Durée maximale}}', 'h', $d['legionella_max'], '{{La chauffe complète s\'arrête au bout de ce temps, même sans boiler plein.}}');
							?>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-euro-sign"></i> {{Prix, pour le gain estimé}}</legend>
							<?php
							routeursolairebeField('price_import', '{{kWh prélevé}}', '€', $d['price_import'], '{{Le prix complet d\'un kWh pris au réseau : énergie, réseau, taxes.}}');
							routeursolairebeField('price_injection', '{{Valeur d\'un kWh injecté}}', '€', $d['price_injection'], '{{Ce qu\'un kWh injecté vous rapporte vraiment. Sans compensation : le prix de rachat du fournisseur. Avec compensation (Wallonie, installation d\'avant 2024) et tarif prosumer proportionnel : la part énergie seule, puisque les frais de réseau se paient sur tout ce qui est prélevé. Le gain d\'un kWh routé est la différence entre les deux prix.}}');
							?>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ============================================ COMMANDES ============================================ -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<table id="table_cmd" class="table table-bordered table-condensed">
					<thead>
						<tr>
							<th>{{Nom}}</th>
							<th>{{Type}}</th>
							<th>{{Options}}</th>
							<th>{{Action}}</th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
		</div>
	</div>
</div>

<?php include_file('core', 'plugin.template', 'js'); ?>
<?php include_file('desktop', 'routeursolairebe', 'js', 'routeursolairebe'); ?>
