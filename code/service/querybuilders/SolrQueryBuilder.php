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
	/**
	 * an array of field => amount to boost
	 * @var array
	 */
	protected $boost = array();

	public function baseQuery($query) {
		$this->userQuery = $query;
	}
	
	public function queryFields($fields) {
		$this->fields = $fields;
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

		return $rawQuery;
	}
}
