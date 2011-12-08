<?php

/**
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class SolrServerConfig extends DataObject {
	public static $db = array(
		'RunLocal'		=> 'Boolean',
		'InstanceID'	=> 'Varchar(64)',
		'LogPath'		=> 'Varchar(128)',
	);
	
	static $defaults = array(
		'LogPath' => '/solr/solr/logs'
	);
	
	public function onBeforeWrite() {
		parent::onBeforeWrite();
		if (!$this->InstanceID) {
			$this->InstanceID = md5(mt_rand(0, 1000) . time());
		}
		
		if (!$this->LogPath) {
			$this->LogPath = self::$defaults['LogPath'];
		}
	}

	public function requireDefaultRecords() {
		parent::requireDefaultRecords();
		
		$conf = DataObject::get_one('SolrServerConfig');
		if (!$conf || !$conf->ID) {
			$conf = new SolrServerConfig();
			$conf->RunLocal = false;
			$conf->InstanceID = md5(mt_rand(0, 1000) . time());
			$conf->write();
		}
	}

	public function getLogFile() {
		$logFile = $this->LogPath;
		if (!$this->LogPath) {
			$logFile = self::$defaults['LogPath'];
		}
		
		$logFile = Director::baseFolder().$logFile . '/solr-' . $this->InstanceID . '.log';
		return $logFile;
	}
}
