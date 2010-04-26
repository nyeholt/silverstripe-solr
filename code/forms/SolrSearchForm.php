<?php
/*

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
 * A generalised search form that uses an arbitrary SearchService to return results
 * 
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 */
class SolrSearchForm extends SearchForm
{
	/**
	 * Return dataObjectSet of the results using $_REQUEST to get info from form.
	 * Wraps around {@link searchEngine()}.
	 *
	 * @param int $pageLength DEPRECATED 2.3 Use SearchForm->pageLength
	 * @param array $data Request data as an associative array. Should contain at least a key 'Search' with all searched keywords.
	 * @return DataObjectSet
	 */
	public function getResults($pageLength = 2, $data = null){
	 	// legacy usage: $data was defaulting to $_REQUEST, parameter not passed in doc.silverstripe.org tutorials
		if(!isset($data) || !is_array($data)) $data = $_REQUEST;

		// set language (if present)
		if(singleton('SiteTree')->hasExtension('Translatable') && isset($data['locale'])) {
			$origLocale = Translatable::get_current_locale();
			Translatable::set_current_locale($data['locale']);
		}

	 	$query = isset($data['Search']) ? $data['Search'] : '';
		$searchService = singleton('SolrSearchService');
		$query = $searchService->parseSearch($query);

		if(!$pageLength) {
			$pageLength = $this->pageLength;
		}

		$start = isset($_GET['start']) ? (int)$_GET['start'] : 0;

		// TODO: Add sorting options from the request params
		$params = array('sort' => 'score desc', 'fl' => '*,score');
		
		$results = $searchService->query($query, $start, $pageLength, $params);
		$results = $results->getDataObjects();

		if($results) {
			foreach($results as $result) {
				if(!$result->canView()) {
					$results->remove($result);
				}
			}
		}

		return $results;
	}
}
?>