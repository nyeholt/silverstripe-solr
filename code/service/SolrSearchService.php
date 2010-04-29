<?php
/**

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
 * A search service built around Solr
 * 
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 *
 */
class SolrSearchService
{
	/**
	 * The connection details for the solr instance to connect to
	 *
	 * @var array
	 */
	public static $solr_details = array(
		'host' => 'localhost',
		'port' => '8983',
		'context' => '/solr',
	);

	/**
	 * Determines what mapper class to use to map to solr schema fields. 
	 * Change this if you have changed the schema that solr uses by default
	 * 
	 * @var String
	 */
	public static $mapper_class = 'SolrSchemaMapper';

	/**
	 * The mapper to use to map silverstripe objects to a solr schema
	 * 
	 * @var SolrSchemaMapper
	 */
	protected $mapper;
	
	public function __construct() {
		$m = self::$mapper_class;
		$this->mapper = new $m;
	}

	/**
	 * A class that can map field types to solr fields, and values to appropriate types
	 *
	 * @param SolrSchemaMapper $mapper
	 */
	public function setMapper($mapper) {
		$this->mapper = $mapper;
	}
	
	/**
	 * Assuming here that we're indexing a stdClass object
	 * with an ID field that is a unique identifier
	 * 
	 * Note that the structur eof the object array must be 
	 * 
	 * array(
	 * 		'FieldName' => array(
	 * 			'Type' => 'Fieldtype (eg date, string, int)',
	 * 			'Value' => 'Actualvalue'
	 * 		)
	 * )
	 * 
	 * You should include a field named 'ID' that dictates the 
	 * ID of the object, and a field named 'ClassName' that is the 
	 * name of the document's type
	 * 
	 * @param stdClass $object
	 */
	public function index($dataObject)
	{
		$document = new Apache_Solr_Document();
		$fieldsToIndex = array();

		if (is_object($dataObject)) {
			$fieldsToIndex = $dataObject->searchableFields();
			
			$object = $this->objectToFields($dataObject);
			$object['ID'] = $dataObject->ID;
			$object['ClassName'] = $dataObject->class;
		} else {
			$object = $dataObject;
			$fieldsToIndex = isset($object['index_fields']) ? $object['index_fields'] : array(
				'Title' => array(),
				'Content' => array(),
			);
		}

		$fieldsToIndex['LastEdited'] = array();
		$fieldsToIndex['Created'] = array();
		
		$id = isset($object['ID']) ? $object['ID'] : false;
		$classType = isset($object['ClassName']) ? $object['ClassName'] : false;

		// we're not indexing these fields just at the moment
		unset($object['ClassName']);unset($object['ID']);

		foreach ($object as $field => $valueDesc) {
			if (!is_array($valueDesc)) {
				continue;
			}

			$type = $valueDesc['Type'];
			$value = $valueDesc['Value'];

			// this should have already been taken care of, but just in case...
			if ($type == 'MultiValueField' && $value instanceof MultiValueField) {
				$value = $value->getValues();
			}

			if (!isset($fieldsToIndex[$field])) {
				continue;
			}

			$fieldName = $this->mapper->mapType($field, $type, $value);

			if (!$fieldName) {
				continue;
			}

			$value = $this->mapper->mapValue($value, $type);

			if (is_array($value)) {
				foreach ($value as $v) {
					$document->addField($fieldName, $v);
				}
			} else {
				$document->$fieldName = $value;
			}
		}

		if ($id) {
			try {
				$document->id = $classType.'_'.$id;
				$this->getSolr()->addDocument($document);
				$this->getSolr()->commit();
				$this->getSolr()->optimize();
			} catch (Exception $ie) {
				SS_Log::log($ie, SS_Log::ERR);
			}
		}
	}


	/**
	 * Pull out all the fields that should be indexed for a particular object
	 *
	 * This mapping is done to make it easier to
	 *
	 * @param DataObject $dataObject
	 * @return array
	 */
	protected function objectToFields($dataObject)
	{
		$ret = array();

		$fields = Object::combined_static($dataObject->ClassName, 'db');
		$fields['Created'] = 'SS_Datetime';
		$fields['LastEdited'] = 'SS_Datetime';

		foreach($fields as $name => $type) {
			if (preg_match('/^(\w+)\(/', $type, $match)) {
				$type = $match[1];
			}

			// Just index everything; the query can figure out what to exclude... !
			$value = $dataObject->$name;

			if ($type == 'MultiValueField') {
				$value = $value->getValues();
				if (!$value || count($value) == 0) {
					continue;
				}
			}

			$ret[$name] = array('Type' => $type, 'Value' => $value);
		}

		return $ret;
	}
	
	/**
	 * Delete a data object from the index
	 * 
	 * @param DataObject $object
	 */
	public function unindex($type, $id)
	{
		$this->getSolr()->deleteById($type.'_'.$id);
	}

	/**
	 * Parse a raw user search string into a query appropriate for
	 * execution.
	 *
	 * @param String $query
	 */
	public function parseSearch($query)
	{
		$escaped = str_replace(array('"', "'"), array('\"', "\'"), $query);

		return 'title:"'.$escaped.'" content_t:"'.$escaped.'"';
	}
	
	/**
	 * Perform a raw query against the search index, returning a SolrResultSet object that 
	 * can be used to extract a more complete result set
	 *
	 * @param String $query
	 * 			The lucene query to execute.
	 * @param int $page
	 * 			What result page are we on?
	 * @param int $limit
	 * 			How many items to limit the query to return
	 * @param array $params
	 * 			A set of parameters to be passed along with the query
	 * @return SolrResultSet
	 */
	public function query($query, $offset = 0, $limit = 20, $params = array()) {
		// execute the query
		$response = $this->getSolr()->search($query, $offset, $limit, $params);
		$params = new stdClass();
		$params->offset = $offset;
		$params->limit = $limit;
		$params->params = $params;

		return new SolrResultSet($query, $response, $params, $this);
	}


	/**
	 * Method used to return details about the facets stored for content, if any, for an empty query.
	 *
	 * Note - if you're wanting to perform actual queries using faceting information, please
	 * manually add the faceting information into the $params array during the query! This
	 * method is purely for convenience!
	 *
	 * @param $fields
	 *			An array of fields to get facet information for
	 *
	 */
	public function getFacetsForFields($fields, $number=10) {
		if (!is_array($fields)) {
			$fields = array($fields);
		}

		return $this->query('*:*', 0, 1, array('facet'=>'true', 'facet.field' => $fields, 'facet.limit' => 10, 'facet.mincount' => 1));
	}
	
	protected $client;
	
	/**
	 * Get the solr service client
	 * 
	 * @return Apache_Solr_Service
	 */
	public function getSolr()
	{
		if (!$this->client) {
			$this->client = new Apache_Solr_Service(self::$solr_details['host'],  self::$solr_details['port'], self::$solr_details['context']);
		} 
		
		return $this->client;
	}
}

/**
 * Class that defines how fields should be mapped to Solr properties
 * 
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 *
 */
class SolrSchemaMapper
{
	private $solrFields = array(
		'Title' => 'title',
		'ClassName' => 'content_type',
		'LastEdited' => 'last_modified',
		'Content' => 'text',
	);

	/**
	 * Map a SilverStripe field to a Solr field
	 *
	 * @param String $field
	 *          The field name
	 * @param String $type
	 *          The field type
	 * @param String $value
	 *			The value being stored (needed if a multival)
	 * 
	 * @return String
	 */
	public function mapType($field, $type, $value)
	{
		if (isset($this->solrFields[$field])) {
			return $this->solrFields[$field];
		}

		if (strpos($type, '(')) {
			$type = substr($type, 0, strpos($type, '('));
		}

		// otherwise, lets use a generic field for it
		switch ($type) {
			case 'MultiValueField': {
				return $field.'_ms';
			}
			case 'Text':
			case 'HTMLText': {
				return $field.'_t';
			}
			case 'SS_Datetime': {
				return $field.'_dt';
			}
			case 'Enum':
			case 'Varchar': {
				return $field.'_ms';
			}
			case 'Integer': {
				return $field.'_i';
			}
			default: {
				return $field.'_ms';
			}
		}
	}

	/**
	 * Convert a value to a format handled by solr
	 * 
	 * @param mixed $value
	 * @param string $type
	 * @return mixed
	 */
	public function mapValue($value, $type)
	{
		if (is_array($value)) {
			$newReturn = array();
			foreach ($value as $v) {
				$newReturn[] = $this->mapValue($v, $type);
			}
			return $newReturn;
		} else {
			switch ($type) {
				case 'SS_Datetime': {
					// we don't want a complete iso8601 date, we want it 
					// in UTC time with a Z at the end. It's okay, php's
					// strtotime will correctly re-convert this to the correct
					// timestamp, but this is how Solr wants things
					$hoursToRemove = date('Z');
					$ts = strtotime($value) - $hoursToRemove;

					return date('o-m-d\TH:i:s\Z', $ts);
				}
				case 'HTMLText': {
					return strip_tags($value);
				}
				default: {
					return $value;
				}
			}
		}
	} 
}

?>