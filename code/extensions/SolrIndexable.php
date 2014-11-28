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
			$this->reindex('Live');
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
		//$changes = $this->owner->getChangedFields(true, 2);
			
		$stage = null;
		// if it's being written and a versionable, then save only in the draft
		// repository. 
		if ($this->owner->hasExtension('Versioned')) {
			$stage = 'Stage';
		}

		if ($this->canShowInSearch()) {
			$this->reindex($stage);
		}
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
		$this->reindex('Stage');
	}

	function onAfterDelete() {
		if (!self::$indexing) return;
		$this->searchService->unindex($this->owner);
	}

	/**
	 *	Index the current data object for a particular stage.
	 *	@param string
	 */

	public function reindex($stage = null) {

		// Make sure the current data object is not orphaned.

		if($this->owner->ParentID > 0) {
			$parent = $this->owner->Parent();
			if(is_null($parent) || ($parent === false)) {
				return;
			}
		}

		// Make sure the extension requirements have been met before enabling the custom site tree search index,
		// since this may impact performance.

		if((ClassInfo::baseDataClass($this->owner) === 'SiteTree')
			&& SiteTree::has_extension('SiteTreePermissionIndexExtension')
			&& SiteTree::has_extension('SolrSearchPermissionIndexExtension')
			&& ClassInfo::exists('QueuedJob')
		) {

			// Queue a job to handle the recursive indexing.
			$indexing = new SolrSiteTreePermissionIndexJob($this->owner, $stage);
			singleton('QueuedJobService')->queueJob($indexing);
		}
		else {

			// When the requirements haven't been met, trigger a normal index.
			$this->searchService->index($this->owner, $stage);
		}
	}

}
