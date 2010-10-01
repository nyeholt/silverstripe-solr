<?php
 
/**
 * A decorator that adds the ability to index a DataObject in Solr
 * 
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 *
 */
class SolrIndexable extends DataObjectDecorator
{
	/**
	 * We might not want to index, eg during a data load
	 * 
	 * @var boolean
	 */
	public static $indexing = true;


	// After delete, mark as dirty in main index (so only results from delta index will count), then update the delta index  
	function onAfterPublish() {
		if (!self::$indexing) return;

		// make sure only the fields that are highlighted in searchable_fields are included!!
		singleton('SolrSearchService')->index($this->owner);
	}

	// After delete, mark as dirty in main index (so only results from delta index will count), then update the delta index
	function onAfterUnpublish() {
		if (!self::$indexing) return;
		singleton('SolrSearchService')->unindex($this->owner->class, $this->owner->ID);
	}

	function onAfterDelete() {
		if (!self::$indexing) return;
		singleton('SolrSearchService')->unindex($this->owner->class, $this->owner->ID);
	}
}


?>