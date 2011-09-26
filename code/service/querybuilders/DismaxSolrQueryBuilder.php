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
		
		// AND conditionals
		$ac = '';
		
		foreach ($this->and as $field => $valArray) {
			foreach ($valArray as $value) {
				$ac .= '+'.$field. ':' .$value .' ';
			}
		}
		
		$this->params['bq'] = $ac;
		
		return $this->params;
	}
}
