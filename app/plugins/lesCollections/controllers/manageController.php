<?php
/* ----------------------------------------------------------------------
 * plugins/statisticsViewer/controllers/StatisticsController.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2010 Whirl-i-Gig
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
 * ----------------------------------------------------------------------
 */

 	require_once(__CA_LIB_DIR__.'/core/TaskQueue.php');
 	require_once(__CA_LIB_DIR__.'/core/Configuration.php');
 	require_once(__CA_MODELS_DIR__.'/ca_lists.php');
 	require_once(__CA_MODELS_DIR__.'/ca_objects.php');
 	require_once(__CA_MODELS_DIR__.'/ca_object_representations.php');
 	require_once(__CA_MODELS_DIR__.'/ca_locales.php');
 	

 	class manageController extends ActionController {
 		# -------------------------------------------------------
 		protected $opa_locales;
 		protected $pa_parameters;

 		# -------------------------------------------------------
 		# Constructor
 		# -------------------------------------------------------

 		public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
 			parent::__construct($po_request, $po_response, $pa_view_paths);
 			/*
 			if (!$this->request->user->canDoAction('can_use_lescollectionsfr_plugin')) {
 				$this->response->setRedirect($this->request->config->get('error_display_url').'/n/3500?r='.urlencode($this->request->getFullUrlPath()));
 				return;
 			}
 			*/
 			$this->opo_config = Configuration::load(__CA_APP_DIR__.'/plugins/lesCollections/conf/lesCollections.conf');
 		}
 		 		
 		# -------------------------------------------------------
 		# Functions to render views
 		# -------------------------------------------------------
 		public function Index($type="") {
			$this->view->setVar('sample_var', "sample_var");
			$this->render('manage_index_html.php');
 		}

 		# ------------------------------------------------------- 				
 		public function Paiement($type="") {
			$this->view->setVar('sample_var', "sample_var");
			$this->render('manage_paiement_html.php');
 		}

 		# ------------------------------------------------------- 				
 	}
 ?>