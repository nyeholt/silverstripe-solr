<?php

/**
 * A page type specifically used for displaying search results.
 *
 * This is an alternative encapsulation of search logic as it comprises much more than the out of the
 * box example. To use this instead of the default implementation, your search form call in Page should first
 * retrieve the ExtensibleSearchPage to use as its context.
 *
 * @author Nathan Glasl <nathan@silverstripe.com.au>
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 */

if(class_exists('ExtensibleSearchPage')) {

	class SolrSearch extends DataExtension {

		// Define the additional DB fields that are supported by solr search customisation.

		public static $support = array(
			'QueryType'							=> 1,
			'SearchType'						=> 1,
			'SearchOnFields'					=> 1,
			'BoostFields'						=> 1,
			'BoostMatchFields'					=> 1,
			'FacetFields'						=> 1,
			'CustomFacetFields'					=> 1,
			'FacetMapping'						=> 1,
			'FacetQueries'						=> 1,
			'MinFacetCount'						=> 1,
			'FilterFields'						=> 1
		);

		public $default_search = '*:*';

		/**
		 *
		 * The facets we're interested in for this search page. This will be made a little more
		 * flexible in later releases.
		 *
		 * for example with the alchemiser module -
		 *
		 * array (
		 * 'AlcKeywords_ms',
		 * 	'AlcPerson_ms',
		 * 	'AlcCompany_ms',
		 * 	'AlcOrganization_ms',
		 * );
		 *
		 * @var array
		 */
		public static $facets = array();
		
		/**
		 * Boolean setting on wether to tag facets or not see
		 * {@link https://wiki.apache.org/solr/SimpleFacetParameters#Multi-Select_Faceting_and_LocalParams}
		 * for information on tag use in Solr searches
		 * @var boolean
		 */
		public static $facetTags = false;
		
		/**
		 * Sets the conjunction for multiple filters on a single column.
		 * Typical conjunctions: AND, OR, NOT 
		 * @var string
		 */
		public static $facetConjunction = 'AND';
		
		/**
		 * @var SolrQueryBuilder
		 */
		protected $builder;

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

		public static $dependencies = array(
			'solrSearchService'			=> '%$SolrSearchService',
		);

		/**
		 * @var SolrSearchService
		 */
		public $solrSearchService;

		public function updateCMSFields(FieldList $fields) {

			// Make sure previously existing hooks are carried across.

			$this->owner->extend('updateSolrCMSFields', $fields);
		}

		/**
		 * Return the fields that can be selected for sorting operations.
		 *
		 * @param String $listType
		 * @return array
		 */
		public function getSelectableFields($listType = null, $excludeGeo = true) {
			if (!$listType) {
				$listType = $this->owner->searchableTypes('Page');
			}

			$availableFields = $this->solrSearchService->getAllSearchableFieldsFor($listType);
			$objFields = array_combine(array_keys($availableFields), array_keys($availableFields));
			$objFields['LastEdited'] = 'LastEdited';
			$objFields['Created'] = 'Created';
			$objFields['ID'] = 'ID';
			$objFields['score'] = 'Score';

			if ($excludeGeo) {
				// need to filter out any fields that are of geopoint type, as we can't use those for search
				if (!is_array($listType)) {
					$listType = array($listType);
				}
				foreach ($listType as $classType) {
					$db = Config::inst()->get($classType, 'db');
					if ($db && count($db)) {
						foreach ($db as $name => $type) {
							$type = current(explode("(", $type));
							if (is_subclass_of($type, 'SolrGeoPoint') || $type == 'SolrGeoPoint') {
								unset($objFields[$name]);
							}
						}
					}
				}
			}

			ksort($objFields);
			return $objFields;
		}

		/**
		 * Get the solr instance.
		 *
		 * Note that we do this as a method just in case we decide in future
		 * that different pages can utilise different solr instances..
		 */
		public function getSolr() {
			if (!$this->solr) {
				$this->solr = $this->solrSearchService;
			}
			return $this->solr;
		}

		/**
		 * Figures out the list of fields to use in faceting, based on configured / defaults
		 */
		public function fieldsForFacets() {
			$fields = self::$facets;
			
			$tag = self::$facetTags;
			
			$facetFields = array('FacetFields', 'CustomFacetFields');
			if (!$fields) {
				$fields = array();
			}
			
			$i = 0;
			foreach ($facetFields as $name) {
				if ($this->owner->$name && $ff = $this->owner->$name->getValues()) {
					$types = $this->owner->searchableTypes('Page');
					foreach ($ff as $f) {
						$fieldName = $this->getSolr()->getSolrFieldName($f, $types);
						if (!$fieldName) {
							$fieldName = $f;
						}
						if ($tag) {
							// @TODO Expand this to allow grouping of multiple tag/ex groups eg:
							// {!ex=t0,t1}Fieldname an {!ex=t0,t3}OtherFieldName
							$fields[] = '{!ex=t' . $i++ . '}' . $fieldName;
						} else {
							$fields[] = $fieldName;
						}
					}
				}
			}

			return $fields;
		}
		
		public function setFilterQuery() {
			
			$facetGroupList = $this->fieldsForFacets();
			
			$activeFacets = $this->getActiveFacets();
			
			$conjunction = ' ' . self::$facetConjunction . ' ';
			
			if (count($activeFacets)) {
				foreach ($activeFacets as $facetName => $facetValues) {
				// @TODO This needs improved configurability of this currently all facet are one conjunction
				// (either 'AND'/'OR'), there will be situations where some facets should be AND and other should be OR+
					if (array_search($facetName, $facetGroupList) !== false) {
						$this->builder->addFilter(
								'{!tag=t' . array_search($facetName, $facetGroupList) . '}' . $facetName,
								"(" . implode($conjunction, $facetValues) . ")"
							);
					} else {
						$this->builder->addFilter(
							$facetName, "(" . implode($conjunction, $facetValues) . ")"
						);
					}
				}
			}
			
			return $this->builder;
		}

		/**
		 * Get the currently active query for this page, if any
		 *
		 * @return SolrResultSet
		 */
		public function getQuery() {
			if ($this->query) {
				return $this->query;
			}

			if (!$this->getSolr()->isConnected()) {
				return null;
			}

			$query = null;
			$this->builder = $this->getSolr()->getQueryBuilder($this->owner->QueryType);

			if (isset($_GET['Search'])) {
				$query = $_GET['Search'];

				// lets convert it to a base solr query
				$this->builder->baseQuery($query);
			}

			$sortBy = isset($_GET['SortBy']) ? $_GET['SortBy'] : $this->owner->SortBy;
			$sortDir = isset($_GET['SortDir']) ? $_GET['SortDir'] : $this->owner->SortDir;
			$sortDir = ($sortDir == 'Ascending') ? 'asc' : 'desc';
			$types = $this->owner->searchableTypes();
			// allow user to specify specific type
			if (isset($_GET['SearchType'])) {
				$fixedType = $_GET['SearchType'];
				if (in_array($fixedType, $types)) {
					$types = array($fixedType);
				}
			}

			// (strlen($this->SearchType) ? $this->SearchType : null);

			$fields = $this->getSelectableFields();

			// if we've explicitly set a sort by, then we want to make sure we have a type
			// so we can resolve what the field name in solr is. Otherwise we don't care about type
			// overly much
			if (!count($types) && $sortBy) {
				// default to page
				$types = array('Page');
			}

			if (!isset($fields[$sortBy])) {
				$sortBy = 'score';
			}

			$this->builder->addFacetFields($this->fieldsForFacets($tag = true));
			
			$this->setFilterQuery();

			$offset = isset($_GET['start']) ? $_GET['start'] : 0;
			$limit = isset($_GET['limit']) 
						? $_GET['limit']
						: ($this->owner->ResultsPerPage ? $this->owner->ResultsPerPage : 10);

			if (count($types)) {
				$sortBy = $this->solrSearchService->getSortFieldName($sortBy, $types);
				$filterQ = array();
				foreach ($types as $t) {
					$filterQ[] = 'ClassNameHierarchy_ms:' . $t;
				}
				
				$this->builder->addFilter(implode(' OR ', $filterQ));
			}

			if ($this->owner->SearchTrees()->count()) {
				$parents = $this->owner->SearchTrees()->column('ID');
				$this->builder->addFilter('ParentsHierarchy_ms', implode(' OR ', $parents));
			}

			if (!$sortBy) {
				$sortBy = 'score';
			}

			$this->builder->sortBy($sortBy, $sortDir);

			$selectedFields = $this->owner->SearchOnFields->getValues();

			// the following serves two purposes; filter out the searched on fields to only those that
			// are in the actually  searched on types, and to map them to relevant solr types
			if (count($selectedFields)) {
				$mappedFields = array();
				foreach ($selectedFields as $field) {
					$mappedField = $this->getSolr()->getSolrFieldName($field, $types);
					// some fields that we're searching on don't exist in the types that the user has selected
					// to search within
					if ($mappedField) {
						$mappedFields[] = $mappedField;
					}
				}
				$this->builder->queryFields($mappedFields);
			}

			if ($boost = $this->owner->BoostFields->getValues()) {
				$boostSetting = array();
				foreach ($boost as $field => $amount) {
					if ($amount > 0) {
						$boostSetting[$this->getSolr()->getSolrFieldName($field, $types)] = $amount;
					}
				}
				$this->builder->boost($boostSetting);
			}

			if ($boost = $this->owner->BoostMatchFields->getValues()) {
				if (count($boost)) {
					$this->builder->boostFieldValues($boost);
				}
			}

			if ($filters = $this->owner->FilterFields->getValues()) {
				if (count($filters)) {
					foreach ($filters as $filter => $val) {
						$this->builder->addFilter($filter, $val);
					}
				}
			}

			$fq = $this->owner->queryFacets();
			if (count($fq)) {
				$this->builder->addFacetQueries($fq);
			}
			
			$params = array(
				'facet.mincount' => $this->owner->MinFacetCount ? $this->owner->MinFacetCount : 1
			);
			
			$this->owner->extend('updateQueryBuilder', $this->builder, $offset, $limit, $params);

			$this->query = $this->getSolr()->query($this->builder, $offset, $limit, $params);
			return $this->query;
		}

		/**
		 * Gets a list of facet based filters
		 */
		public function getActiveFacets() {
			return isset($_GET[self::$filter_param]) ? $_GET[self::$filter_param] : array();
		}

		/**
		 * Retrieve all facets in the result set in a way that can be iterated
		 * over conveniently.
		 *
		 * @return \ArrayList
		 */
		public function AllFacets() {
			if (!$this->getQuery()) {
				return new ArrayList(array());
			}

			$facets = $this->getQuery()->getFacets();
			$result = array();
			$mapping = $this->facetFieldMapping();
			foreach ($facets as $title => $items) {
				$object = new ViewableData();
				$object->Items = $this->currentFacets($title);
				$title = isset($mapping[$title]) ? $mapping[$title] : $title;
				$object->Title = Varchar::create_field('Varchar', $title);
				$result[] = $object;
			}
			return new ArrayList($result);
		}

		/**
		 * Retrieve the mapping of facet field name (eg FieldName_mt)
		 * mapped to the user entered nice name
		 *
		 * @return type
		 */
		protected function facetFieldMapping() {
			$fields = array();
			if ($this->owner->FacetMapping && $ff = $this->owner->FacetMapping->getValues()) {
				$types = $this->owner->searchableTypes('Page');
				foreach ($ff as $f => $mapped) {
					$fieldName = $this->getSolr()->getSolrFieldName($f, $types);
					if (!$fieldName) {
						$fieldName = $f;
					}
					$fields[$fieldName] = $mapped;
				}
			}
			return $fields;
		}

		/**
		 * Get the list of facet values for the given term
		 *
		 * @param Array $term
		 */
		public function currentFacets($term=null) {
			if (!$this->getQuery()) {
				return new ArrayList(array());
			}

			$facets = $this->getQuery()->getFacets();
			$queryFacets = $this->owner->queryFacets();

			$me = $this->owner;

			$convertFacets = function ($term, $raw) use ($facets, $queryFacets, $me) {
				$result = array();
				foreach ($raw as $facetTerm) {
					// if it's a query facet, then we may have a label for it
					if (isset($queryFacets[$facetTerm->Name])) {
						$facetTerm->Name = $queryFacets[$facetTerm->Name];
					}
					$sq = $me->SearchQuery();
					$sep = strlen($sq) ? '&amp;' : '';
					$facetTerm->SearchLink = $me->Link('results') . '?' . $sq .$sep. SolrSearch::$filter_param . "[$term][]=$facetTerm->Query";
					$facetTerm->QuotedSearchLink = $me->Link('results') . '?' . $sq .$sep. SolrSearch::$filter_param . "[$term][]=&quot;$facetTerm->Query&quot;";
					$result[] = new ArrayData($facetTerm);
				}
				return $result;
			};

			if ($term) {
				// return just that term
				$ret = isset($facets[$term]) ? $facets[$term] : null;
				// lets update them all and add a link parameter
				$result = array();
				if ($ret) {
					$result = $convertFacets($term, $ret);
				}

				return new ArrayList($result);
			} else {
				$all = array();
				foreach ($facets as $term => $ret) {
					$result = $convertFacets($term, $ret);
					$all = array_merge($all, $result);
				}

				return new ArrayList($all);
			}

			return new ArrayList($facets);
		}

		/**
		 * Add in any explicitly configured types.
		 *
		 * @param array $source
		 */
		public function updateSource(&$source) {
			$objects = DataObject::get('SolrTypeConfiguration');
			if ($objects) {
				foreach ($objects as $obj) {
					$source[$obj->Title] = $obj->Title;
				}
			}
		}

		/**
		 * Gets the list of query parsers available
		 *
		 * @return array
		 */
		public function getQueryBuilders() {
			return $this->solrSearchService->getQueryBuilders();
		}

	}

	class SolrSearch_Controller extends Extension {

		protected function getSolr() {
			return $this->owner->data()->getSolr();
		}

		public function FacetCrumbs() {
			$activeFacets = $this->owner->data()->getActiveFacets();
			$parts = array();
			$queryString = $this->owner->data()->SearchQuery();
			if (count($activeFacets)) {
				foreach ($activeFacets as $facetName => $facetValues) {
					foreach ($facetValues as $i => $v) {
						$item = new stdClass();
						$item->Name = $v;
						$paramName = urlencode(SolrSearch::$filter_param . '[' . $facetName . '][' . $i . ']') .'='. urlencode($item->Name);
						$item->RemoveLink = $this->owner->Link('results') . '?' . str_replace($paramName, '', $queryString);
						$parts[] = new ArrayData($item);
					}
				}
			}

			return new ArrayList($parts);
		}

		/**
		 * Process and render search results
		 */
		function getSearchResults($data = null, $form = null){
			$query = $this->owner->data()->getQuery();

			$term = isset($_GET['Search']) ? Convert::raw2xml($_GET['Search']) : '';

			$results = $query ? $query->getDataObjects(true) : ArrayList::create();

			$elapsed = '< 0.001';

			if ($query) {
				$resultData = array(
					'TotalResults' => (($total = $query->getTotalResults()) ? $total : 0)
				);
				$time = $query->getTimeTaken();
				if($time) {
					$elapsed = $time / 1000;
				}
			} else {
				$resultData = array();
			}

			$data = array(
				'Results'		=> $results,
				'Query'			=> Varchar::create_field('Varchar', $term),
				'Title'			=> $this->owner->data()->Title,
				'ResultData'	=> ArrayData::create($resultData),
				'TimeTaken'		=> $elapsed
			);
			return $data;
		}

		/**
		 * Return the results with a template applied to them based on the page's listing template
		 *
		 */
		public function TemplatedResults() {
			$query = $this->owner->data()->getQuery();
			if ($this->owner->data()->ListingTemplateID && $query) {
				$template = DataObject::get_by_id('ListingTemplate', $this->owner->data()->ListingTemplateID);
				if ($template && $template->exists()) {
					$items = $query ? $query->getDataObjects() : new DataObjectSet();
					$item = $this->owner->data()->customise(array('Items' => $items));
					$view = SSViewer::fromString($template->ItemTemplate);
					return $view->process($item);
				}
			}
		}

	}

}
