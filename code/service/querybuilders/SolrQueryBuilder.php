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

	public function baseQuery($query) {
		$this->userQuery = $query;
	}
	
	public function queryFields($fields) {
		$this->fields = $fields;
	}
	
	public function sortBy($field, $direction) {
		$this->sort = "$field $direction";
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
	}

	public function setParams($params) {
		$this->params = $params;
	}
	
	public function getParams() {
		if (count($this->filters)) {
			$this->params['fq'] = implode(' AND ', $this->filters);
		}
		if ($this->sort) {
			$this->params['sort'] = $this->sort;
		}
		return $this->params;
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
	public function addFilter($query) {
		$this->filters[] = $query;
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
	}
}
