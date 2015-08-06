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


 	class PublicationController extends ActionController {
 		# -------------------------------------------------------
 		protected $opa_locales;
 		protected $pa_parameters;

 		# -------------------------------------------------------
 		# Constructor
 		# -------------------------------------------------------

 		public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
 			parent::__construct($po_request, $po_response, $pa_view_paths);

 			/*if (!$this->request->user->canDoAction('can_use_lescollectionsfr_plugin')) {
 				$this->response->setRedirect($this->request->config->get('error_display_url').'/n/3500?r='.urlencode($this->request->getFullUrlPath()));
 				return;
 			}
 			*/
            $ps_plugin_path = __CA_APP_DIR__.'/plugins/lesCollections';
            if (file_exists("{$ps_plugin_path}/conf/local/lesCollections.conf")) {
                $vs_conf_path = "{$ps_plugin_path}/conf/local/lesCollections.conf";
            } elseif(file_exists("{$ps_plugin_path}/conf/local/lesCollections.conf")) {
                $vs_conf_path = "{$ps_plugin_path}//conf/local/lesCollections.conf";
            } else {
                return false;
            }
            $this->opo_config = Configuration::load($vs_conf_path);
 		}

        # -------------------------------------------------------
        # Save params to json file
        # -------------------------------------------------------
        private function _updateJson($params) {
            $vs_json_infos = json_encode(
                array(
                    "collectionname" => $params["collectionname"],
                    "collectionsubname" => $params["collectionsubname"],
                    "collectionintro" => $params["collectionintro"],
                    "loginrequis" => $params["loginrequis"],
                    "contactinformations" => $params["contactinformations"],
                    "copyright" => $params["copyright"],
                    "menublog" => $params["menublog"],
                    "menucollections" => $params["menucollections"],
                    "menugalerie" => $params["menugalerie"]
                )
            );
             $jsonFile = __CA_BASE_DIR__.$this->opo_config->get(pawtucketLesCollectionsJsonFile);
            if (!file_put_contents( $jsonFile, $vs_json_infos)) {
                return false;
            }
            return true;
        }
 		# -------------------------------------------------------
 		# Functions to render views
 		# -------------------------------------------------------
 		public function Index($type="") {
            // Test if we are inside an update
            if($this->request->getParameter("collectionname",pString) != "") {
                $va_params = array(
                    "collectionname" => $this->request->getParameter("collectionname",pString),
                    "collectionsubname" => $this->request->getParameter("collectionsubname",pString),
                    "collectionintro" => $this->request->getParameter("collectionintro",pString),
                    "loginrequis"=> $this->request->getParameter("loginrequis",pInteger),
                    "contactinformations"=> $this->request->getParameter("contactinformations",pString),
                    "copyright"=>$this->request->getParameter("copyright",pString),
                    "menublog"=>$this->request->getParameter("menublog",pString),
                    "menucollections"=>$this->request->getParameter("menucollections",pString),
                    "menugalerie"=>$this->request->getParameter("menugalerie",pString)
                );
                if (!$this->_updateJson($va_params)) {
                    $this->response->setRedirect($this->request->config->get('error_display_url').'/n/3500?r='.urlencode($this->request->getFullUrlPath()));
                };
                $this->view->setVar('saved', true);
            } else {
                $this->view->setVar('saved', false);
            }

            // Detecting if we have a relative path or a full qualified path to json file, when "/" is at the beginning of path
            $vs_pawtucketLesCollectionsJsonFile = $this->opo_config->get(pawtucketLesCollectionsJsonFile);
            if (!substr($vs_pawtucketLesCollectionsJsonFile,0,1)=='/') {
                $jsonFile = __CA_BASE_DIR__.$vs_pawtucketLesCollectionsJsonFile;
            } else {
                $jsonFile = $vs_pawtucketLesCollectionsJsonFile;
            };

            // Checking existing file
            if (!$vs_json_infos = file_get_contents($jsonFile)) {
                // Checking write rights
                if (!file_put_contents($jsonFile ,"\n")) {

                    // TODO : handling errors for non writable json file
                    var_dump($jsonFile);
                    die;
                 //   $this->response->setRedirect($this->request->config->get('error_display_url').'/n/3500?r='.urlencode($this->request->getFullUrlPath()));
                 //   return;
                }

               // $this->response->setRedirect($this->request->config->get('error_display_url').'/n/3500?r='.urlencode($this->request->getFullUrlPath()));
                //return;
            }
            $this->view->setVar('infos', json_decode($vs_json_infos));
			$this->render('publication_index_html.php');
 		}
 		# -------------------------------------------------------
 	}
 ?>
