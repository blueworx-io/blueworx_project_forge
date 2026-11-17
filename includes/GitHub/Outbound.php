<?php
/**
 * What each GitHub job does.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

use Blueworx\Forge\Slack\Notify;
use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\Levels;
use WP_Error;

/**
 * #681. Only ever called by Jobs::run(), with a job it has claimed. Each kind
 * reads the item as it is now, not as it was when the job was asked for, so a
 * choice changed before the job ran is the one that counts.
 */
final class Outbound {

	/**
	 * Makes the issue for an item whose repo was chosen.
	 */
	public const CREATE_ISSUE = 'create_issue';

	/**
	 * Comments on an issue raised in GitHub with the way to its task in Forge.
	 */
	public const LINK_COMMENT = 'link_comment';

	/**
	 * Does one job.
	 *
	 * @param array<string, mixed> $job The job, as claimed.
	 * @return true|WP_Error
	 */
	public static function perform( array $job ) {
		$item = Items::get( (string) ( $job['item_id'] ?? '' ) );

		// The item has gone since: there is nothing left to tell GitHub.
		if ( null === $item ) {
			return true;
		}

		switch ( (string) ( $job['kind'] ?? '' ) ) {
			case self::CREATE_ISSUE:
				return self::create_issue( $item, $job );
			case self::LINK_COMMENT:
				return self::link_comment( $item );
			default:
				return new WP_Error( Client::REFUSED, __( 'Forge doesn\'t know how to send that change to GitHub.', 'blueworx-forge' ) );
		}
	}

	/**
	 * Makes the item's issue in its repo and links the two.
	 *
	 * @param array<string, mixed> $item The item.
	 * @param array<string, mixed> $job  The job, as claimed.
	 * @return true|WP_Error
	 */
	private static function create_issue( array $item, array $job ) {
		$id = (string) $item['id'];

		if ( null !== Links::for_item( $id ) ) {
			return true;
		}

		$repo = Repos::normalise( (string) $item['github_repo'] );

		// No repo any more, or work that never has an issue. Nothing was made,
		// so the job goes: choosing a repo later must be free to make one.
		if ( '' === $repo || in_array( (string) $item['level'], array( Levels::PROJECT, Levels::MILESTONE ), true ) ) {
			Jobs::forget( self::CREATE_ISSUE, $id );

			return true;
		}

		$title = (string) $item['title'];
		$body  = IssueText::issue_body( $item, Notify::link( $item ) );

		// An earlier try may have made the issue and lost GitHub's answer: a
		// slow reply times out after GitHub has acted, perhaps while the repo
		// was another one for a moment. So every try looks first.
		$listed = Client::request( 'GET', self::lookup_path( $repo, (int) ( $job['created_at'] ?? 0 ) ) );

		if ( is_wp_error( $listed ) ) {
			return $listed;
		}

		$found = self::marked( $listed, $id );

		if ( null !== $found ) {
			Links::add( $id, $repo, (int) $found['number'], (int) $found['id'], 'forge', $title, hash( 'sha256', (string) ( $found['body'] ?? '' ) ) );

			return true;
		}

		$made = Client::request(
			'POST',
			'/repos/' . $repo . '/issues',
			array(
				'title' => $title,
				'body'  => $body,
			)
		);

		if ( is_wp_error( $made ) ) {
			return $made;
		}

		$number   = (int) ( $made['number'] ?? 0 );
		$issue_id = (int) ( $made['id'] ?? 0 );

		if ( 0 >= $number || 0 >= $issue_id ) {
			return new WP_Error( Client::REFUSED, __( 'GitHub did not say which issue it made.', 'blueworx-forge' ) );
		}

		// False when GitHub's own message about this issue linked it first.
		Links::add( $id, $repo, $number, $issue_id, 'forge', $title, hash( 'sha256', $body ) );

		return true;
	}

	/**
	 * Where to look for an issue already made for a job. GitHub's `since` is
	 * "updated after", and the issue can be made in the same second as the job
	 * or by a GitHub whose clock is behind this site's, so it reaches back an
	 * hour. Any author: the marker alone says which issue it is, whichever
	 * account made it.
	 *
	 * @param string $repo       owner/name.
	 * @param int    $created_at When the job was first asked for.
	 * @return string
	 */
	public static function lookup_path( string $repo, int $created_at ): string {
		return '/repos/' . $repo . '/issues?state=all&per_page=100&since=' . gmdate( 'Y-m-d\TH:i:s\Z', max( 0, $created_at - HOUR_IN_SECONDS ) );
	}

	/**
	 * The issue in a list that Forge made for this item, by its marker.
	 *
	 * @param array<mixed> $issues  Issues as GitHub lists them.
	 * @param string       $item_id The item.
	 * @return array<string, mixed>|null
	 */
	public static function marked( array $issues, string $item_id ): ?array {
		foreach ( $issues as $issue ) {
			if (
				is_array( $issue )
				&& ! isset( $issue['pull_request'] )
				&& 0 < (int) ( $issue['number'] ?? 0 )
				&& 0 < (int) ( $issue['id'] ?? 0 )
				&& IssueText::marker_in( (string) ( $issue['body'] ?? '' ) ) === $item_id
			) {
				return $issue;
			}
		}

		return null;
	}

	/**
	 * Comments on the item's issue with the way back to Forge.
	 *
	 * @param array<string, mixed> $item The item.
	 * @return true|WP_Error
	 */
	private static function link_comment( array $item ) {
		$link = Links::for_item( (string) $item['id'] );

		if ( null === $link ) {
			return true;
		}

		$path = '/repos/' . (string) $link['repo'] . '/issues/' . (int) $link['issue_number'] . '/comments';
		$url  = Notify::link( $item );
		$mark = '<!-- forge:' . (string) $item['id'] . ' -->';

		// The one comment Forge writes: a lost answer or a reopened job must
		// not make a second, so look for it first.
		$listed = Client::request( 'GET', $path . '?per_page=100' );

		if ( is_wp_error( $listed ) ) {
			return $listed;
		}

		foreach ( $listed as $comment ) {
			$text = is_array( $comment ) ? (string) ( $comment['body'] ?? '' ) : '';

			if ( false !== strpos( $text, $mark ) || false !== strpos( $text, $url ) ) {
				return true;
			}
		}

		/* translators: %s: where the task is in Forge. */
		$words = sprintf( __( 'Tracked in Forge: %s', 'blueworx-forge' ), $url );
		$said  = Client::request( 'POST', $path, array( 'body' => $words . "\n\n" . $mark ) );

		return is_wp_error( $said ) ? $said : true;
	}
}
