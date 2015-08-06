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
                    <td>Texte d'introduction</td>
                    <td><textarea name="collectionintro" style="width:90%;height:110px;" placeholder="Veni, Vidi, Léonard de Vinci"/><?php print ($va_infos->collectionintro ? : ""); ?></textarea></td>
                </tr>
            </tbody>
        </table>

        <h3>Page contact</h3>
        <div class="clear"></div>
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
                <td>Informations de contact (adresse postale, téléphone, etc.)</td>
                <td><textarea name="contactinformations" style="width:90%;height:110px;" placeholder="<b>Nos coordonnées</b><br/>Notre adresse..."/><?php print ($va_infos->contactinformations ? : ""); ?></textarea></td>
            </tr>
            <tr>
                <td>Texte du lien de menu pour afficher tous les objets de la base</td>
                <td><input type="text" name="menucollections" style="width:90%;" placeholder="Collections"
                           value="<?php print ($va_infos->menucollections ? : ""); ?>"/></td>
            </tr>
            <tr>
                <td>Texte du lien de menu pour accéder à la galerie d'ensembles</td>
                <td><input type="text" name="menugalerie" style="width:90%;" placeholder="Galerie"
                           value="<?php print ($va_infos->menugalerie ? : ""); ?>"/></td>
            </tr>
            <tr>
                <td>Texte du lien de menu pour accéder aux actualités</td>
                <td><input type="text" name="menublog" style="width:90%;" placeholder="Blog"
                           value="<?php print ($va_infos->menublog ? : ""); ?>"/></td>
            </tr>
           <!-- <tr>
                <td>Autres liens à ajouter au menu</td>
                <td><input type="text" name="menuautrelien1" style="width:90%;" placeholder="Autre lien: texte"
                           value="<?php print ($va_infos->menuautrelien1 ? : ""); ?>"/></td>
                <td><input type="text" name="menuautrelien1" style="width:90%;" placeholder="Autre lien: url du lien"
                           value="<?php print ($va_infos->menuautrelien1 ? : ""); ?>"/></td>

            </tr>-->
            </tbody>
        </table>

        <h3>Détail des objets</h3>
        <div class="clear"></div>
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
                <td>Copyright (affiché en bas à droite sur les pages détaillées des objets)</td>
                <td><input type="text" name="copyright" style="width:90%;" placeholder="CC-BY-SA" width=""
                           value="<?php print ($va_infos->copyright ? : ""); ?>"/></td>
            </tr>
            </tbody>
        </table>

        <h3>Intitulés des menus</h3>
        <div class="clear"></div>
        <p>Dans cette partie, vous pouvez modifier le nom que portent les éléments menus dans l'interface publique (Pawtucket).</p>
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
                <td>Blog</td>
                <td><input type="text" name="menublog" style="width:90%;" placeholder="Blog" width=""
                           value="<?php print ($va_infos->menublog ? : ""); ?>"/></td>
            </tr>
            <tr>
                <td>Collections</td>
                <td><input type="text" name="menucollections" style="width:90%;" placeholder="Collections"
                           value="<?php print ($va_infos->menucollections ? : ""); ?>"/></td>
            </tr>
            <tr>
                <td>Galeries</td>
                <td><input type="text" name="menugalerie" style="width:90%;" placeholder="Galeries"
                            value="<?php print ($va_infos->menugalerie ? : ""); ?>" /></td>
            </tr>
            </tbody>
        </table>

        <h3>Paramètres avancés</h3>
        <div class="clear"></div>
        <p>Dans cette partie, vous pouvez activer ou désactiver certaines fonctions de l'interface publique. Prenez le temps de lire la documentation avant de tenter d'activer des choses au hasard ;-)</p>
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
                <td>Connexion obligatoire par login/mot de passe</td>
                <td>
                    <INPUT type= "radio" name="loginrequis" value="0" <?php print ($va_infos->loginrequis ? "" : "checked"); ?>> désactivé
                    <INPUT type= "radio" name="loginrequis" value="1" <?php print ($va_infos->loginrequis ? "checked" : ""); ?>> activé
            </tr>
            <tr>
                <td>Marge autour des photos en page d'accueil</td>
                <td>
                    <INPUT type= "radio" name="margeimageaccueil" value="0" <?php print ($va_infos->margeimageaccueil ? "" : "checked"); ?>> désactivé
                    <INPUT type= "radio" name="margeimageaccueil" value="1" <?php print ($va_infos->margeimageaccueil ? "checked" : ""); ?>> activé
            </tr>
            </tbody>
        </table>

        <input type="submit" value="Mettre à jour"/>
    </form>
</div>
