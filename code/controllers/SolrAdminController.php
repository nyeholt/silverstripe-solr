<?php

/**
 * Controller for backend management of solr search configs
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SolrAdminController extends ModelAdmin {
	public static $menu_title = 'Solr';
	public static $url_segment = 'solr';
	
	public static $managed_models = array(
		'SolrTypeConfiguration'
	);

	public static $allowed_actions = array(
		'ReindexForm',
		'EditForm',
	);
	
	public static $dependencies = array(
		'searchService' => '%$SolrSearchService',
	);

	public function init() {
		parent::init();
		
		Requirements::javascript('solr/javascript/solr.js');
	}
	
	/**
	 *
	 * @param SS_Request $request
	 * @return Form 
	 */
	public function getEditForm($id = null, $fields = null) {
		$form = parent::getEditForm($id, $fields);
		
		
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
//			return $form;
		}
		
		if (!Permission::check('ADMIN')) {
			return $form;
		}

		$fields = $form->Fields();

		$config = $this->searchService->localEngineConfig();
		$allow = $config->RunLocal;
		
		$fields->push(new CheckboxField('RunLocal', _t('SolrAdmin.RUN_LOCAL', 'Run local Jetty instance of Solr?'), $allow));
		
		if ($allow) {
			$status = $this->searchService->localEngineStatus();

			if (!$status) {
				$fields->push(new CheckboxField('Start', _t('SolrAdmin.START', 'Start Solr')));
			} else {
				$fields->push(new CheckboxField('Kill', _t('SolrAdmin.Kill', 'Kill Solr process (' . $status . ')')));
			}

			$log = $this->searchService->getLogData(100);
			$log = array_reverse($log);
			
			$fields->push($logtxt = new TextareaField('Log', _t('SolrAdmin.LOG', 'Log')));
			
			$logtxt->setColumns(20)->setRows(15)->setValue(implode($log));
		}

		
		$form->Actions()->push(new FormAction('saveconfig', _t('SolrAdmin.SAVE', 'Save')));
//		$actions = new FieldSet();
//		$form = new Form($this, 'EditForm', $fields, $actions);
		return $form;
	}

	public function saveconfig($data, $form, $request) {
		if (!Permission::check('ADMIN')) {
			return false;
		}

		$config = $this->searchService->localEngineConfig();
		$config->RunLocal = $data['RunLocal'];
		$this->searchService->saveEngineConfig($config);
		
		if (isset($data['Start']) && $data['Start']) {
			$this->searchService->startSolr();
			sleep(2);
		} else if (isset($data['Kill']) && $data['Kill']) {
			$this->searchService->stopSolr();
			sleep(2);
		}
		if (Director::is_ajax()) {
			return $this->getResponseNegotiator()->respond($this->request);
		} else {
			$this->redirectBack();
		}
		
	}
}
//
//class SolrAdmin_CollectionController extends ModelAdmin_CollectionController {
//	
//	/**
//	 * Get a combination of the Search, Import and Create and Reindex
//	 *
//	 * @return string
//	 */
//	public function getModelSidebar() {
//		return $this->renderWith('SolrAdminSidebar');
//	}
//	
//	public function ReindexForm() {
//		$fields = new FieldSet();
//		$actions = new FieldSet(new FormAction('reindex', _t('Solr.REINDEX_SYSTEM', 'Reindex Content')));
//		return new Form($this, 'ReindexForm', $fields, $actions);
//	}
//	
//	public function reindex($data, Form $form) {
//		$task = singleton('SolrReindexTask');
//		if ($task) {
//			$task->run($this->request);
//		}
//	}
//}