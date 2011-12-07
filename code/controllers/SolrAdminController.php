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
	
	public static $collection_controller_class = 'SolrAdmin_CollectionController';
	
	public function init() {
		parent::init();
		
		Requirements::javascript('solr/javascript/solr.js');
	}
	
	public function EditForm() {
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			return false;
		}
		
		if (!Permission::check('ADMIN')) {
			return false;
		}

		$fields = new FieldSet($ts = new TabSet('Root'));

		$config = singleton('SolrSearchService')->localEngineConfig();
		$allow = $config->RunLocal;
		
		$fields->addFieldToTab('Root.Content', new CheckboxField('RunLocal', _t('SolrAdmin.RUN_LOCAL', 'Run local Jetty instance of Solr?'), $allow));
		
		if ($allow) {
			$status = singleton('SolrSearchService')->localEngineStatus();

			if (!$status) {
				$fields->addFieldToTab('Root.Content', new CheckboxField('Start', _t('SolrAdmin.START', 'Start Solr')));
			} else {
				$fields->addFieldToTab('Root.Content', new CheckboxField('Kill', _t('SolrAdmin.Kill', 'Kill Solr process (' . $status . ')')));
			}

			$log = singleton('SolrSearchService')->getLogData(100);
			$log = array_reverse($log);
			
			$fields->addFieldToTab('Root.Content', $logtxt = new TextareaField('Log', _t('SolrAdmin.LOG', 'Log'), 15, 20, implode($log)));
		}

		$actions = new FieldSet(new FormAction('saveconfig', _t('SolrAdmin.SAVE', 'Save')));
		$form = new Form($this, 'EditForm', $fields, $actions);
		return $form;
	}

	public function saveconfig($data, $form, $request) {
		if (!Permission::check('ADMIN')) {
			return false;
		}

		$config = singleton('SolrSearchService')->localEngineConfig();
		$config->RunLocal = $data['RunLocal'];
		singleton('SolrSearchService')->saveEngineConfig($config);
		
		if (isset($data['Start']) && $data['Start']) {
			singleton('SolrSearchService')->startSolr();
		} else if (isset($data['Kill']) && $data['Kill']) {
			singleton('SolrSearchService')->stopSolr();
			sleep(2);
		}
		return $this->EditForm()->forAjaxTemplate();
	}
}

class SolrAdmin_CollectionController extends ModelAdmin_CollectionController {
	
	/**
	 * Get a combination of the Search, Import and Create and Reindex
	 *
	 * @return string
	 */
	public function getModelSidebar() {
		return $this->renderWith('SolrAdminSidebar');
	}
	
	public function ReindexForm() {
		$fields = new FieldSet();
		$actions = new FieldSet(new FormAction('reindex', _t('Solr.REINDEX_SYSTEM', 'Reindex Content')));
		return new Form($this, 'ReindexForm', $fields, $actions);
	}
	
	public function reindex($data, Form $form) {
		$task = singleton('SolrReindexTask');
		if ($task) {
			$task->run($this->request);
		}
	}
}