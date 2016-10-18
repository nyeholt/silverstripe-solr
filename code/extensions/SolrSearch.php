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
        
        const BOOST_MAX = 10;
        
		const RESULTS_ACTION = 'results';

		private static $db = array(
			'QueryType' => 'Varchar',
			'SearchType' => 'MultiValueField',	// types that a user can search within
			'SearchOnFields' => 'MultiValueField',
			'ExtraSearchFields'	=> 'MultiValueField',
			'BoostFields' => 'MultiValueField',
			'BoostMatchFields' => 'MultiValueField',
			// faceting fields
			'FacetFields' => 'MultiValueField',
			'CustomFacetFields' => 'MultiValueField',
			'FacetMapping' => 'MultiValueField',
			'FacetQueries' => 'MultiValueField',
			'MinFacetCount' => 'Int',
			// filter fields (not used for relevance, just for restricting data set)
			'FilterFields' => 'MultiValueField'
		);

		public static $supports_hierarchy = true;

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
		private static $facets = array();

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
		private static $filter_param = 'filter';

		/**
		 * The default classes to search on.
		 *
		 * @var array
		 */
		private static $default_searchable_types = array('SiteTree');

		public static $dependencies = array(
			'solrSearchService'			=> '%$SolrSearchService',
		);

		private static $additional_search_types = array();

		/**
		 * @var SolrSearchService
		 */
		public $solrSearchService;

		public function updateExtensibleSearchPageCMSFields(FieldList $fields) {
			if($this->owner->SearchEngine === get_class($this)) {
				$types = SiteTree::page_type_classes();
				$source = array_combine($types, $types);

				// add in any explicitly configured
				asort($source);
				$source = $this->owner->updateSource($source);

				$parsers = $this->owner->getQueryBuilders();
				$options = array();
				foreach ($parsers as $key => $objCls) {
					$obj = new $objCls;
					$options[$key] = $obj->title;
				}
				$fields->addFieldToTab('Root.Main', new DropdownField('QueryType', _t('ExtensibleSearchPage.QUERY_TYPE', 'Query Type'), $options), 'Content');

				ksort($source);
				$source = array_merge($source, ExtensibleSearchPage::config()->additional_search_types);
				$types = MultiValueDropdownField::create('SearchType', _t('ExtensibleSearchPage.SEARCH_ITEM_TYPE', 'Search items of type'), $source);
				$fields->addFieldToTab('Root.Main', $types, 'Content');

				$objFields = $this->owner->getSelectableFields();
                
                
				$sortFields = $objFields;

				// Remove content and groups from being sortable (as they are not relevant).

				unset($sortFields['Content']);
				unset($sortFields['Groups']);
				$fields->replaceField('SortBy', new DropdownField('SortBy', _t('ExtensibleSearchPage.SORT_BY', 'Sort By'), $sortFields));
				$fields->addFieldToTab('Root.Main', MultiValueDropdownField::create('SearchOnFields', _t('ExtensibleSearchPage.INCLUDE_FIELDS', 'Search On Fields'), $objFields), 'Content');
				$fields->addFieldToTab('Root.Main', MultiValueTextField::create('ExtraSearchFields', _t('SolrSearch.EXTRA_FIELDS', 'Custom solr fields to search')), 'Content');
				
				$boostVals = array();
				for ($i = 1; $i <= static::BOOST_MAX; $i++) {
					$boostVals[$i] = $i;
				}

				$fields->addFieldToTab(
					'Root.Main',
					new KeyValueField('BoostFields', _t('ExtensibleSearchPage.BOOST_FIELDS', 'Boost values'), $objFields, $boostVals),
					'Content'
				);

				$fields->addFieldToTab(
					'Root.Main',
					$f = new KeyValueField('BoostMatchFields', _t('ExtensibleSearchPage.BOOST_MATCH_FIELDS', 'Boost fields with field/value matches'), array(), $boostVals),
					'Content'
				);
				$f->setRightTitle('Enter a field name, followed by the value to boost if found in the result set, eg "title:Home" ');

				$fields->addFieldToTab(
					'Root.Main',
					$kv = new KeyValueField('FilterFields', _t('ExtensibleSearchPage.FILTER_FIELDS', 'Fields to filter by')),
					'Content'
				);

				$fields->addFieldToTab('Root.Main', new HeaderField('FacetHeader', _t('ExtensibleSearchPage.FACET_HEADER', 'Facet Settings')), 'Content');

				$fields->addFieldToTab(
					'Root.Main',
					new MultiValueDropdownField('FacetFields', _t('ExtensibleSearchPage.FACET_FIELDS', 'Fields to create facets for'), $objFields),
					'Content'
				);

				$fields->addFieldToTab(
					'Root.Main',
					new MultiValueTextField('CustomFacetFields', _t('ExtensibleSearchPage.CUSTOM_FACET_FIELDS', 'Additional fields to create facets for')),
					'Content'
				);

				$facetMappingFields = $objFields;
				if ($this->owner->CustomFacetFields && ($cff = $this->owner->CustomFacetFields->getValues())) {
					foreach ($cff as $facetField) {
						$facetMappingFields[$facetField] = $facetField;
					}
				}
				$fields->addFieldToTab(
					'Root.Main',
					new KeyValueField('FacetMapping', _t('ExtensibleSearchPage.FACET_MAPPING', 'Mapping of facet title to nice title'), $facetMappingFields),
					'Content'
				);

				$fields->addFieldToTab(
					'Root.Main',
					new KeyValueField('FacetQueries', _t('ExtensibleSearchPage.FACET_QUERIES', 'Fields to create query facets for')),
					'Content'
				);

				$fields->addFieldToTab('Root.Main',
					new NumericField('MinFacetCount', _t('ExtensibleSearchPage.MIN_FACET_COUNT', 'Minimum facet count for inclusion in facet results'), 2),
					'Content'
				);
			}

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

			// Remove any custom field types and display the sortable options nicely to the user.

			$objFieldsNice = array();
			foreach($objFields as $key => $value) {
				if($customType = strpos($value, ':')) {
					$value = substr($value, 0, $customType);
				}

				// Add spaces between words, other characters and numbers.

				$objFieldsNice[$key] = ltrim(preg_replace(array(
					'/([A-Z][a-z]+)/',
					'/([A-Z]{2,})/',
					'/([_.0-9]+)/'
				), ' $0', $value));
			}
			ksort($objFieldsNice);
			return $objFieldsNice;
		}

		/**
		 * get the list of types that we've selected to search on
		 */
		public function searchableTypes($default = null) {
			$listType = $this->owner->SearchType ? $this->owner->SearchType->getValues() : null;
			if (!$listType) {
				$listType = $default ? array($default) : null;
			}
			return $listType;
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
			$fields = Config::inst()->get('SolrSearch', 'facets');

			$facetFields = array('FacetFields', 'CustomFacetFields');
			if (!$fields) {
				$fields = array();
			}

			foreach ($facetFields as $name) {
				if ($this->owner->$name && $ff = $this->owner->$name->getValues()) {
					$types = $this->owner->searchableTypes('Page');
					foreach ($ff as $f) {
						$fieldName = $this->getSolr()->getSolrFieldName($f, $types);
						if (!$fieldName) {
							$fieldName = $f;
						}
						$fields[] = $fieldName;
					}
				}
			}

			return $fields;
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
			$builder = $this->getSolr()->getQueryBuilder($this->owner->QueryType);

			if (isset($_GET['Search'])) {
				$query = $_GET['Search'];

				// lets convert it to a base solr query
				$builder->baseQuery($query);
			}

			$sortBy = isset($_GET['SortBy']) ? $_GET['SortBy'] : $this->owner->SortBy;
			$sortDir = isset($_GET['SortDirection']) ? $_GET['SortDirection'] : $this->owner->SortDirection;
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
				$types = Config::inst()->get(__CLASS__, 'default_searchable_types');
			}

			if (!isset($fields[$sortBy])) {
				$sortBy = 'score';
			}

			$activeFacets = $this->getActiveFacets();
			if (count($activeFacets)) {
				foreach ($activeFacets as $facetName => $facetValues) {
					foreach ($facetValues as $value) {
						$builder->addFilter($facetName, $value);
					}
				}
			}

			$offset = isset($_GET['start']) ? $_GET['start'] : 0;
			$limit = isset($_GET['limit']) ? $_GET['limit'] : ($this->owner->ResultsPerPage ? $this->owner->ResultsPerPage : 10);

			// Apply any hierarchy filters.

			if(count($types)) {
				$sortBy = $this->solrSearchService->getSortFieldName($sortBy, $types);
				$hierarchyTypes = array();
				$parents = $this->owner->SearchTrees()->count() ? implode(' OR ParentsHierarchy_ms:', $this->owner->SearchTrees()->column('ID')) : null;
				foreach($types as $type) {

					// Search against site tree elements with parent hierarchy restriction.

					if($parents && (ClassInfo::baseDataClass($type) === 'SiteTree')) {
						$hierarchyTypes[] = "{$type} AND (ParentsHierarchy_ms:{$parents}))";
					}

					// Search against other data objects without parent hierarchy restriction.

					else {
						$hierarchyTypes[] = "{$type})";
					}
				}
				$builder->addFilter('(ClassNameHierarchy_ms', implode(' OR (ClassNameHierarchy_ms:', $hierarchyTypes));
			}

			if (!$sortBy) {
				$sortBy = 'score';
			}
			
			$sortDir = $sortDir == 'Ascending' ? 'asc' : 'desc';

			$builder->sortBy($sortBy, $sortDir);

			$selectedFields = $this->owner->SearchOnFields->getValues();
			$extraFields = $this->owner->ExtraSearchFields->getValues();

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

				if ($extraFields && count($extraFields)) {
					$mappedFields = array_merge($mappedFields, $extraFields);
				}
				
				$builder->queryFields($mappedFields);
			}

			if ($boost = $this->owner->BoostFields->getValues()) {
				$boostSetting = array();
				foreach ($boost as $field => $amount) {
					if ($amount > 0) {
						$boostSetting[$this->getSolr()->getSolrFieldName($field, $types)] = $amount;
					}
				}
				$builder->boost($boostSetting);
			}

			if ($boost = $this->owner->BoostMatchFields->getValues()) {
				if (count($boost)) {
					$builder->boostFieldValues($boost);
				}
			}

			if ($filters = $this->owner->FilterFields->getValues()) {
				if (count($filters)) {
					foreach ($filters as $filter => $val) {
						$builder->addFilter($filter, $val);
					}
				}
			}

			$params = array(
				'facet' => 'true',
				'facet.field' => $this->fieldsForFacets(),
				'facet.limit' => 10,
				'facet.mincount' => $this->owner->MinFacetCount ? $this->owner->MinFacetCount : 1,
				'fl' => '*,score'
			);

			$fq = $this->owner->queryFacets();
			if (count($fq)) {
				$params['facet.query'] = array_keys($fq);
			}

			$this->owner->extend('updateQueryBuilder', $builder);

			$this->query = $this->getSolr()->query($builder, $offset, $limit, $params);
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
            if (!is_array($facets)) {
                return ArrayList::create($result);
            }
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
		 * @param String $term
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
					$facetTerm->SearchLink = $me->Link(self::RESULTS_ACTION) . '?' . $sq .$sep. SolrSearch::$filter_param . "[$term][]=$facetTerm->Query";
					$facetTerm->QuotedSearchLink = $me->Link(self::RESULTS_ACTION) . '?' . $sq .$sep. SolrSearch::$filter_param . "[$term][]=&quot;$facetTerm->Query&quot;";
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
		public function updateSource($source) {
			$objects = DataObject::get('SolrTypeConfiguration');
			if ($objects) {
				foreach ($objects as $obj) {
					$source[$obj->Title] = $obj->Title;
				}
			}
			return $source;
		}

		/**
		 * Gets the list of query parsers available
		 *
		 * @return array
		 */
		public function getQueryBuilders() {
			return $this->solrSearchService->getQueryBuilders();
		}

		/**
		 * Get the list of field -> query items to be used for faceting by query
		 */
		public function queryFacets() {
			$fields = array();
			if ($this->owner->FacetQueries && $fq = $this->owner->FacetQueries->getValues()) {
				$fields = array_flip($fq);
			}
			return $fields;
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
						$item->RemoveLink = $this->owner->Link(SolrSearch::RESULTS_ACTION) . '?' . str_replace($paramName, '', $queryString);
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

			$count = ($query && ($total = $query->getTotalResults())) ? $total : 0;
			if ($query) {
				$resultData = array(
					'TotalResults' => $count
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
				'Count'			=> $count,
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
