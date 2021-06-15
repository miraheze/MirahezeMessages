<?php
require_once( __DIR__ . '/../../../maintenance/Maintenance.php' );

use MediaWiki\MediaWikiServices;

class ResetWikiCaches extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Resets ManageWiki cache.';
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );

		$cWJ = new CreateWikiJson( $config->get( 'DBname' ) );
		$cWJ->resetWiki();

		sleep( 0.02 );
	}
}

$maintClass = 'ResetWikiCaches';
require_once RUN_MAINTENANCE_IF_MAIN;
