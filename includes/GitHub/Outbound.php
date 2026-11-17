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
	 * Shows the item's stage on its issue as one `forge:` label (#682).
	 */
	public const SET_LABEL = 'set_label';

	/**
	 * Closes or reopens the item's issue with the item (#682).
	 */
	public const SET_STATE = 'set_state';

	/**
	 * Gives the item's issue the item's title (#682).
	 */
	public const SET_TITLE = 'set_title';

	/**
	 * Rewrites a Forge-made issue's description from the item (#682).
	 */
	public const SET_BODY = 'set_body';

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
				$result = self::link_comment( $item );
				break;
			case self::SET_LABEL:
				$result = self::set_label( $item, self::payload( $job ) );
				break;
			case self::SET_STATE:
				$result = self::set_state( $item, self::payload( $job ) );
				break;
			case self::SET_TITLE:
				$result = self::set_title( $item );
				break;
			case self::SET_BODY:
				$result = self::set_body( $item );
				break;
			default:
				return new WP_Error( Client::REFUSED, __( 'Forge doesn\'t know how to send that change to GitHub.', 'blueworx-forge' ) );
		}

		// The issue was deleted, or moved to another repo: every later try
		// would fail the same way. The job is done, and the GitHub row on
		// Connections says which task lost its issue.
		if ( self::gone( $result ) ) {
			/* translators: %s: the task's title. */
			Settings::failed( sprintf( __( 'The GitHub issue for %s is gone.', 'blueworx-forge' ), (string) $item['title'] ) );

			return true;
		}

		return $result;
	}

	/**
	 * Whether GitHub said an issue is no longer there: deleted, or moved to
	 * another repo.
	 *
	 * @param mixed $result What a job gave back.
	 * @return bool
	 */
	public static function gone( $result ): bool {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}

		$data = $result->get_error_data();

		return is_array( $data ) && in_array( (int) ( $data['github_status'] ?? 0 ), array( 404, 410 ), true );
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

		$found = self::marked( $listed, $id, Settings::login() );

		if ( null !== $found ) {
			if ( Links::add( $id, $repo, (int) $found['number'], (int) $found['id'], 'forge', $title, hash( 'sha256', (string) ( $found['body'] ?? '' ) ), Links::time_of( $found ) ) ) {
				Sync::linked( $item );
			}

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

		// False when GitHub's own message about this issue linked it first,
		// and that link asked for the label and state itself.
		if ( Links::add( $id, $repo, $number, $issue_id, 'forge', $title, hash( 'sha256', $body ), Links::time_of( $made ) ) ) {
			Sync::linked( $item );
		}

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
	 * @param string       $login   The account Forge is connected as: only its issues count,
	 *                              so a stranger's copy of the marker never wins.
	 * @return array<string, mixed>|null
	 */
	public static function marked( array $issues, string $item_id, string $login ): ?array {
		foreach ( $issues as $issue ) {
			if (
				is_array( $issue )
				&& ! isset( $issue['pull_request'] )
				&& '' !== $login
				&& 0 === strcasecmp( $login, (string) ( $issue['user']['login'] ?? '' ) )
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

	/**
	 * What a job was asked to send. A pending job's payload is replaced when
	 * it is asked for again, so this is always the latest.
	 *
	 * @param array<string, mixed> $job The job, as claimed.
	 * @return array<string, mixed>
	 */
	private static function payload( array $job ): array {
		$decoded = json_decode( (string) ( $job['payload'] ?? '' ), true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Where the item's linked issue is, or '' when it has none.
	 *
	 * @param array<string, mixed> $item The item.
	 * @return string
	 */
	private static function issue_path( array $item ): string {
		$link = Links::for_item( (string) $item['id'] );

		return null === $link ? '' : '/repos/' . (string) $link['repo'] . '/issues/' . (int) $link['issue_number'];
	}

	/**
	 * Leaves the issue with one `forge:` label, the stage's. Reads the labels
	 * first, so a second run after a lost answer changes nothing, and leaves
	 * every label that is not Forge's alone.
	 *
	 * @param array<string, mixed> $item    The item.
	 * @param array<string, mixed> $payload The stage to show.
	 * @return true|WP_Error
	 */
	private static function set_label( array $item, array $payload ) {
		$path  = self::issue_path( $item );
		$stage = (string) ( $payload['stage'] ?? '' );

		if ( '' === $path || '' === $stage ) {
			return true;
		}

		$issue = Client::request( 'GET', $path );

		if ( is_wp_error( $issue ) ) {
			return $issue;
		}

		$wanted = Sync::label_for( $stage );
		$has    = false;

		foreach ( (array) ( $issue['labels'] ?? array() ) as $label ) {
			$name = is_array( $label ) ? (string) ( $label['name'] ?? '' ) : (string) $label;

			if ( $wanted === $name ) {
				$has = true;
				continue;
			}

			if ( 0 !== strpos( $name, Sync::LABEL_PREFIX ) ) {
				continue;
			}

			$gone = Client::request( 'DELETE', $path . '/labels/' . rawurlencode( $name ) );

			// Already taken off, by someone else or an earlier try.
			if ( is_wp_error( $gone ) && ! self::not_found( $gone ) ) {
				return $gone;
			}
		}

		if ( $has ) {
			return true;
		}

		// GitHub makes the label the first time it is used.
		$added = Client::request( 'POST', $path . '/labels', array( 'labels' => array( $wanted ) ) );

		return is_wp_error( $added ) ? $added : true;
	}

	/**
	 * Opens or closes the issue. Saying the same thing twice is harmless.
	 *
	 * @param array<string, mixed> $item    The item.
	 * @param array<string, mixed> $payload state, and reason when closing.
	 * @return true|WP_Error
	 */
	private static function set_state( array $item, array $payload ) {
		$path  = self::issue_path( $item );
		$state = (string) ( $payload['state'] ?? '' );

		if ( '' === $path || ! in_array( $state, array( 'open', 'closed' ), true ) ) {
			return true;
		}

		$body = array( 'state' => $state );

		if ( 'closed' === $state && '' !== (string) ( $payload['reason'] ?? '' ) ) {
			$body['state_reason'] = (string) $payload['reason'];
		}

		$changed = Client::request( 'PATCH', $path, $body );

		if ( is_wp_error( $changed ) ) {
			return $changed;
		}

		// GitHub's message about it is then Forge's own, and changes nothing.
		Links::seen( (string) $item['id'], array( 'state_seen' => $state ) );
		Links::updated( (string) $item['id'], Links::time_of( $changed ) );

		return true;
	}

	/**
	 * Gives the issue the item's title as it is now, and notes it as seen so
	 * GitHub's message about the rename changes nothing. Sending the same
	 * title twice is harmless.
	 *
	 * @param array<string, mixed> $item The item.
	 * @return true|WP_Error
	 */
	private static function set_title( array $item ) {
		$path  = self::issue_path( $item );
		$title = (string) $item['title'];

		if ( '' === $path || '' === $title ) {
			return true;
		}

		$changed = Client::request( 'PATCH', $path, array( 'title' => $title ) );

		if ( is_wp_error( $changed ) ) {
			return $changed;
		}

		Links::seen( (string) $item['id'], array( 'title_seen' => $title ) );
		Links::updated( (string) $item['id'], Links::time_of( $changed ) );

		return true;
	}

	/**
	 * Rewrites a Forge-made issue's description from the item as it is now.
	 * Nothing is sent when the issue already says it, so a second run
	 * changes nothing. An issue raised in GitHub is never written.
	 *
	 * @param array<string, mixed> $item The item.
	 * @return true|WP_Error
	 */
	private static function set_body( array $item ) {
		$link = Links::for_item( (string) $item['id'] );

		if ( null === $link || 'forge' !== (string) $link['origin'] ) {
			return true;
		}

		$body = IssueText::issue_body( $item, Notify::link( $item ) );
		$hash = hash( 'sha256', $body );

		if ( (string) $link['body_hash'] === $hash ) {
			return true;
		}

		$changed = Client::request( 'PATCH', '/repos/' . (string) $link['repo'] . '/issues/' . (int) $link['issue_number'], array( 'body' => $body ) );

		if ( is_wp_error( $changed ) ) {
			return $changed;
		}

		Links::seen( (string) $item['id'], array( 'body_hash' => $hash ) );
		Links::updated( (string) $item['id'], Links::time_of( $changed ) );

		return true;
	}

	/**
	 * Whether GitHub said the thing asked about is not there.
	 *
	 * @param WP_Error $error The refusal.
	 * @return bool
	 */
	private static function not_found( WP_Error $error ): bool {
		$data = $error->get_error_data();

		return is_array( $data ) && 404 === (int) ( $data['github_status'] ?? 0 );
	}
}
