<?php
$vs_sample_var 	= $this->getVar('sample_var');
?>

<h2>lesCollections.fr</h2>
<p>Depuis cet écran, vous pouvez gérer vos préférences d'abonnement sur lescollections.fr.</p>
<div class="sectionBox">
	<h3>Vos coordonnées</h3>
	<div class="clear"></div>
	<p>Merci de vérifier vos coordonnées et de nous signaler toute modification.</p>
	<table id="caSearchConfigSettingList" class="listtable" width="100%" border="0" cellpadding="0" cellspacing="1">
		<thead>
			<tr>
				<th class="list-header-unsorted">
					Information				</th>
				<th class="list-header-unsorted">
					Valeur				</th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td>Numéro d'abonnement</td>
				<td>#ID</td>
			</tr>		
			<tr>
				<td>Email</td>
				<td>maryanne_prunet@yahoo.com</td>
			</tr>
			<tr>
				<td>Nom, prénom</td>
				<td>Prunet, Mary Anne</td>
			</tr>
			<tr>
				<td>Coordonnées postales</td>
				<td>Mary Anne Prunet<br/>
Av Winston Churchill 214 boite 5<br/>
1180 Bruxelles<br/>
Belgique</td>
			</tr>
		</tbody>
	</table>
	<div class="control-box rounded">
		<div class="control-box-left-content">
			<?php print caNavButton($this->request, __CA_NAV_BUTTON_EDIT__, "Modifier mes informations", '', $this->request->getModulePath(), $this->request->getController(), 'Paiement', array()); ?>
		</div>
		<div class="control-box-right-content">
		</div>
		<div class="control-box-middle-content">				
		</div>
	</div>	
</div>
<div class="clear"></div>
<div class="sectionBox">
	<h3>Votre abonnement</h3>
	<div class="clear"></div>
	<p>Retrouvez ici vos informations de paiement.</p>
	<table id="caSearchConfigSettingList" class="listtable" width="100%" border="0" cellpadding="0" cellspacing="1">
		<thead>
			<tr>
				<th class="list-header-unsorted">
					Information
				</th>
				<th class="list-header-unsorted">
					Valeur
				</th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td>Type d'abonnement</td>
				<td>Paiement mensuel CB</td>
			</tr>
			<tr>
				<td>Numéro de compte paiement</td>
				<td>#ID</td>
			</tr>
			<tr>
				<td>Date de dernier paiement</td>
				<td>__/__/____</td>
			</tr>
			<tr>
				<td>Abonnement valide jusqu'au</td>
				<td>__/__/____</td>
			</tr>
		</tbody>
	</table>
	<div class="control-box rounded">
		<div class="control-box-left-content">
			<?php print caNavButton($this->request, __CA_NAV_BUTTON_SETTINGS__, "Réaliser un paiement", '', $this->request->getModulePath(), $this->request->getController(), 'Paiement', array()); ?>
		</div>
		<div class="control-box-right-content">
		</div>
		<div class="control-box-middle-content">				
		</div>
	</div>
</div>