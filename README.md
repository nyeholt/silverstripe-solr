Solr Search Module
==================

Maintainer Contact
------------------
Marcus Nyeholt
<marcus (at) silverstripe (dot) com (dot) au>

Requirements
------------

* Solr installed and running (a test instance is included, but for production
use, please install and configure)
* The multivaluefield module from http://github.com/nyeholt/silverstripe/silverstripe-multivaluefield

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

	DataObject::add_extension('SiteTree', 'SolrIndexable');

By default, the solr indexer will index Title and Content fields. If you want
other fields indexed too, add them to your $searchable_fields static
variable in your class type. 

## Configure your search page

This module creates a new page of type _SolrSearchPage_ in your site's root.
This should be published before being able to perform searches.

## Add the search extension

Finally, the search mechanism needs to be hooked up to your pages. This can be done
by adding the SolrSearchExtension to your Page_Controller class to make available
the various template hooks

	Object::add_extension('Page_Controller', 'SolrSearchExtension');

Now, add your searchform wherever you like in your Page template using $SearchForm


## Using facets

First, you need to tell the search page what you're going to be faceting on

	SolrSearchPage::$facets = array('MetaKeywords_ms');

then make sure that field (MetaKeywords) is included in the list of fields to
index via the searchable_fields static

Template Options
----------------

To customise search results display, provide a SolrSearchPage_results.ss
file in your theme's templates directory. 

API
---

[GitHub Wiki](http://wiki.github.com/nyeholt/silverstripe-solr)


# Administration

If you have ADMIN privileges, you can start and stop the locally bundled
jetty version of solr from within the CMS on the Solr admin section.

To set the java path (if different from /usr/bin/java), set

    SolrSearchService::$java_bin 

to the appropriate path


Troubleshooting
---------------

If you aren't getting any search results, first make sure Solr is running. 


