<?php

/**
 * A coordinate field
 * 
 * Taken from the silverstripe-gis module which hasn't been updated for SS3
 * 
 * https://github.com/sminnee/silverstripe-gis/blob/master/code/forms/GeoPointField.php
 *
 * @author <marcus@silverstripe.com.au>
 * @license BSD License http://www.silverstripe.org/bsd-license
 */
class GeoCoordinateField extends FormField {
	public $xField, $yField;

	function __construct($name, $title = null, $value = "", $form = null) {
		// naming with underscores to prevent values from actually being saved somewhere
		$this->xField = new NumericField("{$name}[x]", _t('GeoCoordinateField.X', 'Longitude'));
		$this->yField = new NumericField("{$name}[y]", _t('GeoCoordinateField.Y', 'Latitude'));

		parent::__construct($name, $title, $value, $form);
	}

	function Field($properties = array()) {
		return "<div class=\"fieldgroup\">" .
			"<div class=\"fieldgroupField\">" . $this->yField->SmallFieldHolder() . "</div>" . 
			"<div class=\"fieldgroupField\">" . $this->xField->SmallFieldHolder() . "</div>" . 
		"</div>";
	}

	function setValue($val) {
		$this->value = $val;
		if (is_string($val)) {
			return;
		}
		$this->xField->setValue(is_object($val) ? $val->X : $val['x']);
		$this->yField->setValue(is_object($val) ? $val->Y : $val['y']);
	}

	function saveInto(DataObjectInterface $record) {
		$fieldName = $this->name;
		$record->$fieldName->X = $this->xField->Value(); 
		$record->$fieldName->Y = $this->yField->Value();
	}

	/**
	 * Returns a readonly version of this field.
	 */
	function performReadonlyTransformation() {
		$clone = clone $this;
		$clone->setReadonly(true);
		return $clone;
	}
}
