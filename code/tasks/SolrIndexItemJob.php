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
		return DataObject::get_by_id($this->itemType, $this->itemID);
	}

	public function getTitle() {
		$mode = $this->mode == 'index' ? _t('Solr.INDEXING', 'Indexing') : _t('Solr.UNINDEXING', 'Unindexing');
		$stage = $this->stage == 'Live' ? 'Live' : 'Stage';
		return sprintf(_t('Solr.INDEX_ITEM_JOB', $mode . ' %s in stage '.$stage), $this->getItem()->Title);
	}

	public function getJobType() {
		return QueuedJob::IMMEDIATE;
	}

	public function process() {
		$item = $this->getItem();

		$method = $this->mode == 'index' ? 'index' : 'unindex';
		$stage = is_null($this->stage) ? null : ($this->stage == 'Stage' ? 'Stage' : 'Live');

		singleton('SolrSearchService')->$method($this->owner, $stage);

		$this->currentStep++;
		$this->isComplete = true;
	}
}
}