<?php

/**
 * Copyright � 2012 The Regents of the University of California
 *
 * The Unified Digital Format Registry (UDFR) is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * OntoWiki resource controller.
 *
 * @package    application
 * @subpackage mvc
 * @copyright  Copyright (c) 2010, {@link http://aksw.org AKSW}
 * @license    http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */
class ResourceController extends OntoWiki_Controller_Base {
    private function _addLastModifiedHeader() {
        $r = $this->_owApp->selectedResource;
        $m = $this->_owApp->selectedModel;

        if (!$m || !$r) {
            return;
        }

        $versioning   = Erfurt_App::getInstance()->getVersioning();
        $lastModArray = $versioning->getLastModifiedForResource($r, $m->getModelUri());

        if (null === $lastModArray || !is_numeric($lastModArray['tstamp'])) {
            return;
        }

        $response = $this->getResponse();
        $response->setHeader('Last-Modified', date('r', $lastModArray['tstamp']), true);
    }
    
    // UDFR - Abhi - Check setup for table exist or not if no create it
	private function _checkSetup() {
        $this->_initialize();
    } 

    private function _initialize()
    {
    	$getstore = Erfurt_App::getInstance()->getStore();
        
        if (!$getstore->isSqlSupported()) {
            throw new Exception('For create table store adapter needs to implement the SQL interface.');
        }
        
        $existingTableNames = $getstore->listTables();
		
        if (!in_array('ef_reviews', $existingTableNames)) {
            $columnSpec = array(
                'id'          => 'INT PRIMARY KEY AUTO_INCREMENT',
                'model'       => 'VARCHAR(255) NOT NULL',
                's'     => 'VARCHAR(255) NOT NULL',
                'p'    => 'VARCHAR(255) NOT NULL',
                'o'      => 'VARCHAR(255) NOT NULL',
                'review_flag' => 'INT NOT NULL'
                );
            
            $getstore->createTable('ef_reviews', $columnSpec);
        }
    }

    /**
     * Displays all preoperties and values for a resource, denoted by parameter
     */
    public function propertiesAction() {
    	
    	$this->_checkSetup();
        $this->_addLastModifiedHeader();

        $store      = $this->_owApp->erfurt->getStore();
        $graph      = $this->_owApp->selectedModel;
        $resource   = $this->_owApp->selectedResource;
        $navigation = $this->_owApp->navigation;
        $translate  = $this->_owApp->translate;

        // add export formats to resource menu
        $resourceMenu = OntoWiki_Menu_Registry::getInstance()->getMenu('resource');
        foreach (array_reverse(Erfurt_Syntax_RdfSerializer::getSupportedFormats()) as $key => $format) {
            $resourceMenu->prependEntry(
                    'Export Resource as ' . $format,
                    $this->_config->urlBase . 'resource/export/f/' . $key . '?r=' . urlencode($resource)
            );
        }

        $menu = new OntoWiki_Menu();
        $menu->setEntry('Resource', $resourceMenu);

        $event = new Erfurt_Event('onCreateMenu');
        $event->menu = $resourceMenu;
        $event->resource = $this->_owApp->selectedResource;
        $event->model = $this->_owApp->selectedModel;
        $event->trigger();

        $event = new Erfurt_Event('onPropertiesAction');
        $event->uri = (string)$resource;
        $event->graph = $this->_owApp->selectedModel->getModelUri();
        $event->trigger();

        // Give plugins a chance to add entries to the menu
        $this->view->placeholder('main.window.menu')->set($menu->toArray(false, true));

        $title = $resource->getTitle($this->_config->languages->locale) 
               ? $resource->getTitle($this->_config->languages->locale) 
               : OntoWiki_Utils::contractNamespace((string)$resource);
        $windowTitle = sprintf($translate->_('Properties of %1$s'), $title);
        $this->view->placeholder('main.window.title')->set($windowTitle);

        if (!empty($resource)) {
            $event = new Erfurt_Event('onPreTabsContentAction');
            $event->uri = (string)$resource;
            $result = $event->trigger();

            if ($result) {
                $this->view->preTabsContent = $result;
            }

            $event = new Erfurt_Event('onPrePropertiesContentAction');
            $event->uri = (string)$resource;
            $result = $event->trigger();

            if ($result) {
                $this->view->prePropertiesContent = $result;
            }

            $model = new OntoWiki_Model_Resource($store, $graph, (string)$resource);
            $values = $model->getValues();
            $predicates = $model->getPredicates();

            // new trigger onPropertiesActionData to work with data (reorder with plugin)
            $event = new Erfurt_Event('onPropertiesActionData');
            $event->uri         = (string)$resource;
            $event->predicates  = $predicates;
            $event->values      = $values;
            $result = $event->trigger();

            if ( $result ) {
                $predicates = $event->predicates;
                $values     = $event->values;
            }

            $titleHelper = new OntoWiki_Model_TitleHelper($graph);
            // add graphs
            $graphs = array_keys($predicates);
            $titleHelper->addResources($graphs);

            // set RDFa widgets update info for editable graphs and other graph info
            $graphInfo = array();
            $editableFlags = array();
            foreach ($graphs as $g) {
                $graphInfo[$g] = $titleHelper->getTitle($g, $this->_config->languages->locale);

                if ($this->_erfurt->getAc()->isModelAllowed('edit', $g)) {
                    $editableFlags[$g] = true;
                    $this->view->placeholder('update')->append(array(
                        'sourceGraph'    => $g,
                        'queryEndpoint'  => $this->_config->urlBase . 'sparql/',
                        'updateEndpoint' => $this->_config->urlBase . 'update/'
                    ));
                } else {
                    $editableFlags[$g] = false;
                }
            }
            
            $this->view->graphs        = $graphInfo;
            $this->view->editableFlags = $editableFlags;
            $this->view->values        = $values;
            $this->view->predicates    = $predicates;
            $this->view->resourceUri   = (string)$resource;
            $this->view->graphUri      = $graph->getModelIri();
            $this->view->graphBaseUri  = $graph->getBaseIri();
            $this->view->editable = false; // use $this->editableFlags[$graph] now
            // prepare namespaces
            $namespaces = $graph->getNamespaces();
            $graphBase  = $graph->getBaseUri();
            // Abhi - Review image validators
	    	$this->view->store         = $store;
	    	$this->view->url           = $this->_config->staticUrlBase;
            if (!array_key_exists($graphBase, $namespaces)) {
                $namespaces = array_merge($namespaces, array($graphBase => OntoWiki_Utils::DEFAULT_BASE));
            }
            $this->view->namespaces = $namespaces;
        }
		$models = array_keys($this->_owApp->erfurt->getStore()->getAvailableModels(true));
        $isModel = in_array($resource, $models);
		$checkClass = $this->_checkClass();
		$wordType = 0;
		$this->view->isModel = $isModel;
		$this->view->checkClass = $checkClass;
		if (!$checkClass && !$isModel) { //UDFR - Abhi - If selected resource is not a class then show buttons
        $query = Erfurt_Sparql_SimpleQuery::initWithString(
					'SELECT distinct ?cl 
					 FROM <' . (string)$this->_owApp->selectedModel . '>
					 WHERE {
						<' . $resource . '>  <http://www.w3.org/1999/02/22-rdf-syntax-ns#type> ?cl .    
					 } ORDER BY ASC(?cl) LIMIT 2'
					);
		$cl = $this->_owApp->erfurt->getStore()->sparqlQuery($query);
		$myClass = $cl[0]['cl'];
		if (strrchr($myClass, "#")){
			$search = strrchr($myClass, "#");
			$wordType = preg_match("/Type/i", $search);
		}
		else if (strrchr($myClass, "/")) {
			$search = strrchr($myClass, "/");
			$wordType = preg_match("/Type/i", $search);
		}
		$this->view->wordType = $wordType;
		if (!$wordType) {
		$toolbar = $this->_owApp->toolbar;
        
        /*UDFR- Abhi- Add 'Review' button if Reviewer Action is granted */
		if ($this->_erfurt->getAc()->isActionAllowed('Review')) {
            $this->view->review = true;
            // adding submit button for review-action
	    	$toolbar->appendButton(
			OntoWiki_Toolbar::SUBMIT, 
			array('name' => 'Review', 'id' => 'resource-review')
			);
		}
		else {
            $this->view->review = false;
        }
        
		//UDFR- Abhi- view variables for review action
		$reviewurl = $this->_config->urlBase . 'resource/review';
        $this->view->placeholder('main.window.title')->set($windowTitle);
        $this->view->formActionUrl = (string) $reviewurl;
        $this->view->formMethod    = 'post';
        $this->view->formName      = 'resource-review';
        $this->view->formEncoding  = 'multipart/form-data';
			
        // show only if not forwarded and if model is writeable
        // TODO: why is isEditable not false here?
        if ($this->_request->getParam('action') == 'properties' && $graph->isEditable() &&
                $this->_owApp->erfurt->getAc()->isModelAllowed('edit', $this->_owApp->selectedModel)
        ) {
            // TODO: check acl
			$toolbar->appendButton(OntoWiki_Toolbar::EDITADD, array(
                'name'  => 'Clone',
                'class' => 'clone-resource'
            ));
			
					
				$toolbar->appendButton(OntoWiki_Toolbar::EDIT, array('name' => 'Edit Properties'));
				$params = array(
                    'name' => 'Delete',
                    'url'  => 'javascript:deleteResource(\''.(string) $resource.'\')'
				);
				$toolbar->appendButton(OntoWiki_Toolbar::SEPARATOR)
                    ->appendButton(OntoWiki_Toolbar::DELETE, $params);
			
            
            // ->appendButton(OntoWiki_Toolbar::EDITADD, array('name' => 'Add Property', 'class' => 'property-add'));
            
			/* UDFR - Abhi - not a requirement
            $toolbar->prependButton(OntoWiki_Toolbar::SEPARATOR)
                    ->prependButton(OntoWiki_Toolbar::ADD, array('name' => 'Add Property', '+class' => 'property-add'));
			*/
            $toolbar->prependButton(OntoWiki_Toolbar::SEPARATOR)
                    ->prependButton(OntoWiki_Toolbar::CANCEL, array('+class' => 'hidden'))
                    ->prependButton(OntoWiki_Toolbar::SAVE, array('+class' => 'hidden'));
        }

        // let plug-ins add buttons
        $toolbarEvent = new Erfurt_Event('onCreateToolbar');
        $toolbarEvent->resource = (string)$resource;
        $toolbarEvent->graph    = (string)$graph;
        $toolbarEvent->toolbar  = $toolbar;
        $eventResult = $toolbarEvent->trigger();
        
        if ($eventResult instanceof OntoWiki_Toolbar) {
            $toolbar = $eventResult;
        }
        
        // add toolbar
        $this->view->placeholder('main.window.toolbar')->set($toolbar);

        //show modules
        $this->addModuleContext('main.window.properties');
		}
		} else {
			//UDFR - Abhi - do not show any button && set wordType variable for edit icon
			$this->view->wordType = $wordType;
		}
    }

    /**
     * UDFR- Abhi - Stores reviewed properties in to ef_reviews table
     * 	            Stores review actions in to ef_versioning_action table
     */
    public function reviewAction() {
		$flag=1;
		$resource   = $this->_owApp->selectedResource;
		$redirectUrl = $this->_config->urlBase . 'resource/properties/?r=' . urlencode($resource);
		$redirect  = $this->_request->getParam('redirect', $redirectUrl);
		$store      = $this->_owApp->erfurt->getStore();
    	$graph      = $this->_owApp->selectedModel;
		$modelIri = $graph->getModelIri();
		$baseIri  = $graph->getBaseIri();
		$resourceUri   = (string)$resource;
		$model = new OntoWiki_Model_Resource($store, $graph, (string)$resource);
        $values = $model->getValues();
		$predicates = $model->getPredicates();
		
        // get versioning
        $versioning = $this->_erfurt->getVersioning();
		
      	if($_POST['property_review']){

		foreach($_POST['property_review'] as $val) {

	    foreach ($predicates as $graph => $predicatesForGraph) { 

	    	foreach ($predicatesForGraph as $uri => $predicate) {
		    	$currentPredicate = $predicates[$graph][$uri];  
		    	
		    	foreach ($values[$graph][$uri] as $entry) {
					if ($currentPredicate['uri']===$val) {
				    				    
	    			    $event = new Erfurt_Event('onReviewStatement');
			            $event->graphUri   = $modelIri;
			            if ($entry['uri']===NULL) {
				      		$actionsSql = 'INSERT INTO ef_reviews (model, s, p, o, review_flag) VALUES (\''. addslashes($modelIri) . '\', \'' . addslashes($resourceUri) . '\', \'' . addslashes($val) . '\', \'' 
						   . $entry['object'] . '\', ' . (int)$flag .  ')' ;
				       
	    			      $store->sqlQuery($actionsSql);
							$event->statement = array(
									'subject'   => $resourceUri,
					            	'predicate' => $val,
					            	'object'    => array('value'    => $entry['object'], 'type'     => 'literal', 'lang'=> 'en')
					         		);
				    	}
				    	else {
							$actionsSql = 'INSERT INTO ef_reviews (model, s, p, o, review_flag) VALUES (\''. addslashes($modelIri) . '\', \'' .addslashes($resourceUri) . '\', \'' . addslashes($val) . '\', \'' 
						     . $entry['uri'] . '\', ' . (int)$flag .  ')' ;
							
	    			        $store->sqlQuery($actionsSql);
					  		$event->statement = array(
									'subject'   => $resourceUri,
					            	'predicate' => $val,
					            	'object'    => array('value'    => $entry['uri'], 'type'     => 'uri')
					         		);
				    	}
			            $event->trigger();
					}
		    	}
	        }
    	}
	    $countReview++;
		} 

	$message = $countReview
                    . ' Statement'. ($countReview != 1 ? 's': '')
                    . ($countReview ? ' successfully' : '')
                    . ' reviewed.';

    $this->_owApp->appendMessage(
                    new OntoWiki_Message($message, OntoWiki_Message::SUCCESS));

	$this->_redirect($redirect, array('code' => 302));
      }
      else { 
		$message = 'No statement was selected. Please select statement(s) for review';

        	$this->_owApp->appendMessage(
                    new OntoWiki_Message($message, OntoWiki_Message::ERROR));
		$this->_redirect($redirect, array('code' => 302));
      }
    }  
    
    /**
     * Displays resources of a certain type and property values that have
     * been selected by the user.
     */
    public function instancesAction() {
        $store       = $this->_owApp->erfurt->getStore();
        $graph       = $this->_owApp->selectedModel;

        // the list is managed by a controller plugin that catches special http-parameters
        // in Ontowiki/Controller/Plugin/ListSetupHelper.php
        
        //here this list is added to the view
        $listHelper = Zend_Controller_Action_HelperBroker::getStaticHelper('List');
        $listName = 'instances';
        if($listHelper->listExists($listName)){
            $list = $listHelper->getList($listName);
			
			// UDFR - ABHI - sort the list of instance by their label
			$list->setOrderProperty("http://www.w3.org/2000/01/rdf-schema#label");
			
            $listHelper->addList($listName, $list, $this->view);
        } else {
            if($this->_owApp->selectedModel == null){
                $this->_owApp->appendMessage(new OntoWiki_Message('your session timed out. select a model',  OntoWiki_Message::ERROR));
                $this->_redirect($this->_config->baseUrl);
            }
            $list = new OntoWiki_Model_Instances($store, $this->_owApp->selectedModel, array());
            $listHelper->addListPermanently($listName, $list, $this->view);
        }
        
        //two usefull order
        //$list->orderByUri();
        //$list->setOrderProperty('http://ns.ontowiki.net/SysOnt/order');
        
        //begin view building
        $this->view->placeholder('main.window.title')->set('Resource List');

        // TODO: check acl
        // build toolbar
        /*
         * toolbar disabled for 0.9.5 (reactived hopefully later :) ) */
		/* //UDFR -Abhi - Do not add any button like Add Instance here
            if ($graph->isEditable()) {
                $toolbar = $this->_owApp->toolbar;
                $toolbar->appendButton(OntoWiki_Toolbar::EDITADD, array('name' => 'Add Instance', 'class' => 'init-resource'));
                        // ->appendButton(OntoWiki_Toolbar::EDIT, array('name' => 'Edit Instances', 'class' => 'edit-enable'))
                        // ->appendButton(OntoWiki_Toolbar::SEPARATOR)
                        // ->appendButton(OntoWiki_Toolbar::DELETE, array('name' => 'Delete Selected', 'class' => 'submit'))
                        // ->prependButton(OntoWiki_Toolbar::SEPARATOR)
                        // ->prependButton(OntoWiki_Toolbar::CANCEL)
                        // ->prependButton(OntoWiki_Toolbar::SAVE);
                $this->view->placeholder('main.window.toolbar')->set($toolbar);
            }
        
            
            $url = new OntoWiki_Url(
                array(
                    'controller' => 'resource',
                    'action' => 'delete'
                ),
                array()
            );
            
            $this->view->formActionUrl = (string)$url;
            $this->view->formMethod    = 'post';
            $this->view->formName      = 'instancelist';
            $this->view->formEncoding  = 'multipart/form-data';
            *
        */

        $url = new OntoWiki_Url();
        $this->view->redirectUrl = (string)$url;

        $this->addModuleContext('main.window.list');
        $this->addModuleContext('main.window.instances');
    }

    /**
     * Deletes one or more resources denoted by param 'r'
     * TODO: This should be done by a evolution pattern in the future
     */
    public function deleteAction() {
        $this->view->clearModuleCache();

        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();

        $store     = $this->_erfurt->getStore();
        $model     = $this->_owApp->selectedModel;
        $modelIri  = (string) $model;
        $redirect  = $this->_request->getParam('redirect', $this->_config->urlBase);

        if (isset($this->_request->r)) {
            $resources = $this->_request->getParam('r', array());
        } else {
            throw new OntoWiki_Exception('Missing parameter r!');
            exit;
        }

        if (!is_array($resources)) {
            $resources = array($resources);
        }

        // get versioning
        $versioning = $this->_erfurt->getVersioning();

        $count = 0;
        if ($this->_erfurt->getAc()->isModelAllowed('edit', $modelIri)) {
            foreach ($resources as $resource) {

                # if we have only a nice uri, fill to full uri
                if (Zend_Uri::check($resource) == false) {
                    $resource = $model->getBaseIri() . $resource;
                }

                // action spec for versioning
                $actionSpec                 = array();
                $actionSpec['type']         = 130;
                $actionSpec['modeluri']     = $modelIri;
                $actionSpec['resourceuri']  = $resource;

                // starting action
                $versioning->startAction($actionSpec);

                $stmtArray = array();

                // query for all triples to delete them
                $sparqlQuery = new Erfurt_Sparql_SimpleQuery();
                $sparqlQuery->setProloguePart('SELECT ?p, ?o');
                $sparqlQuery->addFrom($modelIri);
                $sparqlQuery->setWherePart('{ <' . $resource . '> ?p ?o . }');

                $result = $store->sparqlQuery($sparqlQuery,array('result_format'=>'extended'));
                // transform them to statement array to be compatible with store methods
                foreach ($result['results']['bindings'] as $stmt) {
                    $stmtArray[$resource][$stmt['p']['value']][] = $stmt['o'];
                }

                $store->deleteMultipleStatements($modelIri, $stmtArray);

                // stopping action
                $versioning->endAction();

                $count++;
            }

            $message = $count
                    . ' resource'. ($count != 1 ? 's': '')
                    . ($count ? ' successfully' : '')
                    . ' deleted.';

            $this->_owApp->appendMessage(
                    new OntoWiki_Message($message, OntoWiki_Message::SUCCESS)
            );

        } else {

            $message = 'not allowed.';

            $this->_owApp->appendMessage(
                    new OntoWiki_Message($message, OntoWiki_Message::WARNING)
            );
        }

        $event = new Erfurt_Event('onDeleteResources');
        $event->resourceArray = $resources;
        $event->modelUri = $modelIri;
        $event->trigger();


        $this->_redirect($redirect, array('code' => 302));
    }

    public function exportAction() {
        $this->_addLastModifiedHeader();

        $this->_helper->viewRenderer->setNoRender();
        $this->_helper->layout->disableLayout();

        if (isset($this->_request->m)) {
            $modelUri = $this->_request->m;
        } else if (isset($this->_owApp->selectedModel)) {
            $modelUri = $this->_owApp->selectedModel->getModelUri();
        } else {
            $response = $this->getResponse();
            $response->setRawHeader('HTTP/1.0 400 Bad Request');
            $response->sendResponse();
            throw new OntoWiki_Controller_Exception("No nodel given.");
            exit;
        }

        $resource = $this->getParam('r', true);

        // Check whether the f parameter is given. If not: default to rdf/xml
        if (!isset($this->_request->f)) {
            $format = 'rdfxml';
        } else {
            $format = $this->_request->f;
        }

        $format = Erfurt_Syntax_RdfSerializer::normalizeFormat($format);

        $store = $this->_erfurt->getStore();

        // Check whether given format is supported. If not: 400 Bad Request.
        if (!in_array($format, array_keys(Erfurt_Syntax_RdfSerializer::getSupportedFormats()))) {
            $response = $this->getResponse();
            $response->setRawHeader('HTTP/1.0 400 Bad Request');
            $response->sendResponse();
            throw new OntoWiki_Controller_Exception("Format '$format' not supported.");
            exit;
        }

        // Check whether model exists. If not: 404 Not Found.
        if (!$store->isModelAvailable($modelUri, false)) {
            $response = $this->getResponse();
            $response->setRawHeader('HTTP/1.0 404 Not Found');
            $response->sendResponse();
            throw new OntoWiki_Controller_Exception("Model '$modelUri' not found.");
            exit;
        }

        // Check whether model is available (with acl). If not: 403 Forbidden.
        if (!$store->isModelAvailable($modelUri)) {
            $response = $this->getResponse();
            $response->setRawHeader('HTTP/1.0 403 Forbidden');
            $response->sendResponse();
            throw new OntoWiki_Controller_Exception("Model '$modelUri' not available.");
            exit;
        }

        $filename = 'export' . date('Y-m-d_Hi');

        switch ($format) {
            case 'rdfxml':
                $contentType = 'application/rdf+xml';
                $filename .= '.rdf';
                break;
            case 'rdfn3':
                $contentType = 'text/rdf+n3';
                $filename .= '.n3';
                break;
            case 'rdfjson':
                $contentType = 'application/json';
                $filename .= '.json';
                break;
            case 'turtle':
                $contentType = 'application/x-turtle';
                $filename .= '.ttl';
                break;
        }

        $additional = array();
        if ((isset($this->_request->provenance) && (boolean)$this->_request->provenance)) {
            $bNodeCounter = 1;

            $model = $store->getModel($modelUri);

            $fileUri = 'http://' . $_SERVER['HTTP_HOST'] . $this->_request->getRequestUri();
            $curBNode = '_:node' . $bNodeCounter++;
            $additional[$fileUri] = array(
                    EF_RDF_TYPE => array(array(
                                    'value' => 'http://purl.org/net/provenance/ns#DataItem',
                                    'type' => 'uri'
                            )),
                    'http://purl.org/net/provenance/ns#createdBy' => array(array(
                                    'value' => $curBNode,
                                    'type' => 'bnode'
                            ))
            );
            $additional[$curBNode] = array(
                    EF_RDF_TYPE => array(array(
                                    'value' => 'http://purl.org/net/provenance/ns#DataCreation',
                                    'type' => 'uri'
                            )),
                    'http://purl.org/net/provenance/ns#performedAt' => array(array(
                                    'type' => 'literal',
                                    'value' => date('c'),
                                    'datatype' => EF_XSD_DATETIME
                            )),
                    'http://purl.org/net/provenance/ns#performedBy' => array(array(
                                    'value' => '_:node'.(++$bNodeCounter),
                                    'type' => 'bnode'
                            ))
            );
            $curBNode = '_:node'.$bNodeCounter++;

            $additional[$curBNode] = array(
                    EF_RDF_TYPE => array(array(
                                    'value' => 'http://purl.org/net/provenance/types#DataCreatingService',
                                    'type' => 'uri'
                            )),
                    'http://www.w3.org/2000/01/rdf-schema#comment' => array(array(
                                    'type' => 'literal',
                                    'value' => 'OntoWiki v0.95 (http://ontowiki.net)'
                            ))
            );

            $s = $resource;
            $operatorUri = $model->getOption('http://purl.org/net/provenance/ns#operatedBy');
            if ($operatorUri !== null) {
                $additional[$s] = array(
                        'http://purl.org/net/provenance/ns#operatedBy' => array(array(
                                        'type' => 'uri',
                                        'value' => $operatorUri[0]['value']
                                ))
                );
            } else {
                $additional[$s] = array();
            }

            $versioning = Erfurt_App::getInstance()->getVersioning();
            $history = $versioning->getHistoryForResource($resource, $modelUri);

            foreach ($history as $i=>$hItem) {
                $curBNode = '_:node' . $bNodeCounter++;

                $additional[$s]['http://purl.org/net/provenance/ns#CreatedBy'] = array(array(
                                'type' => 'bnode',
                                'value' => $curBNode
                ));

                $additional[$curBNode] = array(
                        EF_RDF_TYPE => array(array(
                                        'type' => 'uri',
                                        'value' => 'http://purl.org/net/provenance/ns#DataCreation'
                                )),
                        'http://purl.org/net/provenance/ns#performedAt' => array(array(
                                        'type' => 'literal',
                                        'value' => date('c', $hItem['tstamp']),
                                        'datatype' => EF_XSD_DATETIME
                                )),
                        'http://purl.org/net/provenance/ns#performedBy' => array(array(
                                        'type' => 'uri',
                                        'value' => $hItem['useruri']
                                ))
                );

                if ($i<(count($history)-1)) {
                    $additional[$curBNode]['http://purl.org/net/provenance/ns#precededBy'] = array(array(
                                    'type' => 'bnode',
                                    'value' => '_:node' . ($bNodeCounter+1)
                    ));
                }

                $s = '_:node'.$bNodeCounter++;
            }
        }

        // Event
        $event = new Erfurt_Event('beforeExportResource');
        $event->resource = $resource;
        $event->modelUri = $modelUri;
        $additional2 = $event->trigger();

        if (is_array($additional2)) {
            $additional = array_merge($additional, $additional2);
        }

        $response = $this->getResponse();
        $response->setHeader('Content-Type', $contentType, true);
        $response->setHeader('Content-Disposition', ('filename="'.$filename.'"'));

        $serializer = Erfurt_Syntax_RdfSerializer::rdfSerializerWithFormat($format);
        echo $serializer->serializeResourceToString($resource, $modelUri, false, true, $additional);
        $response->sendResponse();
        exit;
    }
	
	// UDFR - Abhi redirect task
	public function selectAction() {
		if (isset($this->_request->m) && isset($this->_request->r)) {            
            // reset resource/class
            unset($this->_owApp->selectedResource);
            unset($this->_owApp->selectedClass);
            unset($this->_session->hierarchyOpen);
            
            OntoWiki_Navigation::disableNavigation();
			
			$this->_owApp->selectedResource = new OntoWiki_Resource($this->_request->getParam('m'), $this->_owApp->selectedModel);
			$this->_redirect($this->_config->urlBase . 'resource/properties/?r=' .$this->_request->getParam('r') , array('code' => 302));
		} 

	}

	//UDFR - Abhi - check if selected resource is class or instance
	private function _checkClass() {
		$resource   = $this->_owApp->selectedResource;
		$query = Erfurt_Sparql_SimpleQuery::initWithString(
                    'SELECT * 
                     FROM <' . (string)$this->_owApp->selectedModel . '> 
                     WHERE {
                        <' . $resource . '> a ?type  .  
                     }'
                );
		$results[] = $this->_owApp->erfurt->getStore()->sparqlQuery($query);

		$query = Erfurt_Sparql_SimpleQuery::initWithString(
			'SELECT * 
			 FROM <' . (string)$this->_owApp->selectedModel . '>
			 WHERE {
				?inst a <' . $resource . '> .    
			 } LIMIT 2'
		);

		if ( sizeof($this->_owApp->erfurt->getStore()->sparqlQuery($query)) > 0 ) {
			$hasInstances = true;
		} else {
			$hasInstances = false;
		}
		$typeArray = array();
		foreach ($results[0] as $row) {
			$typeArray[] = $row['type'];
		}
		if (in_array(EF_RDFS_CLASS, $typeArray) ||
			in_array(EF_OWL_CLASS, $typeArray)  ||
			$hasInstances
		) {
			return true;
		} else return false;
    }
}
