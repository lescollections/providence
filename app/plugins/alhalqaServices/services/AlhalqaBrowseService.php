<?php
/** ---------------------------------------------------------------------
 * app/plugins/aboutDrawingServices/services/AlhalqaBrowseService.php
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2015 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * @package CollectiveAccess
 * @subpackage WebServices
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 *
 * ----------------------------------------------------------------------
 */

 /**
  *
  */
  
require_once(__CA_LIB_DIR__."/ca/Service/BrowseService.php");
require_once(__CA_LIB_DIR__."/core/Datamodel.php");

class AlhalqaBrowseService extends BrowseService {

	public function __construct($po_request,$ps_table="") {
		parent::__construct($po_request,$ps_table);
	}
	# -------------------------------------------------------


	protected function getBrowseResults() {
		$va_return = parent::getBrowseResults();
		if(($this->getTableName() == 'ca_objects') && is_array($va_return['results']) && sizeof($va_return['results'])>0) {

			foreach($va_return['results'] as &$va_result) {
				$t_object = new ca_objects($va_result['object_id']);
				if(!$t_object->getPrimaryKey()) { continue; }

				// include number of 'likes' (comments)
				$va_result['likes'] = (int) $t_object->getNumComments(null);

				// include urls for reference img
				$va_objects = $t_object->getRelatedItems('ca_objects', array('restrictToRelationshipTypes' => 'reference'));
				if(!is_array($va_objects) || sizeof($va_objects) != 1) { continue; }

				$va_object = array_shift($va_objects);
				$t_rel_object = new ca_objects($va_object['object_id']);
				$va_rep = $t_rel_object->getPrimaryRepresentation(array('preview170', 'medium', 'alhalqa1000', 'alhalqa2000'));
				if(!is_array($va_rep) || !is_array($va_rep['urls'])) { continue; }

				$va_result['reference_image_urls'] = $va_rep['urls'];
			}
		}

		return $va_return;
	}

}
