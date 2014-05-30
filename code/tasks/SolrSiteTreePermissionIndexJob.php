<?php

/**
 *	Recursively indexes a site tree element and any children that inherit view permissions.
 *	@author Nathan Glasl <nathan@silverstripe.com.au>
 */

if(class_exists('AbstractQueuedJob')) {

	class SolrSiteTreePermissionIndexJob extends AbstractQueuedJob {

		public $searchService;
		private $pages;

		public function __construct($page = null, $stage = null) {

			if(!is_null($page)) {
				$this->setObject($page, 'page');

				// Keep a listing of the page IDs in case the job ends up being paused and we lose the current state.

				$this->pageIDs = array(
					$page->ID
				);
			}
			if(!is_null($stage)) {
				$this->stage = $stage;
			}
		}

		/**
		 *	Retrieve the job information using the top level site tree element.
		 *	@return string
		 */

		public function getTitle() {

			return "Recursively Indexing: {$this->pageType} {$this->pageID} on {$this->stage}";
		}

		/**
		 *	Instantiate the listing of site tree elements to index.
		 */

		public function setup() {

			$this->pages = array(
				$this->getObject('page')
			);
		}

		public function process() {

			// Retrieve the pages to index if the job ends up being paused.

			if(is_null($this->pages)) {
				if(is_array($this->pageIDs)) {
					$this->pages = SiteTree::get()->byIDs($this->pageIDs)->toArray();
				}
				else {
					$this->messages[] = 'The listing of site tree elements to index has become corrupt, aborting..';
					$this->isComplete = true;
					return $this->isComplete;
				}
			}

			// Retrieve the next page to index and remove it from the queue.

			$next = array_shift($this->pages);
			$pageIDs = $this->pageIDs;
			array_shift($pageIDs);
			if($next && $this->searchService) {

				// Retrieve any children that inherit view permissions, queueing them up.

				$children = $next->AllChildren();
				foreach($children as $child) {
					if($child->CanViewType === 'Inherit') {
						$this->pages[] = $child;
						$pageIDs[] = $child->ID;
					}
				}

				// Trigger an index for this site tree element and increment the current step.

				$this->searchService->index($next, $this->stage);
				$this->currentStep++;
			}
			$this->pageIDs = $pageIDs;

			// When there are no children remaining to index, mark the job as complete.

			if((count($this->pages) === 0) && (count($this->pageIDs) === 0)) {
				$this->isComplete = true;
			}
			return $this->isComplete;
		}

	}
}
