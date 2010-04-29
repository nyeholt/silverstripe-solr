<?php
/*

Copyright (c) 2009, SilverStripe Australia PTY LTD - www.silverstripe.com.au
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the
      documentation and/or other materials provided with the distribution.
    * Neither the name of SilverStripe nor the names of its contributors may be used to endorse or promote products derived from this software
      without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE
GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT,
STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY
OF SUCH DAMAGE.
*/

/**
 * A page type specifically used for displaying search results.
 *
 * This is an alternative encapsulation of search logic as it comprises much more than the out of the
 * box example. To use this instead of the default implementation, your search form call in Page should first
 * retrieve the SolrSearchPage to use as its context.
 * 
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 */
class SolrSearchPage extends Page
{
    public static $db = array(
		'ResultsPerPage' => 'Int',
	);

	/**
	 *
	 * The facets we're interested in for this site
	 *
	 * @var array
	 */
	public static $facets = array(
		'AlcKeywords_ms',
		'AlcPerson_ms',
		'AlcCompany_ms',
		'AlcOrganization_ms',
	);

	/**
	 * A local cache of the current query the user is executing based
	 * on data in the request
	 *
	 * @var SolrResultSet
	 */
	protected $query;

	/**
	 * @var SolrSearchService
	 */
	protected $solr;

	/**
	 * Used for the url param
	 *
	 * @var String
	 */
	public static $filter_param = 'filter';

	public function getCMSFields() {
		$fields = parent::getCMSFields();

		$fields->addFieldToTab(
			'Root.Content.Main',
			new DropdownField(
				'ResultsPerPage',
				_t('SolrSearchPage.RESULTS_PER_PAGE', 'Results per page'),
				array('5' => '5', '10' => '10', '15' => '15', '20' => '20')
			),
			'Content'
		);

		return $fields;
	}


	/**
	 * Ensures that there is always a 404 page
	 * by checking if there's an instance of
	 * ErrorPage with a 404 error code. If there
	 * is not, one is created when the DB is built.
	 */
	function requireDefaultRecords() {
		parent::requireDefaultRecords();

		$page = DataObject::get_one('SolrSearchPage');
		if(!($page && $page->exists())) {
			$page = new SolrSearchPage();
			$page->Title = _t('SolrSearchPage.DEFAULT_PAGE_TITLE', 'Search');
			$page->Content = '';
			$page->ResultsPerPage = 10;
			$page->Status = 'New page';
			$page->write();

			DB::alteration_message('Search page created', 'created');
		}
	}


	/**
	 * Get the solr instance. 
	 * 
	 * Note that we do this as a method just in case we decide in future
	 * that different pages can utilise different solr instances.. 
	 */
	public function getSolr() {
		if (!$this->solr) {
			$this->solr = singleton('SolrSearchService');
		}
		return $this->solr;
	}

	/**
	 * Get the currently active query for this page, if any
	 * @return SolrResultSet
	 */
	public function getQuery() {
		if ($this->query) {
			return $this->query;
		}

		$query = null;
		if (isset($_GET['Search'])) {
			$query = $_GET['Search'];
		}

		$activeFacets = $this->getActiveFacets();
		if (count($activeFacets)) {
			$sep = $query ? ' AND ' : '';
			foreach ($activeFacets as $facetName => $facetValues) {
				foreach ($facetValues as $value) {
					$query .= $sep . $facetName . ':"'.$value.'"';
					$sep = ' AND ';
				}
			}
		}

		if (!$query) {
			$this->query = $this->getSolr()->getFacetsForFields(self::$facets);
		} else {
			$offset = isset($_GET['start']) ? $_GET['start'] : 0;
			$limit = isset($_GET['limit']) ? $_GET['limit'] : ($this->ResultsPerPage ? $this->ResultsPerPage : 10);
			$params = array('sort' => 'score desc', 'fl' => '*,score');
			$params = array(
				'facet' => 'true',
				'facet.field' => self::$facets,
				'facet.limit' => 10,
				'facet.mincount' => 1,
				'sort' => 'score desc',
				'fl' => '*,score'
			);

			$this->query = $this->getSolr()->query($query, $offset, $limit, $params);
		}
		return $this->query;
	}

	/**
	 * Gets a list of facet based filters
	 */
	public function getActiveFacets() {
		return isset($_GET[self::$filter_param]) ? $_GET[self::$filter_param] : array();
	}

	/**
	 * Returns a url parameter string that was just used to execute the current query.
	 *
	 * This is useful for ensuring the parameters used in the search can be passed on again
	 * for subsequent queries.
	 *
	 * @param array $exclusions
	 *			A list of elements that should be excluded from the final query string
	 *
	 * @return String
	 */
	function SearchQuery() {
		$parts = parse_url($_SERVER['REQUEST_URI']);
		if(!$parts) {
			throw new InvalidArgumentException("Can't parse URL: " . $uri);
		}

		// Parse params and add new variable
		$params = array();
		if(isset($parts['query'])) {
			parse_str($parts['query'], $params);
			if (count($params)) {
				return http_build_query($params);
			}
		}
	}


	/**
	 * Get the list of facet values for the given term
	 *
	 * @param String $term
	 */
	public function currentFacets($term=null) {
		$facets = $this->getQuery()->getFacets();

		if ($term) {
			// return just that term
			$ret = isset($facets[$term]) ? $facets[$term] : null;
			// lets update them all and add a link parameter
			
			foreach ($ret as $facetTerm) {
				$sq = $this->SearchQuery();
				$sep = strlen($sq) ? '&amp;' : '';
				$facetTerm->SearchLink = $this->Link('results') . '?' . $sq .$sep. self::$filter_param . "[$term][]=$facetTerm->Name";
			}

			return new DataObjectSet($ret);
		}

		return $facets;
	}
}

class SolrSearchPage_Controller extends Page_Controller {


	protected function getSolr() {
		return $this->data()->getSolr();
	}

	public function FacetCrumbs() {
		$activeFacets = $this->data()->getActiveFacets();
		$parts = array();
		$queryString = $this->data()->SearchQuery();
		if (count($activeFacets)) {
			foreach ($activeFacets as $facetName => $facetValues) {
				foreach ($facetValues as $i => $v) {
					$item = new stdClass();
					$item->Name = $v;
					$paramName = urlencode(SolrSearchPage::$filter_param . '[' . $facetName . '][' . $i . ']') .'='. urlencode($item->Name);
					$item->RemoveLink = $this->Link('results') . '?' . str_replace($paramName, '', $queryString);
					$parts[] = $item;
				}
			}
		}

		return new DataObjectSet($parts);
	}


	/**
	 * Process and render search results
	 */
	function results($data = null, $form = null){
		$query = $this->data()->getQuery();

		$term = isset($_GET['Search']) ? Convert::raw2xml($_GET['Search']) : '';

	  	$data = array(
	     	'Results' => $query->getDataObjects(),
	     	'Query' => $term,
	      	'Title' => 'Search Results'
	  	);

	  	return $this->customise($data)->renderWith(array('SolrSearchPage_results', 'SolrSearchPage', 'Page'));
	}
}
?>