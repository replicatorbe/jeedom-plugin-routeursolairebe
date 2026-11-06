<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-info-circle"></i> {{Rien à régler ici}}</legend>
		<div class="alert alert-info">
			{{Tous les réglages sont propres à chaque boiler et se trouvent dans la page de l'équipement. Les traces minute par minute sont gardées 90 jours, dans le dossier data/traces du plugin, et se téléchargent depuis l'onglet « En ce moment » de l'équipement. Pour suivre les décisions dans le journal du plugin, réglez son niveau sur « Info » ; « Debug » y ajoute une ligne à chaque mesure du compteur.}}
		</div>
	</fieldset>
</form>
