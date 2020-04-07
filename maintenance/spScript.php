<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class spScript extends Maintenance {
	public function __construct() {
		parent::__construct();
	}

	public function execute() {
		global $wgDBname;
		
		$up = $this->getSettingsValue( 'wgUserProfileDisplay', $wgDBname );
		
		if ( isset( $up ) && $up ) {
			$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );
			
			if ( !isset( $up->activity ) ) {
				$up['activity'] = false;
			}

			if ( !isset( $up->articles ) ) {
				$up['articles'] = true;
			}

			if ( !isset( $up->avatar ) ) {
				$up['avatar'] = true;
			}

			if ( !isset( $up->awards ) ) {
				$up['awards'] = true;
			}

			if ( !isset( $up->board ) ) {
				$up['board'] = false;
			}

			if ( !isset( $up->custom ) ) {
				$up['custom'] = true;
			}

			if ( !isset( $up->foes ) ) {
				$up['foes'] = false;
			}

			if ( !isset( $up->friends ) ) {
				$up['friends'] = false;
			}

			if ( !isset( $up->games ) ) {
				$up['games'] = false;
			}

			if ( !isset( $up->gifts ) ) {
				$up['gifts'] = true;
			}

			if ( !isset( $up->interests ) ) {
				$up['interests'] = true;
			}

			if ( !isset( $up->personal ) ) {
				$up['personal'] = true;
			}

			if ( !isset( $up->profile ) ) {
				$up['profile'] = true;
			}

			if ( !isset( $up->stats ) ) {
				$up['stats'] = false;
			}

			if ( !isset( $up->userboxes ) ) {
				$up['userboxes'] = false;
			}

			$dbw->update(
				'cw_wikis',
				[
					'wiki_settings' => json_encode( $up ),
				],
				[
					'wiki_dbname' => $wgDBname
				],
				__METHOD__
			);

			$dbw->update(
				'mw_settings',
				[
					's_settings' => json_encode( $up ),
				],
				[
					's_dbname' => $wgDBname
				],
				__METHOD__
			);
		}

		$visualeditor = $this->hasExtension( 'visualeditor', $wiki[3] );
		$flow = $this->hasExtension( 'flow', $wiki[3] );
		// Collection installs Electron inaddition now.
		$electron = $this->hasExtension( 'collection', $wiki[3] );
		$citoid = $this->hasExtension( 'citoid', $wiki[3] );

		if ( $visualeditor || $flow || $electron || $citoid ) {
			$servicesvalue = $domain ? str_replace('https://', '', "'" . $domain . "'") : 'true';
			$allWikis[] = "$DBname: $servicesvalue";
		}
	}

	/**
	 * Similar to CreateWiki RemoteWiki function, just this reads from a file.
	 */
	private function getSettingsValue( $setting, $settingsjson ) {
		$settingsarray = json_decode( $settingsjson, true );

		if ( isset( $settingsarray[$setting] ) ) {
			return $settingsarray[$setting];
		}

		return null;
	}
}

$maintClass = 'spScript';
require_once RUN_MAINTENANCE_IF_MAIN;
