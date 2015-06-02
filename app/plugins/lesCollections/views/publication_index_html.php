<?php
$pb_saved 	= (bool)$this->getVar('saved');
$va_infos = $this->getVar("infos");
?>

<h2>lesCollections.fr</h2>
<p>Depuis cet écran, vous pouvez gérer vos préférences concernant la publication de vos collections.</p>
<?php if($pb_saved) : ?>
<div class="notification-info-box rounded">
    <ul class="notification-info-box">
        <li class="notification-info-box">Modifications enregistrées</li>
    </ul>
</div>
<?php endif; ?>
<div class="sectionBox">
	<h3>Page d'accueil</h3>
	<div class="clear"></div>
	<p>Merci de vérifier vos coordonnées et de nous signaler toute modification.</p>
    <form target="_top" action="<?php print caNavUrl($this->request, "*", "*", "*"); ?>" method="post" id="collectionPublicationInfoForm">
	<table id="caSearchConfigSettingList" class="listtable" width="100%" border="0" cellpadding="0" cellspacing="1">
		<thead>
			<tr>
				<th class="list-header-unsorted">
					Paramètre
                </th>
				<th class="list-header-unsorted">
					Valeur
                </th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td>Titre de votre collection</td>
				<td><input type="text" name="collectionname" style="width:90%;" placeholder="Mes collections sur lescollections.fr" width=""
                           value="<?php print ($va_infos->collectionname ? : ""); ?>"/></td>
			</tr>
			<tr>
                <td>Sous-titre</td>
                <td><input type="text" name="collectionsubname" style="width:90%;" placeholder="Pseudo"
                           value="<?php print ($va_infos->collectionsubname ? : ""); ?>"/></td>
			</tr>
            <tr>
                <td>Introduction text</td>
                <td><textarea name="collectionintro" style="width:90%;height:110px;" placeholder="Veni, Vidi, Léonard de Vinci"/><?php print ($va_infos->collectionintro ? : ""); ?></textarea></td>
            </tr>
		</tbody>
	</table>
        <input type="submit" name="Mettre à jour" />
    </form>
</div>
