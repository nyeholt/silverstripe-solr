Solr Search Module
==================

Maintainer Contact
------------------
Marcus Nyeholt
<marcus (at) silverstripe (dot) com (dot) au>

Requirements
------------
Solr installed and running (a test instance is included, but for production
use, please install and configure)

Documentation
-------------
[GitHub Wiki](http://wiki.github.com/nyeholt/silverstripe-solr)

Licensing
-----------------------------------------------
Solr is licensed under the Apache License
This module is licensed under the BSD license

Quick Usage Overview
-----------------------------------------------

## Install Solr (packages available for most OSes)

For demonstration and testing purposes, a standalone Jetty based
installation of solr is available in the solr/ subdirectory. To execute,
simply change to that directory and run java -jar start.jar - the default
settings will be fine for evaluation.

If you are running a custom Solr instance, make sure to copy the
*solr/solr/solr/conf/schema.xml* file to your solr instance - there are
a couple of custom types defined that SilverStripe uses. 

If you have a configuration different to the default locahost:8983/solr
configuration, you can configure things by calling

`SolrSearchService::$solr_details = array();`

## Add the extension to your pages

Add the SolrSearchable decorator to any SiteTree objects you want to search.
Support for other data objects may work, though file indexing is not yet
supported

## Configure your search page

This module creates a new page of type _SolrSearchPage_ in your site's root.
This should be published before being able to perform searches.

## Change Page.php to redirect to the SolrSearchPage

Finally, the search mechanism needs to be hooked up to redirect to the Solr
Search Page for processing results. Add the following to your Page.php

`
	public function getSearchPage() {
		return DataObject::get_one('SolrSearchPage');
	}
`

and change the `results()` action handler to

`
	public function results() {
		$searchText = isset($_REQUEST['Search']) ? $_REQUEST['Search'] : 'Search';
		Director::redirect($this->getSearchPage()->Link('results').'?Search='.rawurlencode($searchText));
	}
`

API
---

By default, the indexing mechanism uses the SolrSchemaMapper class as the 
mechanism for mapping between silverstripe DataObjects and items that can 
be stored in a Solr Schema. If you are using the default schema.xml for solr, 
then you only have a few data types you can play with, which explains why
the default mapper class only indexes a few properties. 

To improve this, or be more specific with how things are stored, you'll 
need to provide your own implementation that expands on some of the
information stored - more details on how exactly to do this will be
provided at a later point in time


Troubleshooting
---------------

If you aren't getting any search results, first make sure Solr is running. Next, check to make sure that
