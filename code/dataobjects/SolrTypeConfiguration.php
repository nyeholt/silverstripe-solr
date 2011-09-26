<?php

/**
 * Description of SolrTypeConfiguration
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SolrTypeConfiguration extends DataObject {
	public static $db = array(
		'Title'			=> 'Varchar',
		'FieldMappings'	=> 'MultiValueField',
	);
	
	public function getCMSFields() {
		$fields = parent::getCMSFields();
		
		$types = ClassInfo::dataClassesFor('DataObject');
		array_shift($types);
		asort($types);
		$source = array_combine($types, $types);

		$fields->replaceField('Title', new DropdownField('Title', _t('Solr.TYPE_CONFIG_TITLE', 'Data type'), $source));

		if ($this->Title) {
			$keys = $this->getFieldsFor($this->Title);
			$vals = array(
				'default'		=> _t('Solr.DEFAULT_MAPPING', 'Default type'),
				':field_mt'		=> _t('Solr.CASE_INSENSITIVE', 'Case Insensitive text'),
				':field_ms'		=> _t('Solr.CASE_SENSITIVE', 'Case Sensitive, untokenised text'),
			);

			$fields->replaceField('FieldMappings', new KeyValueField('FieldMappings', _t('Solr.FIELD_MAPPINGS', 'Indexed fields'), $keys, $vals, $this->FieldMappings));
		} else {
			$fields->removeByName('FieldMappings');
		}
		
		return $fields;
	}
	
	protected function getFieldsFor($type) {
		
		$objFields = array();
		
		$dbFields = array_keys(DataObject::combined_static($type, 'db'));
		$objFields = array_combine($dbFields, $dbFields);
		
		asort($objFields);
		return $objFields;
	}
}
