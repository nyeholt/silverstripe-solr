<?php

/**
 * A job for indexing a piece of content as a background task
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
if (class_exists('AbstractQueuedJob')) {

class SolrIndexItemJob extends AbstractQueuedJob {
	public function __construct($itemToIndex = null, $stage = null, $mode='index') {

		if ($itemToIndex) {
			$this->itemType = $itemToIndex->class;
			$this->itemID = $itemToIndex->ID;

			$this->stage = $stage;
			$this->mode = $mode;

			$this->totalSteps = 1;
		}
	}

	protected function getItem() {
		if (ClassInfo::exists('Subsite')) {
			Subsite::disable_subsite_filter(true);
		}
		$item = DataObject::get_by_id($this->itemType, $this->itemID);
		if (ClassInfo::exists('Subsite')) {
			Subsite::disable_subsite_filter(false);
		}
		return $item;
	}

	public function getTitle() {
		$mode = $this->mode == 'index' ? _t('Solr.INDEXING', 'Indexing') : _t('Solr.UNINDEXING', 'Unindexing');
		$stage = $this->stage == 'Live' ? 'Live' : 'Stage';
		$item = $this->getItem();
		return sprintf(_t('Solr.INDEX_ITEM_JOB', $mode . ' "%s" in stage '.$stage), $item ? $item->Title : 'item #'.$this->itemID);
	}

	public function getJobType() {
		return QueuedJob::IMMEDIATE;
	}

	public function process() {
		$method = $this->mode == 'index' ? 'index' : 'unindex';
		$stage = is_null($this->stage) ? null : ($this->stage == 'Stage' ? 'Stage' : 'Live');

		if ($method == 'index') {
			$item = $this->getItem();
			if ($item) {
				singleton('SolrSearchService')->index($item, $stage);
			}
		} else if ($method == 'unindex') {
			// item has already been deleted by here, so need to use the stored type/id
			singleton('SolrSearchService')->unindex($this->itemType, $this->itemID);
		}

		$this->currentStep++;
		$this->isComplete = true;
	}
}
}