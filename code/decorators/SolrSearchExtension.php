<?php

/**
 * A controller extension that provides additional methods on page controllers
 * to allow for better searching using Solr
 *
 * @author marcus@silverstripe.com.au
 * @license http://silverstripe.org/bsd-license/
 */
class SolrSearchExtension extends Extension {
	
	static $allowed_actions = array(
		'SearchForm',
		'results',
	);

	/**
	 * Returns the default search page for this site
	 *
	 * @return SolrSearchPage
	 */
    public function getSearchPage() {
		// get the search page for this site, if applicable... otherwise use the default
		return DataObject::get_one('SolrSearchPage', '"ParentID" = 0');
	}

	/**
	 * Get the list of facet values for the given term
	 * 
	 * @param String $term
	 */
	public function Facets($term=null) {
		$sp = $this->getSearchPage();
		if ($sp) {
			$facets = $sp->currentFacets($term);
			return $facets;
		}
	}

	/**
	 * The current search query that is being run by the search page. 
	 *
	 * @return String
	 */
	public function SearchQuery() {
		$sp = $this->getSearchPage();
		if ($sp) {
			return $sp->SearchQuery();
		}
	}

	/**
	 * Site search form
	 */
	function SearchForm() {
		$searchText = isset($_REQUEST['Search']) ? $_REQUEST['Search'] : 'Search';
		$fields = new FieldList(
	      	new TextField("Search", "", $searchText)
	  	);
		$actions = new FieldList(
	      	new FormAction('results', 'Search')
	  	);

	  	return new SearchForm($this->owner, "SearchForm", $fields, $actions);
	}

	/**
	 * Always redirect to the search page when doing a site search
	 */
	public function results() {
		$searchText = isset($_REQUEST['Search']) ? $_REQUEST['Search'] : 'Search';
		$this->owner->redirect($this->getSearchPage()->Link('results').'?Search='.rawurlencode($searchText));
	}
}