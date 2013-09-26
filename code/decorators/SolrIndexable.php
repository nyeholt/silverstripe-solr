<?php
 
/**
 * A decorator that adds the ability to index a DataObject in Solr
 * 
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 *
 */
class SolrIndexable extends DataExtension {
	/**
	 * We might not want to index, eg during a data load
	 * 
	 * @var boolean
	 */
	public static $indexing = true;

	/**
	 * Should we index draft content too?
	 *
	 * @var boolean
	 */
	public static $index_draft = true;
	
	/**
	 * @var array
	 */
	public static $dependencies = array(
		'searchService'		=> '%$SolrSearchService',
	);
	
	public static $db = array(
		'ResultBoost'		=> 'Int',
	);
	
	protected function createIndexJob($item, $stage = null, $mode = 'index') {
		$job = new SolrIndexItemJob($item, $stage, $mode);
		Injector::inst()->get('QueuedJobService')->queueJob($job);
	}

	/**
	 * Index after publish
	 */
	function onAfterPublish() {
		if (!self::$indexing) return;

		if ($this->canShowInSearch()) {
			$this->searchService->index($this->owner, 'Live');
		}
	}

	public function onBeforeWrite() {
		parent::onBeforeWrite();
		
		// immediately unindex any stuff that shouldn't be show in search
		if ($this->owner->isChanged('ShowInSearch') && !$this->owner->ShowInSearch) {
			$this->searchService->unindex($this->owner);
		}
	}

	/**
	 * Index after every write; this lets us search on Draft data as well as live data
	 */
	public function onAfterWrite() {
		if (!self::$indexing) return;

		// No longer doing the 'ischanged' check to avoid problems with multivalue field NOT being indexed
//		$changes = $this->owner->getChangedFields(true, 2);
//		
//		if (count($changes)) {
			
			$stage = null;
			// if it's being written and a versionable, then save only in the draft
			// repository. 
			if ($this->owner->hasExtension('Versioned')) {
				$stage = 'Stage';
			}

			if ($this->canShowInSearch()) {
				$this->searchService->index($this->owner, $stage);
			}
//		}
	}
	
	public function canShowInSearch() {
		if ($this->owner->hasField('ShowInSearch')) {
			return $this->owner->ShowInSearch;
		}
		
		return true;
	}

	/**
	 * If unpublished, we delete from the index then reindex the 'stage' version of the 
	 * content
	 *
	 * @return 
	 */
	function onAfterUnpublish() {
		if (!self::$indexing) return;

		$this->searchService->unindex($this->owner);
		$this->searchService->index($this->owner, 'Stage');
	}

	function onAfterDelete() {
		if (!self::$indexing) return;
		$this->searchService->unindex($this->owner);
	}
}
