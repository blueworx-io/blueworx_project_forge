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
use Blueworx\Forge\Work\Stages;
use Blueworx\Forge\Work\Transition;
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
 * raised in GitHub (#682). Closing a linked issue moves finished development
 * on to In review and no further; anything else it would change is left
 * alone, with a note to the team.
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
	 * @return string The outcome: pong, ignored, duplicate, created, linked,
	 *                updated, moved, noted, nothing, unchanged or failed.
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
			case 'closed':
				return self::closed( $payload );
			case 'reopened':
				return self::reopened( $payload );
			default:
				return 'ignored';
		}
	}

	/**
	 * What closing a linked issue in GitHub does to its task (#682).
	 *
	 * Finished development goes on to In review, and nothing from GitHub
	 * ever takes a task further: review is a person's decision. Anything
	 * else is left where it is and the team is told.
	 *
	 * @param array<string, mixed> $item   The task.
	 * @param string               $reason GitHub's state_reason.
	 * @return string move, note or nothing.
	 */
	public static function on_close( array $item, string $reason ): string {
		// Forge closed it, and this is GitHub saying so.
		if ( 'closed' === Sync::state_for( $item )['state'] ) {
			return 'nothing';
		}

		if ( in_array( (string) ( $item['stage'] ?? '' ), array( 'in-review', Stages::COMPLETED, Stages::RELEASED ), true ) ) {
			return 'nothing';
		}

		if ( 'not_planned' === $reason ) {
			return 'note';
		}

		return 'in-development' === (string) ( $item['stage'] ?? '' ) ? 'move' : 'note';
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

		// An edit counts only when it starts from what Forge last saw: GitHub
		// may deliver the message about an older rename after a newer one,
		// and that one must not write the older title back.
		if ( self::starts_from_seen( 'title', $changes, $link ) ) {
			$title = IssueText::title( (string) ( $issue['title'] ?? '' ) );

			if ( '' !== $title && (string) $item['title'] !== $title ) {
				$values['title']    = $title;
				$seen['title_seen'] = $title;
			}
		}

		if ( self::starts_from_seen( 'body', $changes, $link ) ) {
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

		/* translators: %s: who changed the description, as their GitHub login. */
		if ( $note && null === self::note( $item, sprintf( __( 'The issue\'s description was changed in GitHub by %s. Forge kept its own; copy anything you need across.', 'blueworx-forge' ), self::sender( $payload ) ) ) ) {
			return 'failed';
		}

		if ( isset( $seen['body_hash'] ) ) {
			// The same edit is noted, or taken in, once.
			Links::seen( $id, array( 'body_hash' => $seen['body_hash'] ) );
		}

		return $note && 'unchanged' === $outcome ? 'noted' : $outcome;
	}

	/**
	 * Whether GitHub's edit to a field starts from the value Forge last saw
	 * there. A real edit in GitHub always does; the late message about an
	 * older change does not. Titles are compared as Forge keeps them, cut
	 * to length, so a long title still matches.
	 *
	 * @param string               $field   title or body.
	 * @param array<string, mixed> $changes GitHub's `changes`.
	 * @param array<string, mixed> $link    The link, as read.
	 * @return bool
	 */
	public static function starts_from_seen( string $field, array $changes, array $link ): bool {
		if ( ! isset( $changes[ $field ] ) || ! is_array( $changes[ $field ] ) || ! array_key_exists( 'from', $changes[ $field ] ) ) {
			return false;
		}

		$from = (string) ( $changes[ $field ]['from'] ?? '' );

		if ( 'title' === $field ) {
			return IssueText::title( $from ) === (string) ( $link['title_seen'] ?? '' );
		}

		return hash( 'sha256', $from ) === (string) ( $link['body_hash'] ?? '' );
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
	 * A linked issue was closed in GitHub (#682).
	 *
	 * The move goes through Transition::move() like anybody's, so the In
	 * development check and every other rule still decide; when they say no,
	 * the team gets a note instead. GitHub's state is recorded as seen, so a
	 * second message about the same close does nothing.
	 *
	 * @param array<string, mixed> $payload The decoded body.
	 * @return string moved, noted, nothing, ignored or failed.
	 */
	private static function closed( array $payload ): string {
		$issue = (array) ( $payload['issue'] ?? array() );
		$link  = Links::for_issue( (int) ( $issue['id'] ?? 0 ) );
		$item  = null === $link ? null : Items::get( (string) $link['item_id'] );

		if ( null === $link || null === $item ) {
			return 'ignored';
		}

		if ( 'closed' === (string) $link['state_seen'] ) {
			return 'nothing';
		}

		$reason   = (string) ( $issue['state_reason'] ?? '' );
		$decision = self::on_close( $item, $reason );
		$outcome  = 'nothing';

		// A refusal is a note: GitHub never works round a rule.
		if ( 'move' === $decision ) {
			$decision = self::to_review( (string) $item['id'] ) ? 'moved' : 'note';
		}

		if ( 'moved' === $decision ) {
			$outcome = 'moved';
		}

		if ( 'note' === $decision ) {
			$body = 'not_planned' === $reason
				/* translators: %s: who closed the issue, as their GitHub login. */
				? sprintf( __( 'The issue was closed in GitHub by %s as not planned. Forge has left this task where it is.', 'blueworx-forge' ), self::sender( $payload ) )
				/* translators: %s: who closed the issue, as their GitHub login. */
				: sprintf( __( 'The issue was closed in GitHub by %s. Forge has left this task where it is.', 'blueworx-forge' ), self::sender( $payload ) );

			if ( null === self::note( $item, $body ) ) {
				return 'failed';
			}

			$outcome = 'noted';
		}

		Links::seen( (string) $item['id'], array( 'state_seen' => 'closed' ) );

		return $outcome;
	}

	/**
	 * A linked issue was reopened in GitHub (#682). Forge never reopens a
	 * task for it: when the task is finished in Forge the team is told, and
	 * otherwise this is GitHub answering Forge's own reopen.
	 *
	 * @param array<string, mixed> $payload The decoded body.
	 * @return string noted, nothing, ignored or failed.
	 */
	private static function reopened( array $payload ): string {
		$issue = (array) ( $payload['issue'] ?? array() );
		$link  = Links::for_issue( (int) ( $issue['id'] ?? 0 ) );
		$item  = null === $link ? null : Items::get( (string) $link['item_id'] );

		if ( null === $link || null === $item ) {
			return 'ignored';
		}

		if ( 'open' === (string) $link['state_seen'] ) {
			return 'nothing';
		}

		$outcome = 'nothing';

		if ( 'closed' === Sync::state_for( $item )['state'] ) {
			/* translators: %s: who reopened the issue, as their GitHub login. */
			$body = sprintf( __( 'The issue was reopened in GitHub by %s. Forge has left this task where it is.', 'blueworx-forge' ), self::sender( $payload ) );

			if ( null === self::note( $item, $body ) ) {
				return 'failed';
			}

			$outcome = 'noted';
		}

		Links::seen( (string) $item['id'], array( 'state_seen' => 'open' ) );

		return $outcome;
	}

	/**
	 * Moves a task on to In review for a closed issue, with every check a
	 * person's move has. Someone may save the task at the same moment, so a
	 * version that has moved is read again and tried once more.
	 *
	 * @param string $id The task.
	 * @return bool Whether it moved.
	 */
	private static function to_review( string $id ): bool {
		for ( $try = 0; $try < 2; $try++ ) {
			$item = Items::get( $id );

			if ( null === $item ) {
				return false;
			}

			$moved = Transition::move( $item, 'in-review', (int) $item['record_version'], Settings::actor(), Events::VIA_GITHUB );

			if ( ! is_wp_error( $moved ) ) {
				return true;
			}

			if ( 'bwx_forge_stale_version' !== $moved->get_error_code() ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Who did it on GitHub, as their login.
	 *
	 * @param array<string, mixed> $payload The decoded body.
	 * @return string
	 */
	private static function sender( array $payload ): string {
		$login = (string) ( $payload['sender']['login'] ?? '' );

		return '' === $login ? __( 'someone', 'blueworx-forge' ) : $login;
	}

	/**
	 * Tells the team, internally, about something done in GitHub that Forge
	 * did not follow. Never shown to the client.
	 *
	 * @param array<string, mixed> $item The item.
	 * @param string               $body What to tell them.
	 * @return array<string, mixed>|null The note, or null when it could not be written.
	 */
	private static function note( array $item, string $body ): ?array {
		return Comments::add(
			array(
				'item_id'        => (string) $item['id'],
				'client_site_id' => (string) $item['client_site_id'],
				'client_id'      => (string) $item['client_id'],
				'kind'           => Comments::COMMENT,
				'visibility'     => Comments::INTERNAL,
				'body'           => $body,
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
