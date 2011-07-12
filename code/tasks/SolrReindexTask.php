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

	function run($request)
	{
		if (ClassInfo::exists('QueuedJob')) {
			$job = new SolrReindexJob();
			$svc = singleton('QueuedJobService');
			$svc->queueJob($job);
			echo "<p>Reindexing job has been queued</p>";
			return;
		}
		// get the holders first, see if we have any that AREN'T in the root (ie we've already partitioned everything...)
		$pages = DataObject::get('Page');

		$search = singleton('SolrSearchService');
		$search->getSolr()->deleteByQuery('ClassNameHierarchy_ms:Page');
		/* @var $search SolrSearchService */
		$count = 0;
		foreach ($pages as $page) {
			$search->index($page, 'Draft');
			if ($page->Status == 'Published') {
				$search->index($page, 'Live');
			}
			echo "<p>Reindexed (#$page->ID) $page->Title</p>\n";
			$count ++;
		}
		echo "Reindex complete, $count objects re-indexed<br/>";
	}
}