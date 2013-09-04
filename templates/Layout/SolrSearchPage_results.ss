<div class="typography">
	<% if FacetCrumbs %>
	<ul class="facetCrumbs">
		<% loop FacetCrumbs %>
		<li><a href="$RemoveLink">$Name</a></li>
		<% end_loop %>
	</ul>
	<% end_if %>

	$Content
	$Form
	
	<% if Results %>
		<% if ListingTemplateID %>
		$TemplatedResults
		<% else %>
	    <ul id="SearchResults">
	      <% loop Results %>
	        <li>
	            <% if MenuTitle %>
	              <h3><a class="searchResultHeader" href="$Link">$MenuTitle</a></h3>
	            <% else %>
	              <h3><a class="searchResultHeader" href="$Link">$Title</a></h3>
	            <% end_if %>
			  <% if Content %>
	          	$Content.FirstParagraph(html)
			  <% end_if %>
	          <a class="readMoreLink" href="$Link" title="Read more about &quot;{$Title}&quot;">Read more about &quot;{$Title}&quot;...</a>
	        </li>
	      <% end_loop %>
	    </ul>
		<% end_if %>
	  <% else %>
	    <p>Sorry, your search query did not return any results.</p>
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
	        <% loop Results.PaginationSummary(5) %>
	          <% if CurrentBool %>
	            $PageNum
	          <% else %>
	            <a href="$Link" title="View page number $PageNum">$PageNum</a>
	          <% end_if %>
	        <% end_loop %>
	      </span>
      
	    </div>
	 <% end_if %>
</div>
