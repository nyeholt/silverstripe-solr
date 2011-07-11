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
		
		$fields = implode(' ', $this->fields);
		$this->params['defType'] = 'dismax';
		$ac = '';
		foreach ($this->and as $field => $value) {
			$ac .= '+'.$field. ':' .$value .' ';
		}
		$this->params['bq'] = $ac;
		$this->params['qf'] = $fields;
		return $this->params;
	}
}
