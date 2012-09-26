<?php

/**
 * A search service built around Solr
 * 
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 */
class SolrSearchService {
	const RAW_DATA_KEY = 'SOLRRAWDATA';

	public static $config_file = 'solr/config';
	public static $java_bin = '/usr/bin/java';

	/**
	 * The connection details for the solr instance to connect to
	 *
	 * @var array
	 */
	public static $solr_details = array(
		'host' => 'localhost',
		'port' => '8983',
		'context' => '/solr',
		'data_dir' => null
	);

	/**
	 * A list of all fields that will be searched through by default, if the user hasn't specified
	 * any in their search query. 
	 * 
	 * @var array 
	 */
	public static $default_query_fields = array(
		'title',
		'text'
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
	
	/**
	 * A cache object for query caching
	 * 
	 * @var Zend_Cache_Core
	 */
	protected $cache;
	
	/**
	 * How many seconds to cache results for
	 *
	 * @var int
	 */
	protected $cacheTime = 3600;
	
	/**
	 * A mapping of all the available query builders
	 *
	 * @var map
	 */
	protected $queryBuilders = array();

	public function __construct() {
		$m = self::$mapper_class;
		$this->mapper = new $m;

		$this->queryBuilders['default'] = 'SolrQueryBuilder';
		$this->queryBuilders['dismax'] = 'DismaxSolrSearchBuilder';
	}
	
	public function setCache($cache) {
		$this->cache = $cache;
	}

	/**
	 * Is solr alive?
	 *
	 * @return boolean
	 */
	public function isConnected() {
		return $this->getSolr()->ping();
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
	 * Add a field to be included in default searches
	 *
	 * @param string $field 
	 */
	public function add_default_query_field($field) {
		self::$default_query_fields[] = $field;
	}

	/**
	 * Add a new query parser into the service
	 *
	 * @param string $name
	 * @param object $obj 
	 */
	public function addQueryBuilder($name, $obj) {
		$this->queryBuilders[$name] = $obj;
	}

	/**
	 * Gets the list of query parsers available
	 *
	 * @return array
	 */
	public function getQueryBuilders() {
		return $this->queryBuilders;
	}

	/**
	 * Gets the query builder for the given search type
	 *
	 * @param SolrQueryBuilder $type 
	 */
	public function getQueryBuilder($type='default') {
		return isset($this->queryBuilders[$type]) ? new $this->queryBuilders[$type] : new $this->queryBuilders['default'];
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
	 * @param DataObject $object
	 * 				The object being indexed
	 * @param String $stage
	 * 				If we're indexing for a particular stage or not. 
	 *
	 */
	public function index($dataObject, $stage=null) {
		$document = new Apache_Solr_Document();
		$fieldsToIndex = array();

		$id = 0;
		if (is_object($dataObject)) {
			$fieldsToIndex = $this->getSearchableFieldsFor($dataObject); // $dataObject->searchableFields();
			$object = $this->objectToFields($dataObject);
			$id = $dataObject->ID;
		} else {
			$object = $dataObject;
			$id = isset($dataObject['ID']) ? $dataObject['ID'] : 0;

			$fieldsToIndex = isset($object['index_fields']) ? $object['index_fields'] : array(
				'Title' => array(),
				'Content' => array(),
			);
		}

		$fieldsToIndex['SS_URL'] = true;
		$fieldsToIndex['SS_ID'] = true;
		$fieldsToIndex['LastEdited'] = true;
		$fieldsToIndex['Created'] = true;
		$fieldsToIndex['ClassName'] = true;
		$fieldsToIndex['ClassNameHierarchy'] = true;
		$fieldsToIndex['ParentsHierarchy'] = true;

		// the stage we're on when we write this doc to the index.
		// this is used for versioned AND non-versioned objects; we just cheat and
		// set it BOTH stages if it's non-versioned object
		$fieldsToIndex['SS_Stage'] = true;

		// if it's a versioned object, just save ONE stage value. 
		if ($stage) {
			$object['SS_Stage'] = array('Type' => 'Enum', 'Value' => $stage);
			$id = $id . '_' . $stage;
		} else {
			$object['SS_Stage'] = array('Type' => 'Enum', 'Value' => array('Stage', 'Live'));
		}

		// specially handle the subsite module - this has serious implications for our search
		// @TODO we want to genercise this later for other modules to hook into it!
		if (ClassInfo::exists('Subsite')) {
			$fieldsToIndex['SubsiteID'] = true;
			if (is_object($dataObject)) {
				$object['SubsiteID'] = array('Type' => 'Int', 'Value' => $dataObject->SubsiteID);
			}
		}

		$classType = isset($object['ClassName']) ? $object['ClassName']['Value'] : 'INVALID_CLASS_TYPE';

		// we're not indexing these fields just at the moment because the conflict
		unset($object['ID']);

		// a special type hierarchy 
		if ($classType != 'INVALID_CLASS_TYPE') {
			$classes = array_values(ClassInfo::ancestry($classType));
			$object['ClassNameHierarchy'] = array(
				'Type' => 'MultiValueField',
				'Value' => $classes,
			);
			
			$object['ParentsHierarchy'] = $this->getParentsHierarchyField($dataObject);
		}

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

			$fieldName = $this->mapper->mapType($field, $type, $fieldsToIndex[$field]);

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
				$document->id = $classType . '_' . $id;
				$this->getSolr()->addDocument($document);
				$this->getSolr()->commit();
				$this->getSolr()->optimize();
			} catch (Exception $ie) {
				SS_Log::log($ie, SS_Log::ERR);
			}
		}
	}
	
	/**
	 * Get a solr field representing the parents hierarchy (if applicable)
	 * 
	 * @param type $dataObject 
	 */
	protected function getParentsHierarchyField($dataObject) {
		
		// see if we've got Parent values
		if ($dataObject->hasField('ParentID')) {
			$parentsField = array('Type' => '', 'Value' => null);
			$parents = array();
			
			$parent = $dataObject;
			while ($parent && $parent->ParentID) {
				$parents[] = $parent->ParentID;
				$parent = $parent->Parent();
			}
			$parentsField['Value'] = $parents;
			return $parentsField;
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
	protected function objectToFields($dataObject) {
		$ret = array();

		$fields = Object::combined_static($dataObject->ClassName, 'db');
		$fields['Created'] = 'SS_Datetime';
		$fields['LastEdited'] = 'SS_Datetime';

		$ret['ClassName'] = array('Type' => 'Varchar', 'Value' => $dataObject->class);
		$ret['SS_ID'] = array('Type' => 'Int', 'Value' => $dataObject->ID);

		foreach ($fields as $name => $type) {
			if (preg_match('/^(\w+)\(/', $type, $match)) {
				$type = $match[1];
			}

			// Just index everything; the query can figure out what to exclude... !
			$value = $dataObject->$name;

			if ($type == 'MultiValueField') {
				$value = $value->getValues();
//				if (!$value || count($value) == 0) {
//					continue;
//				}
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
	public function unindex($type, $id=null) {
		if (is_object($type)) {
			$id = $type->ID;
			$type = $type->class; // get_class($type);
		}
		try {
			// delete all published/non-published versions of this item. 
			$this->getSolr()->deleteByQuery('id:' . $type . '_' . $id . '*');
			$this->getSolr()->commit();
		} catch (Exception $ie) {
			SS_Log::log($ie, SS_Log::ERR);
		}
	}

	/**
	 * Parse a raw user search string into a query appropriate for
	 * execution.
	 *
	 * @param String $query
	 */
	public function parseSearch($query, $type='default') {
		// if there's a colon in the search, assume that the user is doing a custom power search
		if (strpos($query, ':')) {
			return $query;
		}

		if (isset($this->queryBuilders[$type])) {
			return $this->queryBuilders[$type]->parse($query);
		}

		$lucene = implode(':' . $query . ' OR ', self::$default_query_fields) . ':' . $query;
		return $lucene;
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
		if (is_string($query)) {
			$builder = $this->getQueryBuilder('default');
			$builder->baseQuery($query);
			$query = $builder;
		}
		// be very specific about the subsite support :). 
		if (ClassInfo::exists('Subsite')) {
			$query->andWith('SubsiteID_i', Subsite::currentSubsiteID());
			// $query = "($query) AND (SubsiteID_i:".Subsite::currentSubsiteID().')';
		}

		// add the stage details in - we should probably use an extension mechanism for this,
		// but for now this will have to do. @TODO Refactor this....
		$stage = Versioned::current_stage();
		if (!$stage && !(isset($params['ignore_stage']) && $params['ignore_stage'])) {
			// default to searching live content only
			$stage = 'Live';
		}

		if(!isset($params['ignore_stage']) || !$params['ignore_stage']) {
			$query->andWith('SS_Stage_ms', $stage);
		}

		$extraParams = $query->getParams();
		$params = array_merge($params, $extraParams);

		$query = $query->toString();

		$response = null;
		$rawResponse = null;
		$solr = $this->getSolr();
		$key = null;
		if ($this->cache) {
			$key = md5($query.$offset.$limit.serialize($params));
			if ($rawResponse = $this->cache->load($key)) {
				$response = new Apache_Solr_Response(
					$rawResponse, 
					// we fake the following headers... :o
					array(
						'HTTP/1.1 200 OK',
						'Content-Type: text/plain; charset=utf-8'
					), 
					$solr->getCreateDocuments(), 
					$solr->getCollapseSingleValueArrays()
				);
			}
		}
		if (!$response) {
			// execute the query
			if ($this->isConnected()) {
				$response = $this->getSolr()->search($query, $offset, $limit, $params);
			}
		}
		
		$queryParams = new stdClass();
		$queryParams->offset = $offset;
		$queryParams->limit = $limit;
		$queryParams->params = $params;

		$results = new SolrResultSet($query, $response, $queryParams, $this);
		
		if ($this->cache && !$rawResponse && $key && $response) {
			$this->cache->save($response->getRawResponse(), $key, array(), $this->cacheTime);
		}
		
		return $results;
	}

	/**
	 * Method used to return details about the facets stored for content, if any, for an empty query.
	 *
	 * Note - if you're wanting to perform actual queries using faceting information, please
	 * manually add the faceting information into the $params array during the query! This
	 * method is purely for convenience!
	 *
	 * @param $fields
	 * 			An array of fields to get facet information for
	 *
	 */
	public function getFacetsForFields($fields, $number=10) {
		if (!is_array($fields)) {
			$fields = array($fields);
		}

		return $this->query('*', 0, 1, array('facet' => 'true', 'facet.field' => $fields, 'facet.limit' => 10, 'facet.mincount' => 1));
	}

	protected $client;

	/**
	 * Get the solr service client
	 * 
	 * @return Apache_Solr_Service
	 */
	public function getSolr() {
		if (!$this->client) {
			$this->client = new Apache_Solr_Service(self::$solr_details['host'], self::$solr_details['port'], self::$solr_details['context']);
		}

		return $this->client;
	}

	/**
	 * Get all the fields that can be indexed / searched on for a particular type
	 *
	 * @param string $className 
	 */
	public function getSearchableFieldsFor($className) {
		if (is_object($className)) {
			$className = get_class($className);
		}

		$searchable = $this->buildSearchableFieldCache();
		$hierarchy = array_reverse(ClassInfo::ancestry($className));

		foreach ($hierarchy as $class) {
			if (isset($searchable[$class])) {
				return $searchable[$class];
			}
		}

		$sng = singleton($className);

		if($sng->hasMethod('getSolrSearchableFields')) {
			return $sng->getSolrSearchableFields();
		} else {
			return $sng->searchableFields();
		}
	}
	
	/**
	 * Get all the searchable fields for a given set of classes
	 * @param type $classNames 
	 */
	public function getAllSearchableFieldsFor($classNames) {
		$allfields = array();
		foreach ($classNames as $className) {
			$fields = $this->getSearchableFieldsFor($className);
			$allfields = array_merge($allfields, $fields);
		}
		
		return $allfields;
	}

	protected $searchableCache = array();

	/**
	 * Builds up the searchable fields configuration baased on the solrtypeconfiguration objects
	 */
	protected function buildSearchableFieldCache() {
		if (!$this->searchableCache) {
			$objects = DataObject::get('SolrTypeConfiguration');
			if ($objects) {
				foreach ($objects as $obj) {
					$this->searchableCache[$obj->Title] = $obj->FieldMappings->getValues();
				}
			}
		}
		return $this->searchableCache;
	}

	/**
	 * Return the field name for a given property within a given set of data object types
	 * 
	 * First matching data object with that field is used
	 *
	 * @param String $field
	 * 				The field name to get the Solr type for.
	 * @param String $classNames
	 * 				A list of data object class name. Defaults to 'page'. 
	 *
	 * @return String
	 *
	 */
	public function getSolrFieldName($field, $classNames = array('Page')) {
		if (!is_array($classNames)) {
			$classNames = array($classNames);
		}

		foreach ($classNames as $className) {
			$dummy = singleton($className);
			$fields = $this->objectToFields($dummy);
			if ($field == 'ID') {
				$field = 'SS_ID';
			}
			if (isset($fields[$field])) {
				$configForType = $this->getSearchableFieldsFor($className);
				$hint = isset($configForType[$field]) ? $configForType[$field] : false;
				return $this->mapper->mapType($field, $fields[$field]['Type'], $hint);
			}
		}
	}

	/**
	 * Get a field name used for sorting in a query. This is just a hardcoded
	 * way at the moment to handle the fact that to sort by 'Title', you
	 * actually want to sort by title_exact (due to tokenization in solr). 
	 *
	 * @param String $field
	 * 				The field name to get the Solr type for.
	 * @param String $classNames
	 * 				A list of potential class types that the field may exist in (ie if searching in multiple types)
	 */
	public function getSortFieldName($field, $classNames = array('Page')) {
		if ($field == 'Title') {
			return 'title_exact';
		}
		if (!is_array($classNames)) {
			$classNames = array($classNames);
		}
		return $this->getSolrFieldName($field, $classNames);
	}

	/**
	 * get local service information
	 */
	public function localEngineConfig() {
		$config = DataObject::get_one('SolrServerConfig');
		return $config;
	}

	public function localEngineStatus() {
		$config = $this->localEngineConfig();
		$id = '-Dsolrid=' . $config->InstanceID;
		$cmd = "ps aux | awk '/$id/ && !/awk/ {print $2}'";
		$status = `$cmd`;
		return trim($status);
	}

	public function startSolr() {
		if (!Permission::check('ADMIN')) {
			return false;
		}
		$status = $this->localEngineStatus();
		if (strlen($status)) {
			return;
		}
		$config = $this->localEngineConfig();

		$solrJar = Director::baseFolder() . '/solr/solr/start.jar';
		$logFile = $config->getLogFile();
		$id = $config->InstanceID;

		$curdir = getcwd();
		chdir(dirname($solrJar));

		$logDir = dirname($solrJar).'/logs';
		if (!is_dir($logDir)) {
			mkdir($logDir, 2775);
		}

		$port = self::$solr_details['port'];

		$dataDir = '';
		if (isset(self::$solr_details['data_dir']) && strlen(self::$solr_details['data_dir'])) {
			$dataDir = ' -Dsolr.data.dir=' . self::$solr_details['data_dir'];
		}

		$cmd = self::$java_bin . " -Djetty.port=$port $dataDir -Dsolrid=$id -jar $solrJar > $logFile 2>&1 &";
		system($cmd);
		chdir($curdir);
	}

	public function stopSolr() {
		if (!Permission::check('ADMIN')) {
			return false;
		}
		$status = $this->localEngineStatus();
		if ($status) {
			$cmd = "kill $status";
			system($cmd);
		}
	}

	public function saveEngineConfig($config) {
		if (!Permission::check('ADMIN')) {
			return false;
		}
		$config->write();
	}

	public function getLogData($numLines = 100) {
		if (!Permission::check('ADMIN')) {
			return false;
		}
		$config = $this->localEngineConfig();
		$logFile = $config->getLogFile();

		if (file_exists($logFile)) {
			$log = explode("\n", $this->tail($logFile));
			return $log;
		}

		return array();
	}

	protected function tail($file, $num_to_get=50) {
		$fp = fopen($file, 'r');
		$position = filesize($file);
		fseek($fp, $position - 1);
		$chunklen = 4096;
		$data = '';
		while ($position >= 0) {
			$position = $position - $chunklen;
			if ($position < 0) {
				$chunklen = abs($position);
				$position = 0;
			}
			fseek($fp, $position);
			$data = fread($fp, $chunklen) . $data;
			if (substr_count($data, "\n") >= $num_to_get + 1) {
				preg_match("!(.*?\n){" . ($num_to_get - 1) . "}$!", $data, $match);
				return $match[0];
			}
		}
		fclose($fp);
		return $data;
	}

}

/**
 * Class that defines how fields should be mapped to Solr properties
 * 
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 */
class SolrSchemaMapper {

	protected $solrFields = array(
		'Title' => 'title',
		'LastEdited' => 'last_modified',
		'Content' => 'text',
		'ClassNameHierarchy' => 'ClassNameHierarchy_ms',
		'ParentsHierarchy'	=> 'ParentsHierarchy_ms',
		'SS_Stage' => 'SS_Stage_ms',
	);

	/**
	 * Map a SilverStripe field to a Solr field
	 *
	 * @param String $field
	 *          The field name
	 * @param String $type
	 *          The field type
	 * @param String $value
	 * 			The value being stored (needed if a multival)
	 * 
	 * @return String
	 */
	public function mapType($field, $type, $hint = '') {
		if (isset($this->solrFields[$field])) {
			return $this->solrFields[$field];
		}

		if (strpos($type, '(')) {
			$type = substr($type, 0, strpos($type, '('));
		}

		if ($hint && is_string($hint) && $hint != 'default') {
			return str_replace(':field', $field, $hint);
		}

		// otherwise, lets use a generic field for it
		switch ($type) {
			case 'MultiValueField': {
					return $field . '_mt';
				}
			case 'Text':
			case 'HTMLText': {
					return $field . '_t';
				}
			case 'SS_Datetime': {
					return $field . '_dt';
				}
			case 'Enum': {
					return $field . '_ms';
				}
			case 'Varchar': {
				return $field . '_mt';
			}
			case 'Attr': {
				return 'attr_' . $field;
			}
			case 'Double':
			case 'Decimal':
			case 'Float':
			case 'Money': {
				return $field . '_f';
			}
			case 'Int':
			case 'Integer': {
				return $field . '_i';
			}
			default: {
				return $field . '_mt';
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
	public function mapValue($value, $type) {
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
