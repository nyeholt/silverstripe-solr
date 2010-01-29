###############################################
Solr Search Module
###############################################

Maintainer Contact
-----------------------------------------------
Marcus Nyeholt
<marcus (at) silverstripe (dot) com (dot) au>

Requirements
-----------------------------------------------
Solr installed and running

Documentation
-----------------------------------------------


Licensing
-----------------------------------------------
Solr is licensed under the Apache License

Quick Usage Overview
-----------------------------------------------
Install Solr (packages available for most OSes)

Add the SolrSearchable decorator to any DataObjects you want to search (for example, SiteTree and File)

Use GeneralSearchForm instead of SearchForm. Set GeneralSearchForm::$search_class to
SolrSearch

API
-----------------------------------------------


Troubleshooting
-----------------------------------------------


