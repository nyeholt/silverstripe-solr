<?php

/**
 * A queued job used for reindexing content
 *
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 */
if (class_exists('AbstractQueuedJob')) {
	class SolrReindexJob extends AbstractQueuedJob {

		static $at_a_time = 100;
		
		public function __construct($type = null) {
			if (!$type && isset($_GET['type'])) {
				$type = $_GET['type'];
			}
			if ($type) {
				$this->reindexType = $type;
			}
		}

		public function getTitle() {
			return "Reindex $this->reindexType content in Solr";
		}

		public function setup() {
			$this->lastIndexedID = 0;
			$service = singleton('SolrSearchService');
			$service->getSolr()->deleteByQuery('ClassNameHierarchy_ms:' . $this->reindexType);
		}

		/**
		 * To process this job, we need to get the next page whose ID is the next greater than the last
		 * processed. This way we don't need to remember a bunch of data about what we've processed
		 */
		public function process() {
			if (ClassInfo::exists('Subsite')) {
				Subsite::disable_subsite_filter();
			}

			$class = $this->reindexType;
			$pages = $class::get()->filter('ID:GreaterThan', $this->lastIndexedID)->sort('ID ASC')->limit('0, ' . self::$at_a_time);
			
			if (ClassInfo::exists('Subsite')) {
				Subsite::$disable_subsite_filter = false;
			}

			if (!$pages || !$pages->count()) {
				$this->isComplete = true;
				return;
			}
			
			$mode = Versioned::get_reading_mode();
			Versioned::reading_stage('Stage');

			// index away
			$service = singleton('SolrSearchService');
			
			$live = array();
			$stage = array();
			$all = array();
			
			foreach ($pages as $page) {

				// Make sure the current page is not orphaned.

				if($page->ParentID > 0) {
					$parent = $page->getParent();
					if(is_null($parent) || ($parent === false)) {
						continue;
					}
				}

				// Appropriately index the current page, taking versioning into account.

				if ($page->hasExtension('Versioned')) {
					$stage[] = $page;
					
					$base = $page->baseTable();
					$idField = '"' . $base . '_Live"."ID"';
					$livePage = Versioned::get_one_by_stage($page->ClassName, 'Live', $idField . ' = ' . $page->ID);

					if ($livePage) {
						$live[] = $livePage;
					}
				} else {
					$all[] = $page;
				}
				
				$this->lastIndexedID = $page->ID;
			}

			if (count($all)) {
				$service->indexMultiple($all);
			}
			
			if (count($stage)) {
				$service->indexMultiple($stage, 'Stage');
			}

			if (count($live)) {
				$service->indexMultiple($live, 'Live');
			}
			

			Versioned::set_reading_mode($mode);

			$this->lastIndexedID = $page->ID;
			$this->currentStep += $pages->count();
		}
	}
}
