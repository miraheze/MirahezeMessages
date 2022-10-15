<?php

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;
use Miraheze\ManageWiki\Helpers\ManageWikiSettings;
use Wikimedia\IPUtils;

class MirahezeMagicHooks {
	/**
	 * Avoid filtering automatic account creation
	 *
	 * @param VariableHolder $vars
	 * @param Title $title
	 * @param User $user
	 * @param array &$skipReasons
	 * @return bool
	 */
	public static function onAbuseFilterShouldFilterAction(
		VariableHolder $vars,
		Title $title,
		User $user,
		array &$skipReasons
	) {
		$varManager = AbuseFilterServices::getVariablesManager();

		$action = $varManager->getVar( $vars, 'action', 1 )->toString();
		if ( $action === 'autocreateaccount' ) {
			$skipReasons[] = 'Blocking automatic account creation is not allowed';

			return false;
		}

		return true;
	}

	public static function onCreateWikiCreation( $DBname ) {
		// wfShouldEnableSwift() is defined in LocalSettings.php
		// we don't need to do anything here if using swift
		// @TODO remove this entire hook once on swift everywhere
		if ( wfShouldEnableSwift( $DBname ) ) {
			return;
		}

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		// Create static directory for wiki
		if ( !file_exists( "/mnt/mediawiki-static/{$DBname}" ) ) {
			Shell::command( '/bin/mkdir', '-p', "/mnt/mediawiki-static/{$DBname}" )
				->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();
		}

		// Copy SocialProfile images
		if ( file_exists( "/mnt/mediawiki-static/{$DBname}" ) ) {
			Shell::command(
				'/bin/cp',
				'-r',
				'/srv/mediawiki/w/extensions/SocialProfile/avatars',
				"/mnt/mediawiki-static/{$DBname}/avatars"
			)
				->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();

			Shell::command(
				'/bin/cp',
				'-r',
				'/srv/mediawiki/w/extensions/SocialProfile/awards',
				"/mnt/mediawiki-static/{$DBname}/awards"
			)
				->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();
		}
	}

	public static function onCreateWikiDeletion( $dbw, $wiki ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );

		$dbw = wfGetDB( DB_PRIMARY, [], $config->get( 'EchoSharedTrackingDB' ) );

		$dbw->delete( 'echo_unread_wikis', [ 'euw_wiki' => $wiki ] );

		foreach ( $config->get( 'LocalDatabases' ) as $db ) {
			$manageWikiSettings = new ManageWikiSettings( $db );

			foreach ( $config->get( 'ManageWikiSettings' ) as $var => $setConfig ) {
				if (
					$setConfig['type'] === 'database' &&
					$manageWikiSettings->list( $var ) === $wiki
				) {
					$manageWikiSettings->remove( $var );
					$manageWikiSettings->commit();
				}
			}
		}

		// wfShouldEnableSwift() is defined in LocalSettings.php
		if ( wfShouldEnableSwift( $wiki ) ) {
			Shell::command(
				'swift', 'delete',
				'miraheze-mw',
				'--prefix', $wiki . '/',
				'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ] )
				->restrict( Shell::RESTRICT_NONE )
				->execute();
		} elseif ( file_exists( "/mnt/mediawiki-static/$wiki" ) ) {
			Shell::command( '/bin/rm', '-rf', "/mnt/mediawiki-static/$wiki" )
				->limits( [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ] )
				->restrict( Shell::RESTRICT_NONE )
				->execute();
		}

		static::removeRedisKey( "*{$wiki}*" );
		// static::removeMemcachedKey( ".*{$wiki}.*" );
	}

	public static function onCreateWikiRename( $dbw, $old, $new ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );

		$dbw = wfGetDB( DB_PRIMARY, [], $config->get( 'EchoSharedTrackingDB' ) );

		$dbw->update( 'echo_unread_wikis', [ 'euw_wiki' => $new ], [ 'euw_wiki' => $old ] );

		foreach ( $config->get( 'LocalDatabases' ) as $db ) {
			$manageWikiSettings = new ManageWikiSettings( $db );

			foreach ( $config->get( 'ManageWikiSettings' ) as $var => $setConfig ) {
				if (
					$setConfig['type'] === 'database' &&
					$manageWikiSettings->list( $var ) === $old
				) {
					$manageWikiSettings->modify( [ $var => $new ] );
					$manageWikiSettings->commit();
				}
			}
		}

		// Does not work - swift does not allow to connect to other wiki containers with it's current setup (need to be able to connect to $old and $new here)

		// @TODO convert to a job

		// We define the backends here to have 2 seperate (additional) backends for $old and $new
		// and we rewrite the container paths from the default 'miraheze-swift' backend
		// to match that of $old or $new and to be able to access the containers from another wiki

		global $wgFileBackends;
		$wgFileBackends[1] = $wgFileBackends[0];
		$wgFileBackends[2] = $wgFileBackends[0];

		$new = 'testwiki';
		$old = 'betawiki';

		$wgFileBackends[1]['name'] = "miraheze-swift-$old";
		$wgFileBackends[2]['name'] = "miraheze-swift-$new";

		foreach ( $wgFileBackends[0]['containerPaths'] as $container => $config ) {
			$wgFileBackends[1]['containerPaths'][$container]['directory'] = str_replace(
				$wgDBname, $old, $wgFileBackends[0]['containerPaths'][$container]['directory']
			);

			$wgFileBackends[2]['containerPaths'][$container]['directory'] = str_replace(
				$wgDBname, $new, $wgFileBackends[0]['containerPaths'][$container]['directory']
			);
		}

		$oldBackend = MediaWiki\MediaWikiServices::getInstance()->getFileBackendGroup()->get( "miraheze-swift-$old" );
		$newBackend = MediaWiki\MediaWikiServices::getInstance()->getFileBackendGroup()->get( "miraheze-swift-$new" );

		$subdirectories = $oldBackend->getDirectoryList( [
			'dir' => $oldBackend->getContainerStoragePath( 'local-public' ),
			'adviseStat' => false,
		] );

		if ( $subdirectories ) {
			foreach ( $subdirectories as $directory ) {
				$directory = ltrim( $directory, $old );
				var_dump( $directory );
				$files = $oldBackend->getTopFileList( [
					'dir' => $oldBackend->getContainerStoragePath( 'local-public/' . $directory ),
					'adviseStat' => false,
				] );

				if ( $files ) {
					foreach ( $files as $file ) {
						var_dump( $file );
						$file = ltrim( $file, $old );
						var_dump( $oldBackend->normalizeStoragePath( $oldBackend->getContainerStoragePath( 'local-public/' . $directory ) . '/' . basename( $file ) ) );
						/* $oldBackend->quickMove( [
							'src' => $oldBackend->normalizeStoragePath( $oldBackend->getContainerStoragePath( 'local-public/' . $directory ) . '/' . basename( $file ) ),
							'dst' => $newBackend->normalizeStoragePath( $newBackend->getContainerStoragePath( 'local-public/' . $directory ) . '/' . basename( $file ) )
						] ); */
					}
				}
			}
		}

		$backend->clean( [
			'dir' => $oldBackend->getContainerStoragePath( 'local-public' ),
			'recursive' => true,
		] );

		static::removeRedisKey( "*{$old}*" );
		// static::removeMemcachedKey( ".*{$old}.*" );
	}

	public static function onCreateWikiStatePrivate( $dbname ) {
		$localRepo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		$sitemaps = $localRepo->getBackend()->getTopFileList( [
			'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps',
			'adviseStat' => false,
		] );

		foreach ( $sitemaps as $sitemap ) {
			$status = $localRepo->getBackend()->quickDelete( [
				'src' => $localRepo->getZonePath( 'public' ) . '/' . $sitemap,
			] );

			if ( !$status->isOK() ) {
				/**
				 * We need to log this, as otherwise the sitemaps may
				 * not be being deleted for private wikis. We should know that.
				 */
				$statusMessage = Status::wrap( $status )->getWikitext();
				wfDebugLog( 'MirahezeMagic', "Sitemap \"{$sitemap}\" failed to delete: {$statusMessage}" );
			}
		}

		$localRepo->getBackend()->clean( [ 'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps' ] );
	}

	public static function onCreateWikiTables( &$tables ) {
		$tables['localnames'] = 'ln_wiki';
		$tables['localuser'] = 'lu_wiki';
	}

	/**
	 * From WikimediaMessages. Allows us to add new messages,
	 * and override ones.
	 *
	 * @param string &$lcKey Key of message to lookup.
	 * @return bool
	 */
	public static function onMessageCacheGet( &$lcKey ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );
		static $keys = [
			'centralauth-groupname',
			'dberr-problems',
			'dberr-again',
			'globalblocking-ipblocked',
			'globalblocking-ipblocked-range',
			'globalblocking-ipblocked-xff',
			'privacypage',
			'prefs-help-realname',
			'newsignuppage-loginform-tos',
			'newsignuppage-must-accept-tos',
			'importtext',
			'importdump-help-reason',
			'importdump-help-target',
			'importdump-help-upload-file',
			'oathauth-step1',
			'centralauth-merge-method-admin-desc',
			'centralauth-merge-method-admin',
			'restriction-protect',
			'restriction-delete',
			'wikibase-sitelinks-miraheze',
			'centralauth-login-error-locked',
			'snapwikiskin',
			'skinname-snapwikiskin',
			'uploadtext',
			'group-checkuser',
			'group-checkuser-member',
			'grouppage-checkuser',
			'group-bureaucrat',
			'grouppage-bureaucrat',
			'group-bureaucrat-member',
			'group-sysop',
			'grouppage-sysop',
			'group-sysop-member',
			'group-interface-admin',
			'grouppage-interface-admin',
			'group-interface-admin-member',
			'group-bot',
			'grouppage-bot',
			'group-bot-member',
			'grouppage-user',
		];

		if ( in_array( $lcKey, $keys, true ) ) {
			$prefixedKey = "miraheze-$lcKey";
			// MessageCache uses ucfirst if ord( key ) is < 128, which is true of all
			// of the above.  Revisit if non-ASCII keys are used.
			$ucKey = ucfirst( $lcKey );
			$cache = MediaWikiServices::getInstance()->getMessageCache();

			if (
			// Override order:
			// 1. If the MediaWiki:$ucKey page exists, use the key unprefixed
			// (in all languages) with normal fallback order.  Specific
			// language pages (MediaWiki:$ucKey/xy) are not checked when
			// deciding which key to use, but are still used if applicable
			// after the key is decided.
			//
			// 2. Otherwise, use the prefixed key with normal fallback order
			// (including MediaWiki pages if they exist).
			$cache->getMsgFromNamespace( $ucKey, $config->get( 'LanguageCode' ) ) === false
			) {
				$lcKey = $prefixedKey;
			}
		}

		return true;
	}

	/**
	 * Enables global interwiki for [[mh:wiki:Page]]
	 */
	public static function onHtmlPageLinkRendererEnd( $linkRenderer, $target, $isKnown, &$text, &$attribs, &$ret ) {
		$target = (string)$target;
		$tooltip = $target;
		$useText = true;

		$ltarget = strtolower( $target );
		$ltext = strtolower( HtmlArmor::getHtml( $text ) );

		if ( $ltarget == $ltext ) {
			// Allow link piping, but don't modify $text yet
			$useText = false;
		}

		$target = explode( ':', $target );

		if ( count( $target ) < 2 ) {
			// Not enough parameters for interwiki
			return true;
		}

		if ( $target[0] == '0' ) {
			array_shift( $target );
		}

		$prefix = strtolower( $target[0] );

		if ( $prefix != 'mh' ) {
			// Not interesting
			return true;
		}

		$wiki = strtolower( $target[1] );
		$target = array_slice( $target, 2 );
		$target = implode( ':', $target );

		if ( !$useText ) {
			$text = $target;
		}
		if ( $text == '' ) {
			$text = $wiki;
		}

		$target = str_replace( ' ', '_', $target );
		$target = urlencode( $target );
		$linkURL = "https://$wiki.miraheze.org/wiki/$target";

		$attribs = [
			'href' => $linkURL,
			'class' => 'extiw',
			'title' => $tooltip
		];

		return true;
	}

	/**
	 * Hard redirects all pages like Mh:Wiki:Page as global interwiki.
	 */
	public static function onInitializeArticleMaybeRedirect( $title, $request, &$ignoreRedirect, &$target, $article ) {
		$title = explode( ':', $title );
		$prefix = strtolower( $title[0] );

		if ( count( $title ) < 3 || $prefix !== 'mh' ) {
			return true;
		}

		$wiki = strtolower( $title[1] );
		$page = implode( ':', array_slice( $title, 2 ) );
		$page = str_replace( ' ', '_', $page );
		$page = urlencode( $page );

		$target = "https://$wiki.miraheze.org/wiki/$page";

		return true;
	}

	public static function onTitleReadWhitelist( Title $title, User $user, &$whitelisted ) {
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
			'ResetPassword',
			'Watchlist'
		];

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

	public static function onGlobalUserPageWikis( &$list ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );
		$cwCacheDir = $config->get( 'CreateWikiCacheDirectory' );
		if ( file_exists( "{$cwCacheDir}/databases.json" ) ) {
			$databasesArray = json_decode( file_get_contents( "{$cwCacheDir}/databases.json" ), true );
			$list = array_keys( $databasesArray['combi'] );
			return false;
		}

		return true;
	}

	/** Removes redis keys for jobrunner */
	public static function removeRedisKey( string $key ) {
		global $wgJobTypeConf;

		if ( !isset( $wgJobTypeConf['default']['redisServer'] ) || !$wgJobTypeConf['default']['redisServer'] ) {
			return;
		}

		$hostAndPort = IPUtils::splitHostAndPort( $wgJobTypeConf['default']['redisServer'] );

		if ( $hostAndPort ) {
			try {
				$redis = new Redis();
				$redis->connect( $hostAndPort[0], $hostAndPort[1] );
				$redis->auth( $wgJobTypeConf['default']['redisConfig']['password'] );
				$redis->del( $redis->keys( $key ) );
			} catch ( Throwable $ex ) {
				// empty
			}
		}
	}

	/** Remove memcached keys */
	public static function removeMemcachedKey( string $key ) {
		global $wmgCacheSettings;

		$memcacheServer = explode( ':', $wmgCacheSettings['memcached']['server'][0] );

		try {
			$memcached = new \Memcached();
			$memcached->addServer( $memcacheServer[0], $memcacheServer[1] );

			// Fetch all keys
			$keys = $memcached->getAllKeys();
			if ( !is_array( $keys ) ) {
				return;
			}

			$memcached->getDelayed( $keys );

			$store = $memcached->fetchAll();

			$keys = $memcached->getAllKeys();
			foreach ( $keys as $item ) {
				// Decide which keys to delete
				if ( preg_match( "/{$key}/", $item ) ) {
					$memcached->delete( $item );
				} else {
					continue;
				}
			}
		} catch ( Throwable $ex ) {
			// empty
		}
	}

	public static function onMimeMagicInit( $magic ) {
		$magic->addExtraTypes( 'text/plain txt off' );
	}

	public static function onSkinAddFooterLinks( Skin $skin, string $key, array &$footerItems ) {
		if ( $key === 'places' ) {
			$footerItems['termsofservice'] = self::addFooterLink( $skin, 'termsofservice', 'termsofservicepage' );

			$footerItems['donate'] = self::addFooterLink( $skin, 'miraheze-donate', 'miraheze-donatepage' );
		}
	}

	public static function onUserGetRightsRemove( User $user, array &$aRights ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );
		// Remove read from stewards on staff wiki.
		if ( $config->get( 'DBname' ) === 'staffwiki' && $user->isRegistered() ) {
			$centralAuthUser = CentralAuthUser::getInstance( $user );

			if ( $centralAuthUser &&
				$centralAuthUser->exists() &&
				!in_array( $centralAuthUser->getId(), $config->get( 'MirahezeStaffAccessIds' ) )
			) {
				$aRights = array_unique( $aRights );
				unset( $aRights[array_search( 'read', $aRights )] );
			}
		}
	}

	public static function onSiteNoticeAfter( &$siteNotice, $skin ) {
		$cwConfig = new GlobalVarConfig( 'cw' );

		if ( $cwConfig->get( 'Closed' ) ) {
			if ( $cwConfig->get( 'Private' ) ) {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/0/02/Wiki_lock.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-closed-private' )->parse() . '</span></div>';
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
	public static function onRecentChange_save( RecentChange $recentChange ) {
 		// phpcs:enable

		if ( $recentChange->mAttribs['rc_type'] !== RC_LOG ) {
			return;
		}

		/** @var MirahezeMagicLogEmailManager $logEmailManager */
		$logEmailManager = MediaWikiServices::getInstance()->get( 'MirahezeMagic.LogEmailManager' );

		$user = User::newFromIdentity( $recentChange->getPerformerIdentity() );
		$conditions = $logEmailManager->findForUser( $user );

		if ( empty( $conditions ) ) {
			return;
		}

		$data = [
			'user_name' => $user->getName(),
			'wiki_id' => WikiMap::getCurrentWikiId(),
			'log_type' => $recentChange->mAttribs['rc_log_type'] . '/' . $recentChange->mAttribs['rc_log_action'],
			'comment_text' => $recentChange->mAttribs['rc_comment_text'],
		];

		foreach ( $conditions as $condition ) {
			// TODO: check for log entry types etc if wanted
			$logEmailManager->sendEmail( $data, $condition['email'] );
		}
	}

	private static function addFooterLink( $skin, $desc, $page ) {
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
}
