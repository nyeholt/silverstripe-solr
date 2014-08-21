<?php

/**
 *	Updates the search query to include the current user permissions.
 *	NOTE: This extension is optional, and requires both the site tree extension and queued jobs.
 *	@author Nathan Glasl <nathan@silverstripe.com.au>
 */

class SolrSearchPermissionIndexExtension extends DataExtension {

	/**
	 *	Apply the current user permissions against the solr query builder.
	 *	@param SolrQueryBuilder
	 */

	public function updateQueryBuilder($builder) {

		// Make sure the extension requirements have been met before enabling the custom search index.

		if(SiteTree::has_extension('SolrIndexable') && SiteTree::has_extension('SiteTreePermissionIndexExtension') && ClassInfo::exists('QueuedJob')) {

			// Define the initial user permissions using the general public access flag.

			$groups = array(
				'anyone'
			);

			// Apply the logged in access flag and listing of groups for the current authenticated user.

			$user = Member::currentUser();
			if($user) {

				// Don't restrict the search results for an administrator.

				if(Permission::checkMember($user, array('ADMIN', 'SITETREE_VIEW_ALL'))) {
					return;
				}
				$groups[] = 'logged-in';
				foreach($user->Groups() as $group) {
					$groups[] = (string)$group->ID;
				}
			}

			// Apply this permission filter.

			$builder->andWith('Groups_ms', $groups);
		}
	}

}
