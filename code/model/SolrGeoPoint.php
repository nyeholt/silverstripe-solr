<?php

/**
 * 
 *
 * @author <marcus@silverstripe.com.au>
 * @license BSD License http://www.silverstripe.org/bsd-license
 */
class SolrGeoPoint extends SolrGeoDBField implements CompositeDBField {

	/**
	 * X coordinate (or Longitude)
	 * @var float
	 */
	protected $x;

	/**
	 * Y coordinate (or Latitude)
	 * @var float
	 */
	protected $y;

	protected static $wkt_name = 'POINT';

	/**
	 * @param float $lat
	 * @param float $lng
	 * @return SolrGeoPoint
	 */
	public static function from_x_y($x, $y) {
		$g = new SolrGeoPoint(null);
		$g->X = $x;
		$g->Y = $y;

		return $g;
	}

	/**
	 * @param float $lat
	 * @param float $lng
	 * @return SolrGeoPoint
	 */
	public static function from_lat_lng($lat, $lng) {
		return self::from_x_y($lng, $lat);
	}

	function requireField() {
		DB::requireField($this->tableName, $this->name, "point");
	}

	public function compositeDatabaseFields() {
		return array(
			'' => "SolrGeoPoint"
		);
	}

	function hasValue($field, $arguments = null, $cache = true) {
		return (is_numeric($this->x) || is_numeric($this->y));
	}

	/**
	 * @return string
	 */
	public function WKT() {
		return "GeomFromText('" . $this->stat('wkt_name') . "({$this->X} {$this->Y})')";
	}

	/**
	 * Setting as "Well-known Text" with x/y or lng/lat.
	 * @example "POINT(-174.9999 4.9923)"
	 * 
	 * @param string $wktString
	 */
	function setAsWKT($wktString) {
		parent::setAsWKT($wktString);
		// check the wkt now
		preg_match('/^POINT\(([0-9.\-]+) ([0-9.\-]+)\)$/', $this->wkt, $matches);
		if(!$matches) return false;

		$this->x = (float)$matches[1];
		$this->y = (float)$matches[2];

		$this->isChanged = true;
	}

	/**
	 * Set the value as an array. Accepts different formats:
	 * 
	 * Format: x/y
	 * <example>array('x'=>-174.9999,'y'=>4.9923)</example>
	 * 
	 * Format: lng/lat
	 * <example>array('lng'=>-174.9999,'lat'=>4.9923)</example>
	 *
	 * Format: Numeric array with x=0/y=1
	 * <example>array(-174.9999,4.9923)</example>
	 * 
	 * @param array
	 */
	function setAsArray($arr) {
		if(isset($arr['x']) && isset($arr['y'])) {
			$this->x = (float)$arr['x'];
			$this->y = (float)$arr['y'];
		} elseif(isset($arr['lat']) && isset($arr['lng'])) {
			$this->x = (float)$arr['lng'];
			$this->y = (float)$arr['lat'];
		} elseif(isset($arr[0]) && isset($arr[1])) {
			$this->x = (float)$arr[0];
			$this->y = (float)$arr[1];
		} else {
			user_error("{$this->class}::setAsArray() - Bad array " . var_export($arr, true), E_USER_ERROR);		
		}
	}


	/**
	 * Returns an associative array with the X/Y and lat/lng coordinates.
	 * 
	 * <example>
	 * array('x'=>4.9923,'y'=>-174.9999,'lat'=>-174.9999,'lng'=>4.9923)
	 * </example>
	 * 
	 * @return array
	 */
	function getCoords() {
		return array(
			'x' => $this->x, 
			'y' => $this->y,
			'lng' => $this->x,
			'lat' => $this->y,
		);
	}

	function setX($x) {
		$this->x = (float)$x;
		$this->isChanged = true;
	}

	function setY($y) {
		$this->y = (float)$y;
		$this->isChanged = true;
	}

	function getX() {
		return $this->x;
	}

	function getY() {
		return $this->y;
	}

	function getLat() {
		return $this->y;
	}

	function getLng() {
		return $this->x;
	}
	
	public function latLon() {
		if ($this->x && $this->y) {
			return "{$this->getLat()},{$this->getLng()}";
		}
	}

	function setLat($lat) {
		$this->y = (float)$lat;
		$this->isChanged = true;
	}

	function setLng($lng) {
		$this->x = (float)$lng;
		$this->isChanged = true;
	}

	function writeToManipulation(&$manipulation) {
		if($this->hasValue($this->name)) {
			$manipulation['fields'][$this->name] = $this->WKT();
		} else {
			$manipulation['fields'][$this->name] = $this->nullValue();
		}
	}

	/**
	 * Determines if the passed string is in valid "Well-known Text" format.
	 *
	 * @param string $wktString
	 * @return boolean
	 */
	public static function is_valid_wkt($wktString) {
		return (is_string($wktString) && preg_match('/^POINT\(([0-9.\-]+) ([0-9.\-]+)\)$/', $wktString));
	}

	function debug() {
		return $this->name . '(' . $this->value . ')';
	}

	function toJSON() {
		return Convert::raw2json($this->getCoords());
	}
	function toXML() {
		return "<$this->Name x=\"" . Convert::raw2xml($this->X) . "\" y=\"" . Convert::raw2xml($this->Y) . "\" srid=\"" . Convert::raw2att($this->srid) . "\" />";
	}

	function toCSV() {
		return Convert::raw2xml($this->X) . "," . Convert::raw2xml($this->Y);
	}

	public function scaffoldFormField($title = null) {
		return new GeoCoordinateField($this->name, $title);
	}
}
