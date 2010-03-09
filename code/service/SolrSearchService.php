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
	
	public function __construct()
	{
		$m = self::$mapper_class;
		$this->mapper = new $m;
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
	public function index($object)
	{
		$document = new Apache_Solr_Document();

		if (is_object($object)) {
			$o = $object;
			$object = $this->objectToFields($object);
			$object['ID'] = $o->ID;
			$object['ClassName'] = $o->class;
			unset($o);
		}
		
		$id = isset($object['ID']) ? $object['ID'] : false;
		unset($object['ID']);
		$classType = isset($object['ClassName']) ? $object['ClassName'] : false;
		unset($object['ClassName']);

		foreach ($object as $field => $valueDesc) {
			if (!is_array($valueDesc)) {
				continue;
			}

			$type = $valueDesc['Type'];
			$value = $valueDesc['Value'];

			$fieldName = $this->mapper->mapType($field, $type);

			if (!$fieldName) {
				continue;
			}

			$value = $this->mapper->mapValue($value, $type);

			if (is_array($value)) {
				$document->setMultiValue($fieldName, $value);
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
				SS_Log::log($ie->getMessage(), SS_Log::ERR);
				SS_Log::log($ie->getTraceAsString(), SS_Log::ERR);
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

		$fieldsToIndex = $dataObject->searchableFields();

		foreach (ClassInfo::ancestry($dataObject->class, true) as $class) {
			$fields = DataObject::database_fields($class);
			if ($fields) {
				foreach($fields as $name => $type) {
					if (preg_match('/^(\w+)\(/', $type, $match)) {
						$type = $match[1];
					}

					// Just index everything; the query can figure out what to
					// exclude... !

					// if (isset($fieldsToIndex[$name])) {
					$ret[$name] = array('Type' => $type, 'Value' => $dataObject->$name);
					// }
				}
			}
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
	 * Perform a raw query against the search index
	 * 
	 * @param String $query
	 * 			The lucene query to execute. 
	 * @param int $page
	 * 			What result page are we on?
	 * @param int $limit
	 * 			How many items to limit the query to
	 * @param array $params
	 * 			A set of parameters to be passed along with the query
	 * @return Array
	 */
	public function queryLucene($query, $offset = 0, $limit = 10, $params = array())
	{
		// execute the query, and return the results, then map them back to 
		// data objects
		$response = $this->getSolr()->search($query, $offset, $limit, $params);
		
		if ($response->getHttpStatus() >= 200 && $response->getHttpStatus() < 300) {
			// decode the response
			$response = json_decode($response->getRawResponse());
			return $response->response;
		}

		return null;
	}

	/**
	 * Perform a raw query against the search index, then convert the results
	 * into a dataobjectset
	 *
	 * @param String $query
	 * 			The lucene query to execute.
	 * @param int $page
	 * 			What result page are we on?
	 * @param int $limit
	 * 			How many items to limit the query to
	 * @param array $params
	 * 			A set of parameters to be passed along with the query
	 * @return Array
	 */
	public function queryDataObjects($query, $offset = 0, $limit = 10, $params = array())
	{
		$items = new DataObjectSet();

	    $documents = $this->queryLucene($query, $offset, $limit, $params);
	    if ($documents && isset($documents->docs)) {
			$totalAdded = 0;
			foreach ($documents->docs as $doc) {
				list($type, $id) = explode('_', $doc->id);
				if (!$type || !$id) {
					SS_Log::log("Invalid solr document ID $doc->id", SS_Log::ERR);
					continue;
				}

				$object = DataObject::get_by_id($type, $id);
				if ($object && $object->ID) {
					// check that the user has permission
					if (isset($doc->score)) {
						$object->SearchScore = $doc->score;
					}

					$items->push($object);
					$totalAdded++;
				} else {
					SS_Log::log("Object $doc->id is no longer in the system, removing from index", SS_Log::ERR);
					$this->unindex($type, $id);
				}
			}

			// update the dos with stats about this query
			$items->setPageLimits($documents->start, $limit, $documents->numFound);
	    }
		return $items;
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
		'Content' => 'content_t',
	);

        /**
         * Map a SilverStripe field to a Solr field
         *
         * @param String $field
         *          The field name
         * @param String $type
         *          The field type
         * @return String
         */
	public function mapType($field, $type)
	{
		if (isset($this->solrFields[$field])) {
			return $this->solrFields[$field];
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
					// timestamp 
					$hoursToRemove = date('Z');
					$ts = strtotime($value) - $hoursToRemove;

					return date('o-m-d\TH:i:s\Z', $ts);
				}
				default: {
					return $value;
				}
			}
		}
	} 
}

?>