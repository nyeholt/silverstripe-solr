	<% require css(solr/css/SolrSearchPage.css) %>

<div class="typography">

	$Content
	$Form
	
	<% if Results %>
		<% if ListingTemplateID %>
			$TemplatedResults
		<% else %>

			<% if numFacets %>
				<div class="solrFacets">
					<h2>Too Many Results?</h2>
					<% if FacetCrumbs %>
						<h3>Active filters</h3>
						<ul class="facetCrumbs">
							<% control FacetCrumbs %>
								<li><a href="$RemoveLink">$Name.XML</a></li>
							<% end_control %>
						</ul>
					<% end_if %>

					<% if AllFacets %>
						<h3>Suggested Filters</h3>
						<% control AllFacets %>
							<% if Facets %>
								<h4>$Title</h4>
								<ul class="facetCrumbs">
									<% control Facets %>
										<li><a href="$QuotedSearchLink">$Name.XML</a></li>
									<% end_control %>
								</ul>
							<% end_if %>
						<% end_control %>
					<% end_if %>
				</div>
			<% end_if %>

			<div class="clear"><!-- //--></div>

			<% if Results %>
			    <ul id="SearchResults">
					<% control Results %>
						<% if Results %>
							<h2>$Title</h2>
							<% control Results %>
						        <li class="results $Class">
						            <% if MenuTitle %>
										<h3><a class="searchResultHeader" href="$Link">$MenuTitle</a></h3>
									<% else %>
										<h3><a class="searchResultHeader" href="$Link">$Title</a></h3>
									<% end_if %>
									<% if Content %>
										$Content.ContextSummary(140)
									<% end_if %>
									<a class="readMoreLink" href="$Link" title="Read more about &quot;{$Title}&quot;">Read more about &quot;{$Title}&quot;...</a>
								</li>
							<% end_control %>
						<% else %>
							<p>Sorry, your search query did not return any results.</p>
						<% end_if %>
					<% end_control %>
				</ul>
			<% end_if %>

			<% if Results.MoreThanOnePage %>
				<div id="PageNumbers">
					<% if Results.NotLastPage %>
						<a class="next" href="$Results.NextLink" title="View the next page">Next</a>
					<% end_if %>
					<% if Results.NotFirstPage %>
						<a class="prev" href="$Results.PrevLink" title="View the previous page">Prev</a>
					<% end_if %>
					<span>
						<% control Results.PaginationSummary(5) %>
							<% if CurrentBool %>
								$PageNum
							<% else %>
								<a href="$Link" title="View page number $PageNum">$PageNum</a>
							<% end_if %>
						<% end_control %>
					</span>
				</div>
			<% end_if %>

		<% end_if %>
	<% end_if %>
</div>
