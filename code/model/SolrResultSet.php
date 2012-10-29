<?php

/**
 * A Solr result set that provides access to results of a solr query, either as
 * a data object set, or as more specific solr items
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 */
class SolrResultSet
{

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
	public function getDataObjects($evaluatePermissions=false) {
		if (!$this->dataObjects) {
			$this->dataObjects = ArrayList::create();

			$result = $this->getResult();
			$documents = $result->response;

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
						singleton('SolrUtils')->log("Invalid solr document ID $doc->id", SS_Log::WARN);
						continue;
					}

					// a double sanity check for the stage here. 
					if ($currentStage = Versioned::current_stage()) {
						if ($currentStage != $stage) {
							continue;
						}
					}
					if (strpos($id, SolrSearchService::RAW_DATA_KEY) === 0) {
						$data = array(
							'ID'		=> $id,
							'Title'		=> $doc->title[0],
							'Link'		=> $doc->attr_SS_URL[0],
						);

						foreach ($doc as $key => $val) {
							if (strpos($key, 'attr_') === 0 && $key != 'attr_SS_URL') {
								$name = str_replace('attr_', '', $key);
								$val = $doc->$key;
								if (is_array($val) && count($val) == 1) {
									$data[$name] = $val[0];
								} else {
									$data[$name] = $val;
								}
							}
						}

						$object = new ArrayData($data);
					} else {
						if (!class_exists($type)) {
							continue;
						}
						$object = DataObject::get_by_id($type, $id);
					}

					if ($object && $object->ID) {
						// check that the user has permission
						if (isset($doc->score)) {
							$object->SearchScore = $doc->score;
						}

						if (!$evaluatePermissions || $object->canView()) {
							$this->dataObjects->push($object);
						}

						$totalAdded++;
					} else {
						singleton('SolrUtils')->log("Object $doc->id is no longer in the system, removing from index", SS_Log::WARN);
						$this->solr->unindex($type, $id);
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
		
		return $facets;
	}
}
