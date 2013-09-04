<?php

/**
 * The default solr / lucene formatted query
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SolrQueryBuilder {
	
	public $title = 'Default Solr';
	
	protected $userQuery = '';
	protected $fields = array('title', 'text');
	protected $and = array();
	protected $params = array();
	
	protected $filters = array();
	
	/**
	 * an array of field => amount to boost
	 * @var array
	 */
	protected $boost = array();
	
	/**
	 * Field:value => boost amount
	 *
	 * @var array
	 */
	protected $boostFieldValues = array();
	
	protected $sort;
	
	/**
	 *
	 * @var array
	 */
	protected $facets = array('fields' => array(), 'queries' => array());
	
	/**
	 * Per-field facet limits
	 *
	 * @var array
	 */
	protected $facetFieldLimits = array();
	
	/**
	 * Number of facets to return
	 *
	 * @var int
	 */
	protected $facetLimit = 50;
	
	/**
	 * Number of items with faces to be included
	 *
	 * @var int
	 */
	protected $facetCount = 1;

	public function baseQuery($query) {
		$this->userQuery = $query;
		return $this;
	}
	
	public function queryFields($fields) {
		$this->fields = $fields;
		return $this;
	}
	
	public function sortBy($field, $direction) {
		$this->sort = "$field $direction";
		return $this;
	}
	
	public function andWith($field, $value) {
		$existing = array();
		if (isset($this->and[$field])) {
			$existing = $this->and[$field];
		}

		if (is_array($value)) {
			$existing = $existing + $value;
		} else {
			$existing[] = $value;
		}

		$this->and[$field] = $existing;
		return $this;
	}

	public function setParams($params) {
		$this->params = $params;
		return $this;
	}
	
	public function addFacetFields($fields, $limit = 0) {
		$this->facets['fields'] = array_unique(array_merge($this->facets['fields'], $fields));
		$this->facetLimit = $limit;
		if ($limit) {
			$this->facetLimit = $limit;
		}
		return $this;
	}
	
	public function addFacetQueries($queries, $limit = 0) {
		$this->facets['queries'] = array_unique(array_merge($this->facets['queries'], $queries));
		if ($limit) {
			$this->facetLimit = $limit;
		}
		
		return $this;
	}
	
	public function addFacetFieldLimit($field, $limit) {
		$this->facetFieldLimits[$field] = $limit;
	}
	
	public function getParams() {
		if (count($this->filters)) {
			$this->params['fq'] = implode(' AND ', $this->filters);
		}
		if ($this->sort) {
			$this->params['sort'] = $this->sort;
		}

		$this->facetParams();

		return $this->params;
	}
	
	/**
	 * Return the base search term
	 * 
	 * @return string
	 */
	public function getUserQuery() {
		return $this->userQuery;
	}
	
	protected function facetParams() {
		if (isset($this->facets['fields']) && count($this->facets['fields'])) {
			$this->params['facet'] = 'true';
			
			$this->params['facet.field'] = $this->facets['fields'];
		}
		
		if (isset($this->facets['queries']) && count($this->facets['queries'])) {
			$this->params['facet'] = 'true';
			$this->params['facet.query'] = $this->facets['queries'];
		}
		
		if ($this->facetLimit) {
			$this->params['facet.limit'] = $this->facetLimit;
		}
		
		if (count($this->facetFieldLimits)) {
			foreach ($this->facetFieldLimits as $field => $limit) {
				$this->params['f.' . $field . '.facet.limit'] = $limit;
			}
		}

		$this->params['facet.mincount'] = $this->facetCount ? $this->facetCount : 1;
		
		
	}

	public function parse($string) {
		// custom search query entered
		if (strpos($string, ':') > 0) {
			return $string;
		}

		$sep = '';
		$lucene = '';
		foreach ($this->fields as $field) {
			$lucene .= $sep . $field . ':' . $string;
			if (isset($this->boost[$field])) {
				$lucene .= '^' . $this->boost[$field];
			}
			$sep = ' OR ';
		}

		return $lucene;
	}
	
	public function boost($boost) {
		$this->boost = $boost;
	}
	
	public function boostFieldValues($boost) {
		$this->boostFieldValues = $boost;
	}

	public function toString() {
		$rawQuery = $this->userQuery ? '(' . $this->parse($this->userQuery).')' : '';
		
		// add in all the clauses;
		$sep = '';
		if ($rawQuery) {
			$sep = ' AND ';
		}
		
		foreach ($this->and as $field => $valArray) {
			$innerSep = '';
			$innerQuery = '';
			foreach ($valArray as $value) {
				$innerQuery .= $innerSep . $field .':' . $value;
				$innerSep = ' OR ';
			}
			if (strlen($innerQuery)) {
				$rawQuery .= $sep . '(' . $innerQuery . ')';
				$sep = ' AND ';
			}
		}
		
		$sep = '';
		if ($rawQuery) {
			$sep = ' OR ';
		}
		foreach ($this->boostFieldValues as $field => $boost) {
			$rawQuery .= $sep . $field . '^' . $boost;
			$sep = ' OR ';
		}

		return $rawQuery;
	}
	
	/**
	 * Add a filter query clause. 
	 * 
	 * Filter queries simply restrict the result set without affecting the score of results
	 * 
	 * @param string $query
	 */
	public function addFilter($query, $value = null) {
		if ($value) {
			$query = "$query:$value";
		}
		$this->filters[$query] = $query;
		return $this;
	}
	
	/**
	 * Remove a filter in place on this query
	 * 
	 * @param string $query
	 * @param mixed $value
	 */
	public function removeFilter($query, $value = null) {
		if ($value) {
			$query = "$query:$value";
		}
		unset($this->filters[$query]);
		return $this;
	}

	/**
	 * Apply a geo field restriction around a particular point
	 * 
	 * @param string $point 
	 *					The point in "lat,lon" format
	 * @param string $field
	 * @param float $radius
	 */
	public function restrictNearPoint($point, $field, $radius) {
		$this->addFilter("{!geofilt}");
		
		$this->params['sfield'] = $field;
		$this->params['pt'] = $point;
		$this->params['d'] = $radius;

		return $this;
	}
}
