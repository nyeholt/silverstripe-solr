<?php

/**
 *	Migrate any old SolrSearch instances to ExtensibleSearchPage instances.
 *
 *	@author Nathan Glasl <nathan@silverstripe.com.au>
 */

class SolrSearchPageMigrationTask extends BuildTask {

	protected $title = "Solr Search Page Migration";

	protected $description = "Migrate any old SolrSearchPage instances to ExtensibleSearchPage instances.";

	public function run($request) {

		increase_time_limit_to();
		$readingMode = Versioned::get_reading_mode();
		Versioned::reading_stage('Stage');

		// Make sure that we have something to migrate.

		if(!ClassInfo::hasTable('SolrSearchPage')) {
			echo "Nothing to Migrate!";
			die;
		}

		// Retrieve the search tree relationships to migrate.

		$relationships = array();
		if (DB::getConn()->hasTable('SolrSearchPage_SearchTrees')) {
			foreach(DB::query('SELECT * FROM SolrSearchPage_SearchTrees') as $relationship) {
				$relationships[$relationship['SolrSearchPageID']] = $relationship['PageID'];
			}
		}
		

		// Store the current live page migration state to avoid duplicates.

		$created = array();

		// Migrate any live pages to begin with.

		$query = DB::query('SELECT * FROM SiteTree_Live st, SolrSearchPage_Live ssp WHERE st.ID = ssp.ID');
		$queryCount = $query->numRecords();
		$writeCount = 0;
		foreach($query as $results) {
			$searchPage = ExtensibleSearchPage::create();
			$searchPage->SearchEngine = 'SolrSearch';

			// Migrate the key site tree and solr search fields across.

			$fields = array('ParentID', 'URLSegment', 'Title', 
				'MenuTitle', 'Content', 'ShowInMenus', 'ShowInSearch', 'Sort', 
				'ResultsPerPage', 'SortBy', 'BoostFieldsValue', 'SearchOnFieldsValue', 'SearchTypeValue', 'StartWithListing', 'QueryType',
				'ListingTemplateID', 'FilterFieldsValue', 'MinFacetCount', 'FacetQueriesValue', 'FacetMappingValue', 'CustomFacetFieldsValue', 'FacetFieldsValue', 'BoostMatchFieldsValue',
				
			);
			
			foreach ($fields as $fname) {
				if (isset($results[$fname])) {
					$searchPage->$fname = $results[$fname];
				}
				
			}

			// This field name no longer matches the original.

			if($results['SortDir']) {
				$searchPage->SortDirection = $results['SortDir'];
			}
			
			if(isset($relationships[$results['ID']])) {
				$searchPage->SearchTrees()->add($relationships[$results['ID']]);
			}

			// Attempt to publish these new pages.

			$searchPage->doPublish();
			if($searchPage->ID) {
				echo "<strong>{$results['ID']}</strong> Published<br>";
				$writeCount++;
			}
			$created[] = $results['ID'];
		}

		// Confirm that the current user had permission to publish these new pages.

		$this->checkPermissions($queryCount, $writeCount);

		// Migrate any remaining draft pages.

		$query = DB::query('SELECT * FROM SiteTree st, SolrSearchPage ssp WHERE st.ID = ssp.ID');
		$queryCount = $query->numRecords();
		$writeCount = 0;
		foreach($query as $results) {

			// Make sure this search page doesn't already exist.

			if(!in_array($results['ID'], $created)) {
				$searchPage = ExtensibleSearchPage::create();
				$searchPage->SearchEngine = 'SolrSearch';

				// Migrate the key site tree and solr search fields across.

				$searchPage->ParentID = $results['ParentID'];
				$searchPage->URLSegment = $results['URLSegment'];
				$searchPage->Title = $results['Title'];
				$searchPage->MenuTitle = $results['MenuTitle'];
				$searchPage->Content = $results['Content'];
				$searchPage->ShowInMenus = $results['ShowInMenus'];
				$searchPage->ShowInSearch = $results['ShowInSearch'];
				$searchPage->Sort = $results['Sort'];

				$searchPage->ResultsPerPage = $results['ResultsPerPage'];
				$searchPage->SortBy = $results['SortBy'];
				$searchPage->SortDirection = $results['SortDir'];
				$searchPage->QueryType = $results['QueryType'];
				$searchPage->StartWithListing = $results['StartWithListing'];
				$searchPage->SearchTypeValue = $results['SearchTypeValue'];
				$searchPage->SearchOnFieldsValue = $results['SearchOnFieldsValue'];
				$searchPage->BoostFieldsValue = $results['BoostFieldsValue'];
				$searchPage->BoostMatchFieldsValue = $results['BoostMatchFieldsValue'];
				$searchPage->FacetFieldsValue = $results['FacetFieldsValue'];
				$searchPage->CustomFacetFieldsValue = $results['CustomFacetFieldsValue'];
				$searchPage->FacetMappingValue = $results['FacetMappingValue'];
				$searchPage->FacetQueriesValue = $results['FacetQueriesValue'];
				$searchPage->MinFacetCount = $results['MinFacetCount'];
				$searchPage->FilterFieldsValue = $results['FilterFieldsValue'];
				$searchPage->ListingTemplateID = $results['ListingTemplateID'];
				if(isset($relationships[$results['ID']])) {
					$searchPage->SearchTrees()->add($relationships[$results['ID']]);
				}

				$searchPage->write();
				if($searchPage->ID) {
					echo "<strong>{$results['ID']}</strong> Saved<br>";
					$writeCount++;
				}
			}
			else {
				$writeCount++;
			}
		}

		// Confirm that the current user had permission to write these new pages.

		$this->checkPermissions($queryCount, $writeCount);

		// Remove the previous search page tables, as they are now obsolete (and may not be marked as such).

		$remove = array(
			'SiteTree',
			'SiteTree_Live',
			'Page',
			'Page_Live'
		);
		foreach($remove as $table) {
			foreach($created as $ID) {
				DB::query("DELETE FROM {$table} WHERE ID = {$ID}");
			}
		}
		$remove = array(
			'SolrSearchPage',
			'SolrSearchPage_Live',
			'SolrSearchPage_SearchTrees',
			'SolrSearchPage_versions'
		);
		foreach($remove as $table) {
			DB::query("DROP TABLE {$table}");
		}
		Versioned::set_reading_mode($readingMode);
		echo 'Migration Complete!';
	}

	public function checkPermissions($queryCount, $writeCount) {

		if($queryCount !== $writeCount) {
			echo "Invalid Permissions!";
			die;
		}
	}

}
