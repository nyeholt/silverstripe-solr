<?php

/**
 *	Inserts an additional search index based on user permissions for a site tree element.
 *	NOTE: This extension is optional, and requires both the solr search page extension and queued jobs.
 *	@author Nathan Glasl <nathan@silverstripe.com.au>
 */

class SiteTreePermissionIndexExtension extends DataExtension {

	// Define the name for the custom search index.

	private $index = 'Groups';

	/**
	 *	Retrieve the existing searchable fields, appending our custom search index to enable it.
	 *	@return array
	 */

	public function updateSolrSearchableFields(&$fields) {
		// Make sure the extension requirements have been met before enabling the custom search index.
		if(ClassInfo::exists('QueuedJob')) {
			$fields[$this->index] = true;
		}
	}

	/**
	 *	Retrieve the custom search index value for the current site tree element, returning the listing of groups that have access.
	 *	@return array
	 */

	public function additionalSolrValues() {

		if(($this->owner->CanViewType === 'Inherit') && $this->owner->ParentID) {

			// Recursively determine the site tree element permissions where required.

			return $this->owner->Parent()->additionalSolrValues();
		}
		else if($this->owner->CanViewType === 'OnlyTheseUsers') {

			// Return the listing of groups that have access.

			$groups = array();
			foreach($this->owner->ViewerGroups() as $group) {
				$groups[] = (string)$group->ID;
			}
			return array(
				$this->index => $groups
			);
		}
		else if($this->owner->CanViewType === 'LoggedInUsers') {

			// Return an appropriate flag for logged in access.

			return array(
				$this->index => array(
					'logged-in'
				)
			);
		}
		else {

			// Return an appropriate flag for general public access.

			return array(
				$this->index => array(
					'anyone'
				)
			);
		}
	}

}
