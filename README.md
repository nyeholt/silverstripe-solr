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

	DataObject::add_extension('SiteTree', 'SolrIndexable');

## Configure your search page

This module creates a new page of type _SolrSearchPage_ in your site's root.
This should be published before being able to perform searches.

## Change Page.php to redirect to the SolrSearchPage

Finally, the search mechanism needs to be hooked up to your pages. This can be done
by adding the SolrSearchExtension to your Page_Controller class to make available
the various template hooks

	Object::add_extension('Page_Controller', 'SolrSearchExtension');

Now, add your searchform wherever you like in your Page template using $SearchForm

Template Options
----------------

Facets can be used in your templates with code similar to the following. In this instance, the
argument passed to the Facets call is the name of a multivalue parameter on a set of dataobjects
that have been indexed in Solr.

	<div id="Facets">
		<div class="facetList">
				<h3>Keywords</h3>
				<ul>
				<% control Facets(AlcKeywords_ms) %>
				<li><a href="$SearchLink">$Name</a> ($Count)</li>
				<% end_control %>
				</ul>
		</div>
		 <div class="clear"><!-- --></div>
	</div>


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
