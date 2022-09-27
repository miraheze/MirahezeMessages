<?php

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MediaWikiServices;

/**
 * Format a notification as a human-readable string using IRC colour codes.
 *
 * Parameters:
 * - `add_interwiki_prefix`: Whether the titles should be prefixed with
 *   the first entry in the $wgLocalInterwikis array (or the value of
 *   $wgLocalInterwiki, if set).
 *   Default: false.
 *
 * @see $wgRCFeeds
 * @since 1.22
 */

class MirahezeIRCRCFeedFormatter implements RCFeedFormatter {
	/**
	 * @see RCFeedFormatter::getLine
	 * @param array $feed
	 * @param RecentChange $rc
	 * @param string|null $actionComment
	 * @return string|null
	 */
	public function getLine( array $feed, RecentChange $rc, $actionComment ) {
		$mainConfig = MediaWikiServices::getInstance()->getMainConfig();
		$useRCPatrol = $mainConfig->get( 'UseRCPatrol' );
		$useNPPatrol = $mainConfig->get( 'UseNPPatrol' );
		$localInterwikis = $mainConfig->get( 'LocalInterwikis' );
		$canonicalServer = $mainConfig->get( 'CanonicalServer' );
		$script = $mainConfig->get( 'Script' );
		$attribs = $rc->getAttributes();
		if ( $attribs['rc_type'] == RC_CATEGORIZE ) {
			// Don't send RC_CATEGORIZE events to IRC feed (T127360)
			return null;
		}

		/**
		 * Don't send renameuser log events to IRC for the
		 * Miraheze Trust and Safety team.
		 */
		if (
			$attribs['rc_type'] == RC_LOG &&
			$attribs['rc_log_type'] === 'renameuser'
		) {
			$globalUserGroups = CentralAuthUser::getInstanceByName( $attribs['rc_user_text'] )->getGlobalGroups();
			if ( in_array( 'trustandsafety', $globalUserGroups ) ) {
				return null;
			}
		}

		if ( $attribs['rc_type'] == RC_LOG ) {
			// Don't use SpecialPage::getTitleFor, backwards compatibility with
			// IRC API which expects "Log".
			$titleObj = Title::newFromText( 'Log/' . $attribs['rc_log_type'], NS_SPECIAL );
		} else {
			$titleObj = $rc->getTitle();
		}
		$title = $titleObj->getPrefixedText();
		$title = self::cleanupForIRC( $title );

		if ( $attribs['rc_type'] == RC_LOG ) {
			$url = '';
		} else {
			$url = $canonicalServer . $script;
			if ( $attribs['rc_type'] == RC_NEW ) {
				$query = '?oldid=' . $attribs['rc_this_oldid'];
			} else {
				$query = '?diff=' . $attribs['rc_this_oldid'] . '&oldid=' . $attribs['rc_last_oldid'];
			}
			if ( $useRCPatrol || ( $attribs['rc_type'] == RC_NEW && $useNPPatrol ) ) {
				$query .= '&rcid=' . $attribs['rc_id'];
			}

			Hooks::runner()->onIRCLineURL( $url, $query, $rc );
			$url .= $query;
		}

		if ( $attribs['rc_old_len'] !== null && $attribs['rc_new_len'] !== null ) {
			$szdiff = $attribs['rc_new_len'] - $attribs['rc_old_len'];
			if ( $szdiff < -500 ) {
				$szdiff = "\002$szdiff\002";
			} elseif ( $szdiff >= 0 ) {
				$szdiff = '+' . $szdiff;
			}
			// @todo i18n with parentheses in content language?
			$szdiff = '(' . $szdiff . ')';
		} else {
			$szdiff = '';
		}

		$user = self::cleanupForIRC( $attribs['rc_user_text'] );

		if ( $attribs['rc_type'] == RC_LOG ) {
			$targetText = $rc->getTitle()->getPrefixedText();
			$comment = self::cleanupForIRC( str_replace(
				"[[$targetText]]",
				"[[\00302$targetText\00310]]",
				$actionComment
			) );
			$flag = $attribs['rc_log_action'];
		} else {
			$store = MediaWikiServices::getInstance()->getCommentStore();
			$comment = self::cleanupForIRC( $store->getComment( 'rc_comment', $attribs )->text );
			$flag = '';
			if ( !$attribs['rc_patrolled']
				&& ( $useRCPatrol || $attribs['rc_type'] == RC_NEW && $useNPPatrol )
			) {
				$flag .= '!';
			}
			$flag .= ( $attribs['rc_type'] == RC_NEW ? "N" : "" )
				. ( $attribs['rc_minor'] ? "M" : "" ) . ( $attribs['rc_bot'] ? "B" : "" );
		}

		if ( $feed['add_interwiki_prefix'] === true && $localInterwikis ) {
			// we use the first entry in $wgLocalInterwikis in recent changes feeds
			$prefix = $localInterwikis[0];
		} elseif ( $feed['add_interwiki_prefix'] ) {
			$prefix = $feed['add_interwiki_prefix'];
		} else {
			$prefix = false;
		}
		if ( $prefix !== false ) {
			$titleString = "\00314[[\00303$prefix:\00307$title\00314]]";
		} else {
			$titleString = "\00314[[\00307$title\00314]]";
		}

		# see http://www.irssi.org/documentation/formats for some colour codes. prefix is \003,
		# no colour (\003) switches back to the term default
		$fullString = "$titleString\0034 $flag\00310 " .
			"\00302$url\003 \0035*\003 \00303$user\003 \0035*\003 $szdiff \00310$comment\003\n";

		return $fullString;
	}

	/**
	 * Remove newlines, carriage returns and decode html entities
	 * @param string $text
	 * @return string
	 */
	public static function cleanupForIRC( $text ) {
		return str_replace(
			[ "\n", "\r" ],
			[ " ", "" ],
			Sanitizer::decodeCharReferences( $text )
		);
	}
}
