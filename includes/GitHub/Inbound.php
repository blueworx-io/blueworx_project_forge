<?php
/**
 * What Forge does with a message from GitHub.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

use Blueworx\Forge\Slack\Notify;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Work\Comments;
use Blueworx\Forge\Work\Events;
use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\Levels;
use Blueworx\Forge\Work\Transitions;
use Blueworx\Forge\Work\Types;
use Blueworx\Forge\Work\Validate;

/**
 * #680. Only called with a message that has passed its signature check.
 *
 * A new issue in a repository linked to a client site becomes a loose
 * Feature in Future Idea on that site, recorded under the administrator who
 * connected GitHub. A linked issue renamed in GitHub renames its task, and
 * a description edited there follows into the task when the issue was
 * raised in GitHub (#682).
 *
 * The delivery id stops one message being handled twice, but GitHub's event
 * and delivery headers are not signed, so the issue id is checked as well:
 * one issue is one item, however many times it arrives.
 */
final class Inbound {

	/**
	 * Handles one verified message.
	 *
	 * @param string               $event   The X-GitHub-Event name.
	 * @param array<string, mixed> $payload The decoded body.
	 * @return string The outcome: pong, ignored, duplicate, created, linked or failed.
	 */
	public static function handle( string $event, array $payload ): string {
		if ( 'ping' === $event ) {
			return 'pong';
		}

		if ( 'issues' !== $event ) {
			return 'ignored';
		}

		switch ( (string) ( $payload['action'] ?? '' ) ) {
			case 'opened':
				return self::opened( $payload );
			case 'edited':
				return self::edited( $payload );
			default:
				return 'ignored';
		}
	}

	/**
	 * What a new issue becomes, before it is checked.
	 *
	 * @param array<string, mixed> $issue The issue, as GitHub sends it.
	 * @param string               $repo  Its repository, owner/name.
	 * @return array<string, mixed>
	 */
	public static function values_for( array $issue, string $repo ): array {
		$bug = false;

		foreach ( (array) ( $issue['labels'] ?? array() ) as $label ) {
			if ( is_array( $label ) && 'bug' === strtolower( trim( (string) ( $label['name'] ?? '' ) ) ) ) {
				$bug = true;
			}
		}

		return array(
			'title'       => IssueText::title( (string) ( $issue['title'] ?? '' ) ),
			'problem'     => IssueText::to_html( (string) ( $issue['body'] ?? '' ) ),
			'level'       => Levels::FEATURE,
			'work_type'   => $bug ? Types::BUG : Types::FEATURE,
			'github_repo' => $repo,
		);
	}

	/**
	 * An issue was opened.
	 *
	 * @param array<string, mixed> $payload The decoded body.
	 * @return string
	 */
	private static function opened( array $payload ): string {
		$issue    = (array) ( $payload['issue'] ?? array() );
		$repo     = Repos::normalise( (string) ( $payload['repository']['full_name'] ?? '' ) );
		$issue_id = (int) ( $issue['id'] ?? 0 );
		$number   = (int) ( $issue['number'] ?? 0 );
		$site_id  = '' === $repo ? '' : Repos::site_for( $repo );

		if ( '' === $site_id || 0 >= $issue_id ) {
			return 'ignored';
		}

		// An issue Forge made for an item: GitHub's message can beat Forge's own
		// create call, and it must link that item, never make another.
		$own = Items::get( IssueText::marker_in( (string) ( $issue['body'] ?? '' ) ) );

		// Item ids are not secret, so a marker alone proves nothing: it counts
		// only on an issue the connected account opened, in the item's own
		// repo, for an item with no issue yet. Anything else is taken in as
		// any other new issue.
		$login = Settings::login();

		if (
			null !== $own
			&& '' !== $login
			&& 0 === strcasecmp( $login, (string) ( $issue['user']['login'] ?? '' ) )
			&& Repos::normalise( (string) $own['github_repo'] ) === $repo
			&& null === Links::for_item( (string) $own['id'] )
		) {
			return self::linked( $own, $issue, $repo, $number );
		}

		if ( null !== Links::for_issue( $issue_id ) ) {
			return 'duplicate';
		}

		$site  = ClientSites::get( $site_id );
		$actor = Settings::actor();

		// A closed site takes no new work, as when somebody adds it by hand.
		if ( null === $site || 'active' !== (string) $site['status'] ) {
			return 'ignored';
		}

		// The repo is set below, not checked: it is where the issue came from.
		$sent = self::values_for( $issue, $repo );
		unset( $sent['github_repo'] );

		$checked = Validate::item( $sent, false );

		if ( array() !== $checked['errors'] || 0 >= $actor || false === get_userdata( $actor ) ) {
			return 'failed';
		}

		$values                = $checked['values'];
		$values['github_repo'] = $repo;

		$item = Items::create( (string) $site['id'], (string) $site['client_id'], $values, $actor );

		if ( null === $item ) {
			return 'failed';
		}

		$linked = Links::add( (string) $item['id'], $repo, $number, $issue_id, 'github', (string) $item['title'], hash( 'sha256', (string) ( $issue['body'] ?? '' ) ) );

		if ( ! $linked ) {
			// Another delivery of the same issue got there first, or the link
			// could not be written. Either way this item must not stand: it
			// would be a second copy, or one no later message could find.
			Items::delete( (string) $item['id'] );

			return null !== Links::for_issue( $issue_id ) ? 'duplicate' : 'failed';
		}

		Events::append(
			array(
				'item_id'        => (string) $item['id'],
				'client_site_id' => (string) $item['client_site_id'],
				'action'         => Events::CREATED,
				'to_stage'       => (string) $item['stage'],
				'gate'           => Transitions::CREATE_GATE,
				'cycle'          => (int) $item['cycle'],
				'actor'          => $actor,
				'via'            => Events::VIA_GITHUB,
				/* translators: 1: the repository, 2: the issue number, 3: who opened it on GitHub. */
				'detail'         => sprintf( __( 'Came from GitHub: %1$s#%2$d, opened by %3$s.', 'blueworx-forge' ), $repo, $number, (string) ( $issue['user']['login'] ?? '' ) ),
			)
		);

		// One comment with the way to the task, and never an edit to the issue.
		Jobs::queue( Outbound::LINK_COMMENT, (string) $item['id'], array(), true );

		return 'created';
	}

	/**
	 * A linked issue was edited (#682).
	 *
	 * A new title renames the task. A new description replaces the task's
	 * when the issue was raised in GitHub; on an issue Forge made, Forge's
	 * own stands and the team gets an internal note instead.
	 *
	 * What is seen is noted before the task is written, so the save's own
	 * listener knows the change came from GitHub and sends nothing back.
	 *
	 * @param array<string, mixed> $payload The decoded body.
	 * @return string updated, noted, unchanged, ignored or failed.
	 */
	private static function edited( array $payload ): string {
		$issue   = (array) ( $payload['issue'] ?? array() );
		$changes = (array) ( $payload['changes'] ?? array() );
		$link    = Links::for_issue( (int) ( $issue['id'] ?? 0 ) );
		$item    = null === $link ? null : Items::get( (string) $link['item_id'] );

		if ( null === $link || null === $item ) {
			return 'ignored';
		}

		$id     = (string) $item['id'];
		$values = array();
		$seen   = array();
		$note   = false;

		if ( array_key_exists( 'title', $changes ) ) {
			$title = IssueText::title( (string) ( $issue['title'] ?? '' ) );

			if ( '' !== $title && (string) $item['title'] !== $title ) {
				$values['title']    = $title;
				$seen['title_seen'] = $title;
			}
		}

		if ( array_key_exists( 'body', $changes ) ) {
			$body = (string) ( $issue['body'] ?? '' );
			$hash = hash( 'sha256', $body );

			if ( (string) $link['body_hash'] !== $hash ) {
				$seen['body_hash'] = $hash;

				if ( 'github' === (string) $link['origin'] ) {
					$values['problem'] = IssueText::to_html( $body );
				} else {
					// What Forge would write itself is Forge's own edit, come
					// back before its job noted it as seen: not worth a note.
					$note = hash( 'sha256', IssueText::issue_body( $item, Notify::link( $item ) ) ) !== $hash;
				}
			}
		}

		$outcome = 'unchanged';

		if ( array() !== $values ) {
			$checked = Validate::item( $values, true );

			if ( array() !== $checked['errors'] ) {
				return 'failed';
			}

			// Before the write, so the listener it fires sees the title as
			// GitHub's already. The description is noted after: an issue
			// raised in GitHub never has one sent back, and a failed write
			// must leave GitHub's next try something to do.
			if ( isset( $seen['title_seen'] ) ) {
				Links::seen( $id, array( 'title_seen' => $seen['title_seen'] ) );
			}

			if ( ! self::write( $id, $checked['values'] ) ) {
				return 'failed';
			}

			$outcome = 'updated';
		}

		if ( $note && null === self::note( $item, (string) ( $payload['sender']['login'] ?? '' ) ) ) {
			return 'failed';
		}

		if ( isset( $seen['body_hash'] ) ) {
			// The same edit is noted, or taken in, once.
			Links::seen( $id, array( 'body_hash' => $seen['body_hash'] ) );
		}

		return $note && 'unchanged' === $outcome ? 'noted' : $outcome;
	}

	/**
	 * Writes GitHub's change to the item. Someone may save the item at the
	 * same moment, so a version that has moved is read again and tried once
	 * more; GitHub sends again what still fails.
	 *
	 * @param string               $id     The item.
	 * @param array<string, mixed> $values Checked values.
	 * @return bool
	 */
	private static function write( string $id, array $values ): bool {
		for ( $try = 0; $try < 2; $try++ ) {
			$item = Items::get( $id );

			if ( null === $item ) {
				return false;
			}

			if ( null !== Items::update( $id, $values, (int) $item['record_version'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells the team, internally, that a Forge-made issue's description was
	 * changed in GitHub. Never shown to the client.
	 *
	 * @param array<string, mixed> $item  The item.
	 * @param string               $login Who changed it on GitHub.
	 * @return array<string, mixed>|null The note, or null when it could not be written.
	 */
	private static function note( array $item, string $login ): ?array {
		return Comments::add(
			array(
				'item_id'        => (string) $item['id'],
				'client_site_id' => (string) $item['client_site_id'],
				'client_id'      => (string) $item['client_id'],
				'kind'           => Comments::COMMENT,
				'visibility'     => Comments::INTERNAL,
				/* translators: %s: who changed the description, as their GitHub login. */
				'body'           => sprintf( __( 'The issue\'s description was changed in GitHub by %s. Forge kept its own; copy anything you need across.', 'blueworx-forge' ), '' === $login ? __( 'someone', 'blueworx-forge' ) : $login ),
				'author'         => Settings::actor(),
				'author_name'    => 'GitHub',
			),
			Comments::SCOPE_STAFF
		);
	}

	/**
	 * Links an item to the issue Forge made for it.
	 *
	 * @param array<string, mixed> $item   The item the marker names.
	 * @param array<string, mixed> $issue  The issue, as GitHub sends it.
	 * @param string               $repo   Its repository.
	 * @param int                  $number Its number there.
	 * @return string linked or duplicate.
	 */
	private static function linked( array $item, array $issue, string $repo, int $number ): string {
		if ( null !== Links::for_item( (string) $item['id'] ) || null !== Links::for_issue( (int) $issue['id'] ) ) {
			return 'duplicate';
		}

		$body = (string) ( $issue['body'] ?? '' );

		return Links::add( (string) $item['id'], $repo, $number, (int) $issue['id'], 'forge', (string) $item['title'], hash( 'sha256', $body ) ) ? 'linked' : 'duplicate';
	}
}
