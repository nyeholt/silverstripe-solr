<?php

// Add to mysite/_config.php

/*
DataObject::add_extension('SiteTree', 'SolrIndexable');
Object::add_extension('Page_Controller', 'SolrSearchExtension');
 */

// To enable faceting, you need to specify which fields should be available
// SolrSearchPage::$facets = array ('AlcKeywords_ms', 'AlcPerson_ms', 'AlcCompany_ms', 'AlcOrganization_ms');


// You will also need to specify a solr configuration if your solr application is on a different host:port than
// the default
// SolrSearchService::$solr_details = array();

if (($solr_module_dir = basename(dirname(__FILE__))) != 'solr') {
	exit("The solr module must be installed in /solr, not in $solr_module_dir");
}

if (!class_exists('MultiValueField')) {
	exit("The solr module requires the multivaluefield module from https://github.com/nyeholt/silverstripe-multivaluefield");
}