<?php

/**
 * Reindex the entire content of the current system in the solr backend
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 */
class SolrReindexTask extends BuildTask
{
	protected $title = "Reindex all content within Solr";

	protected $description = "Iterates through all content within the system, re-indexing it in solr";
	
	protected $types = array();
	
	public function __construct($types = array()) {
		parent::__construct();
		$this->types = $types;
	}

	public function run($request) {
		increase_time_limit_to();

		$type = Convert::raw2sql($request->getVar('type'));

		if($type) {
			$this->types[] = $type;
		} elseif(!$this->types) {
			foreach(ClassInfo::subclassesFor('DataObject') as $class) {
				if($class::has_extension('SolrIndexable')) {
					$this->types[] = $class;
				}
			}
		}

		$search = singleton('SolrSearchService');
		
		if (isset($_GET['delete_all'])) {
			$search->getSolr()->deleteByQuery('*:*');
			$search->getSolr()->commit();
		}
		
		$count = 0;
		
		foreach ($this->types as $type) {
			$search->getSolr()->deleteByQuery('ClassNameHierarchy_ms:' . $type);
			$search->getSolr()->commit();
			
			if (ClassInfo::exists('QueuedJob') && !isset($_GET['direct'])) {
				$job = new SolrReindexJob($type);
				$svc = singleton('QueuedJobService');
				$svc->queueJob($job);
				echo "<p>Reindexing job for $type has been queued</p>";
			} else {

				$mode = Versioned::get_reading_mode();
				Versioned::reading_stage('Stage');

				// get the holders first, see if we have any that AREN'T in the root (ie we've already partitioned everything...)
				$pages = DataObject::get($type);

				/* @var $search SolrSearchService */

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
						$search->index($page, 'Stage');
						
						$baseTable = $page->baseTable();
						$live = Versioned::get_one_by_stage($page->ClassName, 'Live', "\"$baseTable\".\"ID\" = $page->ID");
						if ($live) {
							$search->index($live, 'Live');
							echo "<p>Reindexed Live version of $live->Title</p>\n";
						}

						echo "<p>Reindexed (#$page->ID) $page->Title</p>\n";
						$count ++;
					} else {
						$search->index($page);
						echo "<p>Reindexed $type ID#$page->ID</p>\n";
						$count ++;
					}
				}
				
				Versioned::set_reading_mode($mode);
			}
		}
		
		echo "Reindex complete, $count objects re-indexed<br/>";
	}
}
