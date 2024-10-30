<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Cache\Hook\MessageCacheFetchOverridesHook;
use MediaWiki\CommentStore\CommentStore;
use MediaWiki\Config\Config;
use MediaWiki\Config\GlobalVarConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterShouldFilterActionHook;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Hook\BlockIpCompleteHook;
use MediaWiki\Hook\ContributionsToolLinksHook;
use MediaWiki\Hook\GetLocalURL__InternalHook;
use MediaWiki\Hook\MimeMagicInitHook;
use MediaWiki\Hook\RecentChange_saveHook;
use MediaWiki\Hook\SiteNoticeAfterHook;
use MediaWiki\Hook\SkinAddFooterLinksHook;
use MediaWiki\Html\Html;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Linker\Linker;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\Hook\TitleReadWhitelistHook;
use MediaWiki\Permissions\Hook\UserGetRightsRemoveHook;
use MediaWiki\Shell\Shell;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use Memcached;
use MessageCache;
use Miraheze\CreateWiki\Hooks\CreateWikiDeletionHook;
use Miraheze\CreateWiki\Hooks\CreateWikiReadPersistentModelHook;
use Miraheze\CreateWiki\Hooks\CreateWikiRenameHook;
use Miraheze\CreateWiki\Hooks\CreateWikiStatePrivateHook;
use Miraheze\CreateWiki\Hooks\CreateWikiTablesHook;
use Miraheze\CreateWiki\Hooks\CreateWikiWritePersistentModelHook;
use Miraheze\ImportDump\Hooks\ImportDumpJobAfterImportHook;
use Miraheze\ImportDump\Hooks\ImportDumpJobGetFileHook;
use Miraheze\ManageWiki\Helpers\ManageWikiExtensions;
use Miraheze\ManageWiki\Helpers\ManageWikiSettings;
use Redis;
use Skin;
use Throwable;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\ILBFactory;

class Main implements
	AbuseFilterShouldFilterActionHook,
	BlockIpCompleteHook,
	ContributionsToolLinksHook,
	CreateWikiDeletionHook,
	CreateWikiReadPersistentModelHook,
	CreateWikiRenameHook,
	CreateWikiStatePrivateHook,
	CreateWikiTablesHook,
	CreateWikiWritePersistentModelHook,
	GetLocalURL__InternalHook,
	ImportDumpJobAfterImportHook,
	ImportDumpJobGetFileHook,
	MessageCacheFetchOverridesHook,
	MimeMagicInitHook,
	RecentChange_saveHook,
	SiteNoticeAfterHook,
	SkinAddFooterLinksHook,
	TitleReadWhitelistHook,
	UserGetRightsRemoveHook
{

	/** @var ServiceOptions */
	private $options;

	/** @var CommentStore */
	private $commentStore;

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

	/** @var HttpRequestFactory */
	private $httpRequestFactory;

	/**
	 * @param ServiceOptions $options
	 * @param CommentStore $commentStore
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param HttpRequestFactory $httpRequestFactory
	 */
	public function __construct(
		ServiceOptions $options,
		CommentStore $commentStore,
		ILBFactory $dbLoadBalancerFactory,
		HttpRequestFactory $httpRequestFactory
	) {
		$this->options = $options;
		$this->commentStore = $commentStore;
		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
		$this->httpRequestFactory = $httpRequestFactory;
	}

	/**
	 * @param Config $mainConfig
	 * @param CommentStore $commentStore
	 * @param ILBFactory $dbLoadBalancerFactory
	 * @param HttpRequestFactory $httpRequestFactory
	 *
	 * @return self
	 */
	public static function factory(
		Config $mainConfig,
		CommentStore $commentStore,
		ILBFactory $dbLoadBalancerFactory,
		HttpRequestFactory $httpRequestFactory
	): self {
		return new self(
			new ServiceOptions(
				[
					'ArticlePath',
					'CreateWikiCacheDirectory',
					'CreateWikiGlobalWiki',
					'EchoSharedTrackingDB',
					'JobTypeConf',
					'LanguageCode',
					'LocalDatabases',
					'ManageWikiSettings',
					'MirahezeMagicAccessIdsMap',
					'MirahezeMagicMemcachedServers',
					'MirahezeReportsBlockAlertKeywords',
					'MirahezeReportsWriteKey',
					'Script',
				],
				$mainConfig
			),
			$commentStore,
			$dbLoadBalancerFactory,
			$httpRequestFactory
		);
	}

	/**
	 * Avoid filtering automatic account creation
	 *
	 * @param VariableHolder $vars
	 * @param Title $title
	 * @param User $user
	 * @param array &$skipReasons
	 * @return bool|void
	 */
	public function onAbuseFilterShouldFilterAction(
		VariableHolder $vars,
		Title $title,
		User $user,
		array &$skipReasons
	) {
		if ( defined( 'MW_PHPUNIT_TEST' ) ) {
			return;
		}

		$varManager = AbuseFilterServices::getVariablesManager();

		$action = $varManager->getVar( $vars, 'action', 1 )->toString();
		if ( $action === 'autocreateaccount' ) {
			$skipReasons[] = 'Blocking automatic account creation is not allowed';

			return false;
		}
	}

	public function onCreateWikiDeletion( DBConnRef $cwdb, string $dbname ): void {
		global $wmgSwiftPassword;

		$echoSharedTrackingDB = $this->options->get( 'EchoSharedTrackingDB' );
		$dbw = $this->dbLoadBalancerFactory->getMainLB(
			$echoSharedTrackingDB
		)->getMaintenanceConnectionRef( DB_PRIMARY, [], $echoSharedTrackingDB );

		$dbw->delete( 'echo_unread_wikis', [ 'euw_wiki' => $dbname ] );

		foreach ( $this->options->get( MainConfigNames::LocalDatabases ) as $db ) {
			$manageWikiSettings = new ManageWikiSettings( $db );

			foreach ( $this->options->get( 'ManageWikiSettings' ) as $var => $setConfig ) {
				if (
					$setConfig['type'] === 'database' &&
					$manageWikiSettings->list( $var ) === $dbname
				) {
					$manageWikiSettings->remove( $var );
					$manageWikiSettings->commit();
				}
			}
		}

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		// Get a list of containers to delete for the wiki
		$containers = explode( "\n",
			trim( Shell::command(
				'swift', 'list',
				'--prefix', 'miraheze-' . $dbname . '-',
				'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->disableSandbox()
				->execute()->getStdout()
			)
		);

		foreach ( $containers as $container ) {
			// Just an extra precaution to ensure we don't select the wrong containers
			if ( !str_contains( $container, $dbname . '-' ) ) {
				continue;
			}

			// Delete the container
			Shell::command(
				'swift', 'delete',
				$container,
				'--object-threads', '1',
				'--container-threads', '1',
				'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->disableSandbox()
				->execute();
		}

		$this->removeRedisKey( "*{$dbname}*" );
		$this->removeMemcachedKey( ".*{$dbname}.*" );
	}

	public function onCreateWikiRename(
		DBConnRef $cwdb,
		string $oldDbName,
		string $newDbName
	): void {
		global $wmgSwiftPassword;

		$echoSharedTrackingDB = $this->options->get( 'EchoSharedTrackingDB' );
		$dbw = $this->dbLoadBalancerFactory->getMainLB(
			$echoSharedTrackingDB
		)->getMaintenanceConnectionRef( DB_PRIMARY, [], $echoSharedTrackingDB );

		$dbw->update( 'echo_unread_wikis', [ 'euw_wiki' => $newDbName ], [ 'euw_wiki' => $oldDbName ] );

		foreach ( $this->options->get( MainConfigNames::LocalDatabases ) as $db ) {
			$manageWikiSettings = new ManageWikiSettings( $db );

			foreach ( $this->options->get( 'ManageWikiSettings' ) as $var => $setConfig ) {
				if (
					$setConfig['type'] === 'database' &&
					$manageWikiSettings->list( $var ) === $oldDbName
				) {
					$manageWikiSettings->modify( [ $var => $newDbName ] );
					$manageWikiSettings->commit();
				}
			}
		}

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		// Get a list of containers to download, and later upload for the wiki
		$containers = explode( "\n",
			trim( Shell::command(
				'swift', 'list',
				'--prefix', 'miraheze-' . $oldDbName . '-',
				'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->disableSandbox()
				->execute()->getStdout()
			)
		);

		foreach ( $containers as $container ) {
			// Just an extra precaution to ensure we don't select the wrong containers
			if ( !str_contains( $container, $oldDbName . '-' ) ) {
				continue;
			}

			// Get a list of all files in the container to ensure everything is present in new container later.
			$oldContainerList = Shell::command(
				'swift', 'list',
				$container,
				'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->disableSandbox()
				->execute()->getStdout();

			// Download the container
			Shell::command(
				'swift', 'download',
				$container,
				'-D', wfTempDir() . '/' . $container,
				'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->disableSandbox()
				->execute();

			$newContainer = str_replace( $oldDbName, $newDbName, $container );

			// Upload to new container
			// We have to use exec here, as Shell::command does not work for this
			// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions
			exec( escapeshellcmd(
				implode( ' ', [
					'swift', 'upload',
					$newContainer,
					wfTempDir() . '/' . $container,
					'--object-name', '""',
					'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
					'-U', 'mw:media',
					'-K', $wmgSwiftPassword
				] )
			) );

			wfDebugLog( 'MirahezeMagic', "Container '$newContainer' created." );

			$newContainerList = Shell::command(
				'swift', 'list',
				$newContainer,
				'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->disableSandbox()
				->execute()->getStdout();

			if ( $newContainerList === $oldContainerList ) {
				// Everything has been correctly copied over
				// wipe files from the temp directory and delete old container

				// Delete the container
				Shell::command(
					'swift', 'delete',
					$container,
					'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
					'-U', 'mw:media',
					'-K', $wmgSwiftPassword
				)->limits( $limits )
					->disableSandbox()
					->execute();

				wfDebugLog( 'MirahezeMagic', "Container '$container' deleted." );

				// Wipe from the temp directory
				Shell::command( '/bin/rm', '-rf', wfTempDir() . '/' . $container )
					->limits( $limits )
					->disableSandbox()
					->execute();
			} else {
				/**
				 * We need to log this, as otherwise all files may not have been succesfully
				 * moved to the new container, and they still exist locally. We should know that.
				 */
				wfDebugLog( 'MirahezeMagic', "The rename of wiki {$oldDbName} to {$newDbName} may not have been successful. Files still exist locally in {wfTempDir()} and the Swift containers for the old wiki still exist." );
			}
		}

		$scriptOptions = [ 'wrapper' => MW_INSTALL_PATH . '/maintenance/run.php' ];

		Shell::makeScriptCommand(
			MW_INSTALL_PATH . '/extensions/CreateWiki/maintenance/setContainersAccess.php',
			[
				'--wiki', $newDbName
			],
			$scriptOptions
		)->limits( $limits )->execute();

		$this->removeRedisKey( "*{$oldDbName}*" );
		$this->removeMemcachedKey( ".*{$oldDbName}.*" );
	}

	public function onCreateWikiStatePrivate( string $dbname ): void {
		$localRepo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		$sitemaps = $localRepo->getBackend()->getTopFileList( [
			'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps',
			'adviseStat' => false,
		] );

		foreach ( $sitemaps as $sitemap ) {
			$status = $localRepo->getBackend()->quickDelete( [
				'src' => $localRepo->getZonePath( 'public' ) . '/sitemaps/' . $sitemap,
			] );

			if ( !$status->isOK() ) {
				$statusFormatter = MediaWikiServices::getInstance()->getFormatterFactory()
					->getStatusFormatter( RequestContext::getMain() );

				/**
				 * We need to log this, as otherwise the sitemaps may
				 * not be being deleted for private wikis. We should know that.
				 */
				$statusMessage = $statusFormatter->getWikiText( $status );
				wfDebugLog( 'MirahezeMagic', "Sitemap \"{$sitemap}\" failed to delete: {$statusMessage}" );
			}
		}

		$localRepo->getBackend()->clean( [ 'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps' ] );
	}

	public function onCreateWikiTables( array &$cTables ): void {
		$cTables['localnames'] = 'ln_wiki';
		$cTables['localuser'] = 'lu_wiki';
	}

	public function onCreateWikiReadPersistentModel( string &$pipeline ): void {
		$backend = MediaWikiServices::getInstance()->getFileBackendGroup()->get( 'miraheze-swift' );
		if ( $backend->fileExists( [ 'src' => $backend->getContainerStoragePath( 'createwiki-persistent-model' ) . '/requestmodel.phpml' ] ) ) {
			$pipeline = unserialize(
				$backend->getFileContents( [
					'src' => $backend->getContainerStoragePath( 'createwiki-persistent-model' ) . '/requestmodel.phpml',
				] )
			);
		}
	}

	public function onCreateWikiWritePersistentModel( string $pipeline ): bool {
		$backend = MediaWikiServices::getInstance()->getFileBackendGroup()->get( 'miraheze-swift' );
		$backend->prepare( [ 'dir' => $backend->getContainerStoragePath( 'createwiki-persistent-model' ) ] );

		$backend->quickCreate( [
			'dst' => $backend->getContainerStoragePath( 'createwiki-persistent-model' ) . '/requestmodel.phpml',
			'content' => $pipeline,
			'overwrite' => true,
		] );

		return true;
	}

	public function onImportDumpJobAfterImport( $filePath, $importDumpRequestManager ): void {
		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];
		Shell::command( '/bin/rm', $filePath )
			->limits( $limits )
			->disableSandbox()
			->execute();
	}

	public function onImportDumpJobGetFile( &$filePath, $importDumpRequestManager ): void {
		global $wmgSwiftPassword;

		$dbr = $this->dbLoadBalancerFactory->getReplicaDatabase( 'virtual-importdump' );

		$container = $dbr->getDomainID() === 'metawikibeta' ?
			'miraheze-metawikibeta-local-public' :
			'miraheze-metawiki-local-public';

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		Shell::command(
			'swift', 'download',
			$container,
			$importDumpRequestManager->getSplitFilePath(),
			'-o', $filePath,
			'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
			'-U', 'mw:media',
			'-K', $wmgSwiftPassword
		)->limits( $limits )
			->disableSandbox()
			->execute();
	}

	/**
	 * From WikimediaMessages
	 * When core requests certain messages, change the key to a Miraheze version.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/MessageCacheFetchOverrides
	 * @param string[] &$keys
	 */
	public function onMessageCacheFetchOverrides( array &$keys ): void {
		static $keysToOverride = [
			'centralauth-groupname',
			'centralauth-login-error-locked',
			'createwiki-close-email-body',
			'createwiki-close-email-sender',
			'createwiki-close-email-subject',
			'createwiki-defaultmainpage',
			'createwiki-defaultmainpage-summary',
			'createwiki-email-body',
			'createwiki-email-subject',
			'createwiki-error-subdomaintaken',
			'createwiki-help-bio',
			'createwiki-help-reason',
			'createwiki-help-subdomain',
			'createwiki-label-reason',
			'dberr-again',
			'dberr-problems',
			'globalblocking-ipblocked-range',
			'globalblocking-ipblocked-xff',
			'globalblocking-ipblocked',
			'grouppage-autoconfirmed',
			'grouppage-automoderated',
			'grouppage-autoreview',
			'grouppage-blockedfromchat',
			'grouppage-bot',
			'grouppage-bureaucrat',
			'grouppage-chatmod',
			'grouppage-checkuser',
			'grouppage-commentadmin',
			'grouppage-csmoderator',
			'grouppage-editor',
			'grouppage-flow-bot',
			'grouppage-interface-admin',
			'grouppage-moderator',
			'grouppage-no-ipinfo',
			'grouppage-reviewer',
			'grouppage-suppress',
			'grouppage-sysop',
			'grouppage-upwizcampeditors',
			'grouppage-user',
			'importdump-help-reason',
			'importdump-help-target',
			'importdump-help-upload-file',
			'importdump-import-failed-comment',
			'importtext',
			'interwiki_intro',
			'newsignuppage-loginform-tos',
			'newsignuppage-must-accept-tos',
			'oathauth-step1',
			'prefs-help-realname',
			'privacypage',
			'requestwiki-error-invalidcomment',
			'requestwiki-info-guidance',
			'requestwiki-info-guidance-post',
			'requestwiki-label-agreement',
			'requestwiki-success',
			'restriction-delete',
			'restriction-protect',
			'skinname-snapwikiskin',
			'snapwikiskin',
			'uploadtext',
			'webauthn-module-description',
			'wikibase-sitelinks-miraheze',
		];

		$languageCode = $this->options->get( MainConfigNames::LanguageCode );

		$transformationCallback = static function ( string $key, MessageCache $cache ) use ( $languageCode ): string {
			$transformedKey = "miraheze-$key";

			// MessageCache uses ucfirst if ord( key ) is < 128, which is true of all
			// of the above.  Revisit if non-ASCII keys are used.
			$ucKey = ucfirst( $key );

			if (
				/*
				 * Override order:
				 * 1. If the MediaWiki:$ucKey page exists, use the key unprefixed
				 * (in all languages) with normal fallback order.  Specific
				 * language pages (MediaWiki:$ucKey/xy) are not checked when
				 * deciding which key to use, but are still used if applicable
				 * after the key is decided.
				 *
				 * 2. Otherwise, use the prefixed key with normal fallback order
				 * (including MediaWiki pages if they exist).
				 */
				$cache->getMsgFromNamespace( $ucKey, $languageCode ) === false
			) {
				return $transformedKey;
			}

			return $key;
		};

		foreach ( $keysToOverride as $key ) {
			$keys[$key] = $transformationCallback;
		}
	}

	public function onTitleReadWhitelist( $title, $user, &$whitelisted ) {
		if ( $title->equals( Title::newMainPage() ) ) {
			$whitelisted = true;
			return;
		}

		$specialsArray = [
			'CentralAutoLogin',
			'CentralLogin',
			'ConfirmEmail',
			'CreateAccount',
			'Notifications',
			'OAuth',
			'ResetPassword'
		];

		if ( $user->isAllowed( 'interwiki' ) ) {
			$specialsArray[] = 'Interwiki';
		}

		if ( $title->isSpecialPage() ) {
			$rootName = strtok( $title->getText(), '/' );
			$rootTitle = Title::makeTitle( $title->getNamespace(), $rootName );

			foreach ( $specialsArray as $page ) {
				if ( $rootTitle->equals( SpecialPage::getTitleFor( $page ) ) ) {
					$whitelisted = true;
					return;
				}
			}
		}
	}

	public function onGlobalUserPageWikis( array &$list ): bool {
		$cwCacheDir = $this->options->get( 'CreateWikiCacheDirectory' );

		if ( file_exists( "{$cwCacheDir}/databases.php" ) ) {
			$databasesArray = include "{$cwCacheDir}/databases.php";

			$dbList = array_keys( $databasesArray['databases'] ?? [] );

			// Filter out those databases that don't have GlobalUserPage enabled
			$list = array_filter( $dbList, static function ( $dbname ) {
				$extensions = new ManageWikiExtensions( $dbname );
				return in_array( 'globaluserpage', $extensions->list() );
			} );

			return false;
		}

		return true;
	}

	public function onMimeMagicInit( $mimeMagic ) {
		$mimeMagic->addExtraTypes( 'text/plain txt off' );
	}

	public function onSkinAddFooterLinks( Skin $skin, string $key, array &$footerItems ) {
		if ( $key === 'places' ) {
			$footerItems['termsofservice'] = $this->addFooterLink( $skin, 'termsofservice', 'termsofservicepage' );
			$footerItems['donate'] = $this->addFooterLink( $skin, 'miraheze-donate', 'miraheze-donatepage' );
		}
	}

	public function onUserGetRightsRemove( $user, &$rights ) {
		// Remove read from global groups on some wikis
		foreach ( $this->options->get( 'MirahezeMagicAccessIdsMap' ) as $wiki => $ids ) {
			if ( WikiMap::isCurrentWikiId( $wiki ) && $user->isRegistered() ) {
				$centralAuthUser = CentralAuthUser::getInstance( $user );

				if ( $centralAuthUser &&
					$centralAuthUser->exists() &&
					!in_array( $centralAuthUser->getId(), $ids )
				) {
					$rights = array_unique( $rights );
					unset( $rights[array_search( 'read', $rights )] );
				}
			}
		}
	}

	public function onSiteNoticeAfter( &$siteNotice, $skin ) {
		$cwConfig = new GlobalVarConfig( 'cw' );

		if ( $cwConfig->get( 'Closed' ) ) {
			if ( $cwConfig->get( 'Private' ) ) {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/0/02/Wiki_lock.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-closed-private' )->parse() . '</span></div>';
			} elseif ( $cwConfig->get( 'Locked' ) ) {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/5/5f/Out_of_date_clock_icon.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-closed-locked' )->parse() . '</span></div>';
			} else {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/0/02/Wiki_lock.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-closed' )->parse() . '</span></div>';
			}
		} elseif ( $cwConfig->get( 'Inactive' ) && $cwConfig->get( 'Inactive' ) !== 'exempt' ) {
			if ( $cwConfig->get( 'Private' ) ) {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/5/5f/Out_of_date_clock_icon.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-inactive-private' )->parse() . '</span></div>';
			} else {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/5/5f/Out_of_date_clock_icon.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-inactive' )->parse() . '</span></div>';
			}
		}
	}

	/**
	 * phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	 */
	public function onRecentChange_save( $recentChange ) {
 		// phpcs:enable

		if ( $recentChange->mAttribs['rc_type'] !== RC_LOG ) {
			return;
		}

		$globalUserGroups = CentralAuthUser::getInstanceByName( $recentChange->mAttribs['rc_user_text'] )->getGlobalGroups();
		if ( !in_array( 'trustandsafety', $globalUserGroups ) ) {
			return;
		}

		$data = [
			'writekey' => $this->options->get( 'MirahezeReportsWriteKey' ),
			'username' => $recentChange->mAttribs['rc_user_text'],
			'log' => $recentChange->mAttribs['rc_log_type'] . '/' . $recentChange->mAttribs['rc_log_action'],
			'wiki' => WikiMap::getCurrentWikiId(),
			'comment' => $this->commentStore->getComment( 'rc_comment', $recentChange->mAttribs )->text,
		];

		$this->httpRequestFactory->post( 'https://reports.miraheze.org/api/ial', [ 'postData' => $data ] );
	}

	public function onBlockIpComplete( $block, $user, $priorBlock ) {
		// TODO: do we want to add localization support for these keywords, so they match in other languages as well?
		$blockAlertKeywords = $this->options->get( 'MirahezeReportsBlockAlertKeywords' );

		foreach ( $blockAlertKeywords as $keyword ) {
			// use strtolower for case insensitivity
			if ( str_contains( strtolower( $block->getReasonComment()->text ), strtolower( $keyword ) ) ) {
				$data = [
					'writekey' => $this->options->get( 'MirahezeReportsWriteKey' ),
					'username' => $block->getTargetName(),
					'reporter' => $user->getName(),
					'report' => 'people-other',
					'evidence' => 'This is an automatic report. A user was blocked on ' . WikiMap::getCurrentWikiId() . ', and the block matched keyword "' . $keyword . '." The block ID is: ' . $block->getId() . ', and the block reason is: ' . $block->getReasonComment()->text,
				];

				$this->httpRequestFactory->post( 'https://reports.miraheze.org/api/report', [ 'postData' => $data ] );

				break;
			}
		}
	}

	public function onContributionsToolLinks( $id, Title $title, array &$tools, SpecialPage $specialPage ) {
		$username = $title->getText();

		if ( !IPUtils::isIPAddress( $username ) ) {
			$globalUserGroups = CentralAuthUser::getInstanceByName( $username )->getGlobalGroups();

			if (
				!in_array( 'steward', $globalUserGroups ) &&
				!in_array( 'global-sysop', $globalUserGroups ) &&
				!$specialPage->getUser()->isAllowed( 'centralauth-lock' )
			) {
				return;
			}

			$tools['centralauth'] = Linker::makeExternalLink(
				'https://meta.miraheze.org/wiki/Special:CentralAuth/' . $username,
				strtolower( $specialPage->msg( 'centralauth' )->text() )
			);
		}
	}

	/**
	 * phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	 *
	 * @param Title $title
	 * @param string &$url
	 * @param string $query
	 */
	public function onGetLocalURL__Internal( $title, &$url, $query ) {
		// phpcs:enable

		if ( defined( 'MW_PHPUNIT_TEST' ) ) {
			return;
		}

		// If the URL contains wgScript, rewrite it to use wgArticlePath
		if ( str_contains( $url, $this->options->get( MainConfigNames::Script ) ) ) {
			$dbkey = wfUrlencode( $title->getPrefixedDBkey() );
			$url = str_replace( '$1', $dbkey, $this->options->get( MainConfigNames::ArticlePath ) );
			if ( $query !== '' ) {
				$url = wfAppendQuery( $url, $query );
			}
		}
	}

	private function addFooterLink( $skin, $desc, $page ) {
		if ( $skin->msg( $desc )->inContentLanguage()->isDisabled() ) {
			$title = null;
		} else {
			$title = Title::newFromText( $skin->msg( $page )->inContentLanguage()->text() );
		}

		if ( !$title ) {
			return '';
		}

		return Html::element( 'a',
			[ 'href' => $title->fixSpecialName()->getLinkURL() ],
			$skin->msg( $desc )->text()
		);
	}

	/** Removes redis keys for jobrunner */
	private function removeRedisKey( string $key ) {
		$jobTypeConf = $this->options->get( MainConfigNames::JobTypeConf );
		if ( !isset( $jobTypeConf['default']['redisServer'] ) || !$jobTypeConf['default']['redisServer'] ) {
			return;
		}

		$hostAndPort = IPUtils::splitHostAndPort( $jobTypeConf['default']['redisServer'] );

		if ( $hostAndPort ) {
			try {
				$redis = new Redis();
				$redis->connect( $hostAndPort[0], $hostAndPort[1] );
				$redis->auth( $jobTypeConf['default']['redisConfig']['password'] );
				$redis->del( $redis->keys( $key ) );
			} catch ( Throwable $ex ) {
				// empty
			}
		}
	}

	/** Remove memcached keys */
	private function removeMemcachedKey( string $key ) {
		$memcachedServers = $this->options->get( 'MirahezeMagicMemcachedServers' );

		try {
			foreach ( $memcachedServers as $memcachedServer ) {
				$memcached = new Memcached();

				$memcached->addServer( $memcachedServer[0], (string)$memcachedServer[1] );

				// Fetch all keys
				$keys = $memcached->getAllKeys();
				if ( !is_array( $keys ) ) {
					return;
				}

				foreach ( $keys as $item ) {
					// Decide which keys to delete
					if ( preg_match( "/{$key}/", $item ) ) {
						$memcached->delete( $item );
					} else {
						continue;
					}
				}
			}
		} catch ( Throwable $ex ) {
			// empty
		}
	}
}
