<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\RemoteWiki;
use Miraheze\ManageWiki\Helpers\ManageWikiSettings;

class UpdatePrivateAuthUrls extends Maintenance {
	public function execute() {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$dbName = $config->get( 'DBname' );
		$wiki = new RemoteWiki( $dbName );

		if ( $wiki->isPrivate() ) {
			$manageWikiSettings = new ManageWikiSettings( $dbName );
			foreach ( $manageWikiSettings->list() as $var => $val ) {
				if (
					is_string( $val ) &&
					str_contains( $val, "static.miraheze.org/$dbName" )
				) {
					$manageWikiSettings->modify( [ $var => str_replace( "static.miraheze.org/$dbName", '/w/img_auth', $val ] );
					$manageWikiSettings->commit();
				}
			}
		}
	}
}

$maintClass = UpdatePrivateAuthUrls::class;
require_once RUN_MAINTENANCE_IF_MAIN;
