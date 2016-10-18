<?php

/**
 * A Solr result set that provides access to results of a solr query, either as
 * a data object set, or as more specific solr items
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 */
class SolrResultSet {

	/**
	 * A list of solr field type suffixes to look for and swap out
	 */
	static $solr_attrs = array('as', 'ms', 's', 't', 'i', 'dt', 'f', 'p');
	
	/**
	 * The raw lucene query issued to solr
	 * @var String
	 */
	protected $luceneQuery;

	/**
	 * @var SolrSearchService
	 */
	protected $solr;

	/**
	 * The raw result from Solr
	 *
	 * @var String
	 */
	protected $response;

	/**
	 * The actual decoded search result
	 *
	 * @var StdClass
	 */
	protected $result;

	/**
	 * The list of data objects that is represented by this search result set
	 *
	 * @var DataObjectSet
	 */
	protected $dataObjects;

	/**
	 * The query parameters that were used for the query
	 *
	 * @var StdClass
	 */
	protected $queryParameters;

	/**
	 * The total number of results found in this query
	 *
	 * @var Int
	 */
	protected $totalResults;

	/**
	 * Create a new result set object
	 *
	 * @param $query
	 *			The raw lucene query issued to solr
	 */
    public function __construct($query, $rawResponse, $parameters, SolrSearchService $service) {
		$this->luceneQuery = $query;
		$this->response = $rawResponse;
		$this->queryParameters = $parameters;
		$this->solr = $service;
	}

	public function getErrors() {
		
	}

	/**
	 * @return String
	 *			The raw query issued to generate this result set
	 */
	public function getLuceneQuery() {
		return $this->luceneQuery;
	}

	/**
	 * Get all the parameters used in this query
	 *
	 */
	public function getQueryParameters() {
		return $this->queryParameters;
	}


	/**
	 * Gets the raw result set as an object graph.
	 *
	 * This is effectively the results as sent from solre
	 */
	public function getResult() {
		if (!$this->result && $this->response && $this->response->getHttpStatus() >= 200 && $this->response->getHttpStatus() < 300) {
			// decode the response
			$this->result = json_decode($this->response->getRawResponse());
		}

		return $this->result;
	}

	/**
	 * The number of results found for the given parameters.
	 *
	 * @return Int
	 */
	public function getTotalResults() {
		return $this->totalResults;
	}

	/**
	 * Return all the dataobjects that were found in this query
	 *
	 * @param $evaluatePermissions
	 *			Should we evaluate whether the user can view before adding the result to the dataset?
	 *
	 * @return DataObjectSet
	 */
	public function getDataObjects($evaluatePermissions=false, $expandRawObjects = true) {
		if (!$this->dataObjects) {
			$this->dataObjects = ArrayList::create();

			$result = $this->getResult();
			$documents = $result && isset($result->response) ? $result->response : null;

			if ($documents && isset($documents->docs)) {
				$totalAdded = 0;
				foreach ($documents->docs as $doc) {
					$bits = explode('_', $doc->id);
					if (count($bits) == 3) {
						list($type, $id, $stage) = $bits;
					} else {
						list($type, $id) = $bits;
						$stage = Versioned::current_stage();
					}
					
					if (!$type || !$id) {
						error_log("Invalid solr document ID $doc->id");
						continue;
					}

					if (strpos($doc->id, SolrSearchService::RAW_DATA_KEY) === 0) {
						$object = $this->inflateRawResult($doc, $expandRawObjects);

						// $object = new ArrayData($data);
					} else {
						if (!class_exists($type)) {
							continue;
						}
						// a double sanity check for the stage here. 
						if ($currentStage = Versioned::current_stage()) {
							if ($currentStage != $stage) {
								continue;
							}
						}

						$object = DataObject::get_by_id($type, $id);
					}

					if ($object && $object->ID) {
						// check that the user has permission
						if (isset($doc->score)) {
							$object->SearchScore = $doc->score;
						}
						
						$canAdd = true;
						if ($evaluatePermissions) {
							// check if we've got a way of evaluating perms
							if ($object->hasMethod('canView')) {
								$canAdd = $object->canView();
							}
						}

						if (!$evaluatePermissions || $canAdd) {
							if ($object->hasMethod('canShowInSearch')) {
								if ($object->canShowInSearch()) {
									$this->dataObjects->push($object);
								}
							} else {
								$this->dataObjects->push($object);
							}
						}

						$totalAdded++;
					} else {
						error_log("Object $doc->id is no longer in the system, removing from index");
                        $tmpObj = new stdClass();
                        $tmpObj->class = $type;
                        $tmpObj->ID = $id;
						$this->solr->unindex($tmpObj);
					}
				}
				$this->totalResults = $documents->numFound;
				
				// update the dos with stats about this query
				
				$this->dataObjects = PaginatedList::create($this->dataObjects);
				
				$this->dataObjects->setPageLength($this->queryParameters->limit)
						->setPageStart($documents->start)
						->setTotalItems($documents->numFound)
						->setLimitItems(false);
				
//				$paginatedSet->setPaginationFromQuery($set->dataQuery()->query());
				// $this->dataObjects->setPageLimits($documents->start, $this->queryParameters->limit, $documents->numFound);
			}

		}

		return $this->dataObjects;
	}
	
	/**
	 * Inflate a raw result into an object of a particular type
	 * 
	 * If the raw result has a SolrSearchService::SERIALIZED_OBJECT field,
	 * and convertToObject is true, that serialized data will be used to create
	 * a new object of type $doc['SS_TYPE']
	 * 
	 * @param array $doc
	 * @param boolean $convertToObject
	 */
	protected function inflateRawResult($doc, $convertToObject = true) {
		
		$field = SolrSearchService::SERIALIZED_OBJECT . '_t';
		if (isset($doc->$field) && $convertToObject) {
			$raw = unserialize($doc->$field);
			if (isset($raw['SS_TYPE'])) {
				$class = $raw['SS_TYPE'];

				$object = Injector::inst()->create($class);
				$object->update($raw);
				
				$object->ID = str_replace(SolrSearchService::RAW_DATA_KEY, '', $doc->id);
				
				return $object;
			} 
			
			return ArrayData::create($raw);
		}

		$data = array(
			'ID'		=> str_replace(SolrSearchService::RAW_DATA_KEY, '', $doc->id),
		);

		if (isset($doc->attr_SS_URL[0])) {
			$data['Link'] = $doc->attr_SS_URL[0];
		}
		
		if (isset($doc->title)) {
			$data['Title'] = $doc->title;
		}
		
		if (isset($doc->title_as)) {
			$data['Title'] = $doc->title_as;
		}

		foreach ($doc as $key => $val) {
			if ($key != 'attr_SS_URL') {
				$name = null;
				if (strpos($key, 'attr_') === 0) {
					$name = str_replace('attr_', '', $key);
				} else if (preg_match('/(.*?)_('. implode('|', self::$solr_attrs) .')$/', $key, $matches)) {
					$name = $matches[1];
				}

				$val = $doc->$key;
				if (is_array($val) && count($val) == 1) {
					$data[$name] = $val[0];
				} else {
					$data[$name] = $val;
				}
			}
		}
		
		return ArrayData::create($data);
	}

	protected $returnedFacets;
	
	/**
	 * Gets the details about facets found in this query
	 *
	 * @return array
	 *			An array of facet values in the format
	 *			array(
	 *				'field_name' => stdClass {
	 *					name,
	 *					count
	 *				}
	 *			)
	 */
	public function getFacets() {
		if ($this->returnedFacets) {
			return $this->returnedFacets;
		}
		
		$result = $this->getResult();
		if (!isset($result->facet_counts)) {
			return;
		}

		if (isset($result->facet_counts->exception)) {
			// $this->logger->error($result->facet_counts->exception)
			return array();
		}
		
		$elems = $result->facet_counts->facet_fields;
		
		$facets = array();
		foreach ($elems as $field => $values) {
			$elemVals = array();
			foreach ($values as $vname => $vcount) {
				if ($vname == '_empty_') {
					continue;
				}
				$r = new stdClass;
				$r->Name = $vname;
				$r->Query = $vname;
				$r->Count = $vcount;
				$elemVals[] = $r;
			}
			$facets[$field] = $elemVals;
		}
		
		// see if there's any query facets for things too
		$query_elems = $result->facet_counts->facet_queries;
		if ($query_elems) {
			foreach ($query_elems as $vname => $count) {
				if ($vname == '_empty_') {
					continue;
				}
				
				list($field, $query) = explode(':', $vname);

				$r = new stdClass;
				$r->Type = 'query';
				$r->Name = $vname;
				$r->Query = $query;
				$r->Count = $count;
				
				$existing = isset($facets[$field]) ? $facets[$field] : array();
				$existing[] = $r;
				$facets[$field] = $existing;
			}
		}
		
		$this->returnedFacets = $facets;
		return $this->returnedFacets;
	}

	/**
	 * Gets the query's elapsed time.
	 *
	 * @return Int
	 */
	public function getTimeTaken() {
		return ($this->result ? $this->result->responseHeader->QTime : null);
	}
}
