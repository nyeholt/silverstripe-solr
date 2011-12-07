<?php

/**
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SolrServerConfig extends DataObject {
	public static $db = array(
		'RunLocal'		=> 'Boolean',
		'LogPath'		=> 'Varchar(128)',
	);
	
	static $defaults = array(
		'LogPath' => '/solr/solr/logs/solr.log'
	);
	
	public function requireDefaultRecords() {
		parent::requireDefaultRecords();
		
		$conf = DataObject::get_one('SolrServerConfig');
		if (!$conf || !$conf->ID) {
			$conf = new SolrServerConfig();
			$conf->RunLocal = false;
			$conf->write();
		}
	}

	public function getLogFile() {
		$logFile = $this->LogPath;
		if (!$this->LogPath) {
			$logFile = self::$defaults['LogPath'];
		}
		$logFile = Director::baseFolder().$logFile;
		return $logFile;
	}
}
