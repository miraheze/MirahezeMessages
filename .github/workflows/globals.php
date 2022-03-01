<?php

define( 'MW_DB', 'wikidb' );

require_once "$IP/extensions/CreateWiki/includes/WikiInitialise.php";
$wi = new WikiInitialise();

$wi->setVariables(
	"$IP/cache",
	[
		''
	],
	[
		'127.0.0.1' => ''
	]
);

$wi->config->settings += [
	'cwClosed' => [
		'default' => false,
	],
	'cwInactive' => [
		'default' => false,
	],
	'cwPrivate' => [
		'default' => false,
	],
];

$wgCreateWikiGlobalWiki = 'wikidb';
$wgCreateWikiDatabase = 'wikidb';
$wgCreateWikiCacheDirectory = "$IP/cache";

$wmgCacheSettings['jobrunner'] = [
	'server' => '',
	'password' => '',
];

$wgHooks['MediaWikiServices'][] = 'wfOnMediaWikiServices';

function wfOnMediaWikiServices( MediaWiki\MediaWikiServices $services ) {
	try {
		global $IP;

		$dbw = wfGetDB( DB_PRIMARY );

		$dbw->insert(
			'cw_wikis',
			[
				'wiki_dbname' => 'wikidb',
				'wiki_dbcluster' => 'c1',
				'wiki_sitename' => 'TestWiki',
				'wiki_language' => 'en',
				'wiki_private' => (int)0,
				'wiki_creation' => $dbw->timestamp(),
				'wiki_category' => 'uncategorised',
				'wiki_closed' => (int)0,
				'wiki_deleted' => (int)0,
				'wiki_locked' => (int)0,
				'wiki_inactive' => (int)0,
				'wiki_inactive_exempt' => (int)0,
				'wiki_url' => 'http://127.0.0.1:9412'
			]
		);

		$dbw->sourceFile( "$IP/extensions/Echo/db_patches/echo_unread_wikis.sql" );
	} catch ( Wikimedia\Rdbms\DBQueryError $e ) {
		return;
	}
}

$wi->readCache();
$wi->config->extractAllGlobals( $wi->dbname );
$wgConf = $wi->config;
