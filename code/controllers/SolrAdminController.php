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
	);
	
	public static $collection_controller_class = 'SolrAdmin_CollectionController';
	
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