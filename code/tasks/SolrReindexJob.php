<?php

/**
 * A queued job used for reindexing content
 *
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 */
class SolrReindexJob extends AbstractQueuedJob {

	public function __construct() {

	}

	public function getTitle() {
		return "Reindex content in Solr";
	}

	/**
	 * Lets see how many pages we're re-indexing
	 */
	public function getJobType() {
		$query = 'SELECT COUNT(*) FROM "SiteTree"';
		$this->totalSteps = DB::query($query)->value();
		return $this->totalSteps > 100 ? QueuedJob::LARGE : QueuedJob::QUEUED;
	}

	public function setup() {
		$this->lastIndexedID = 0;
		$service = singleton('SolrSearchService');
		$service->getSolr()->deleteByQuery('*:*');
	}

	/**
	 * To process this job, we need to get the next page whose ID is the next greater than the last
	 * processed. This way we don't need to remember a bunch of data about what we've processed
	 */
	public function process() {
		if (ClassInfo::exists('Subsite')) {
			Subsite::disable_subsite_filter();
		}
		$page = DataObject::get_one('SiteTree', singleton('SolrUtils')->dbQuote(array('SiteTree.ID >' => $this->lastIndexedID)), true, 'ID ASC');
		if (ClassInfo::exists('Subsite')) {
			Subsite::$disable_subsite_filter = false;
		}

		if (!$page || !$page->exists()) {
			$this->isComplete = true;
			return;
		}

		// index away
		$service = singleton('SolrSearchService');
		$service->index($page);

		$this->currentStep++;

		$this->lastIndexedID = $page->ID;

	}
}
