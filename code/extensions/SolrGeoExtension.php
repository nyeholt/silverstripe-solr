<?php

/**
 * Adds geospatial search to SolrSearch
 *
 * @author <marcus@silverstripe.com.au>
 * @license BSD License http://www.silverstripe.org/bsd-license
 */
class SolrGeoExtension extends DataExtension{
	public static $db = array(
		// geo spatial options
		'GeoRestrictionField'				=> 'Varchar(64)',
		'GeoCentre'							=> 'SolrGeoPoint',
		'GeoRadius'							=> 'Double',
		'DistanceSort'						=> "Enum(',asc,desc','asc')",
	);
	
	public function updateSolrCMSFields(\FieldList $fields) {
		// geo spatial field stuff
		$fields->addFieldToTab('Root.Main', $geoHeader = new HeaderField('GeoHeader', _t('SolrSearch.GEO_HEADER', 'Geospatial Settings')), 'Content');
		
		$fields->addFieldToTab('Root.Main',
			new GeoCoordinateField('GeoCentre', _t('SolrSearch.GEO_CENTRE', 'Centre for geo restriction')),
			'Content'
		);
		
		$geoFields = $this->getGeoSelectableFields();
		$geoFields = array_merge(array('' => ''), $geoFields);
		$fields->addFieldToTab('Root.Main', 
			$geoField = new DropdownField('GeoRestrictionField', _t('SolrSearch.RESTRICT_BY', 'Geo field to restrict within radius'), $geoFields),
			'Content'
		);
		$geoField->setRightTitle('Leave the geo field blank and no geospatial restriction will be used');

		$fields->addFieldToTab('Root.Main', 
			new NumericField('GeoRadius', _t('SolrSearch.RESTRICT_RADIUS', 'Restrict results within radius (in km)')),
			'Content'
		);

		$distanceOpts = array(
			'' => 'None', 
			'asc' => 'Nearest to furthest',
			'desc' => 'Furthest to nearest', 
		);

		$fields->addFieldToTab('Root.Main', 
			new DropdownField('DistanceSort', _t('SolrSearch.SORT_BY_DISTANCE', 'Sort by distance from point'), $distanceOpts),
			'Content'
		);
	}
	
		
	/**
	 * Get a list of geopoint fields that are selectable 
	 */
	public function getGeoSelectableFields() {
		$all = $this->owner->getSelectableFields(null, false);
		$listTypes = $this->owner->searchableTypes('Page');
		$geoFields = array();
		
		foreach ($listTypes as $classType) {
			$db = Config::inst()->get($classType, 'db');
			foreach ($db as $name => $type) {
				if (is_subclass_of($type, 'SolrGeoPoint') || $type == 'SolrGeoPoint') {
					$geoFields[$name] = $name;
				}
			}
		}

		ksort($geoFields);
		return $geoFields;
	}

	public function updateQueryBuilder(SolrQueryBuilder $builder) {
		
		// geo fields
		if ($this->owner->GeoRestrictionField) {
			$mappedField = $this->getSolr()->getSolrFieldName($this->owner->GeoRestrictionField, $types);
			$radius = $this->owner->GeoRadius ? $this->owner->GeoRadius : 5;
			$centre = $this->owner->GeoCentre instanceof SolrGeoPoint ? $this->owner->GeoCentre->latLon() : null;
			
			// allow an extension to decide how to geosearch
			if (!$centre && $this->owner->hasMethod('updateGeoSearch')) {
				$this->owner->extend('updateGeoSearch', $builder, $builder->getUserQuery(), $mappedField, $radius);
			} else if ($centre && $mappedField) {
				$builder->restrictNearPoint($centre, $mappedField, $radius);
				if ($this->owner->DistanceSort) {
					$builder->sortBy('geodist()', strtolower($this->owner->DistanceSort));
				}
			}
		}
	}
}
