<?php

/**
 * Query parser for Dismax querying
 * 
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class DismaxSolrSearchBuilder extends SolrQueryBuilder {
	public $title = 'Solr Dismax';
	
	public function toString() {
		return $this->userQuery;
	}

	public function getParams() {
		parent::getParams();
		
		$fields = '';
		$sep = '';
		foreach ($this->fields as $field) {
			$fields .= $sep . $field;
			if (isset($this->boost[$field])) {
				$fields .= '^'.$this->boost[$field];
			}
			$sep = ' ';
		}
		$this->params['qf'] = $fields;
		$this->params['defType'] = 'dismax';
		
		$filterVals = isset($this->params['fq']) ? $this->params['fq'] : array();
		
		foreach ($this->and as $field => $valArray) {
			foreach ($valArray as $value) {
				$filterVals[] = $field. ':' .$value;
			}
		}

		$this->params['fq'] = $filterVals;
		
		$bq = array();
		foreach ($this->boostFieldValues as $field => $boost) {
			$bq[] = "$field^$boost";
		}
		if (count($bq)) {
			$this->params['bq'] = $bq;
		}
		
		if ($this->sort) {
			$this->params['sort'] = $this->sort;
		}
		
		$this->facetParams();
		
		return $this->params;
	}
}
