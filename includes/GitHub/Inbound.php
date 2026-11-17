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
 * alone, with a note to the team. A change made by someone who doesn't
 * belong to the repo is only ever a note.
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

		$linked = Links::add( (string) $item['id'], $repo, $number, $issue_id, 'github', (string) $item['title'], hash( 'sha256', (string) ( $issue['body'] ?? '' ) ), Links::time_of( $issue ) );

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

		// One comment with the way to the task, and the stage label.
		Jobs::queue( Outbound::LINK_COMMENT, (string) $item['id'], array(), true );
		Sync::linked( $item );

		return 'created';
	}

	/**
	 * A linked issue was edited (#682).
	 *
	 * A new title renames the task. A new description replaces the task's
	 * when the issue was raised in GitHub; on an issue Forge made, Forge's
	 * own stands and the team gets an internal note instead. A change by
	 * someone who doesn't belong to the repo is only ever a note: anyone can
	 * open an issue on a public repo, and its author can always edit it.
	 *
	 * The issue as it is now is compared with what Forge last saw of it, so
	 * an edit whose message went missing is still caught up with. A message
	 * older than the last one seen changes nothing.
	 *
	 * What is seen is noted before the task is written, so the save's own
	 * listener knows the change came from GitHub and sends nothing back.
	 *
	 * @param array<string, mixed> $payload The decoded body.
	 * @return string updated, noted, unchanged, ignored or failed.
	 */
	private static function edited( array $payload ): string {
		$issue = (array) ( $payload['issue'] ?? array() );
		$link  = Links::for_issue( (int) ( $issue['id'] ?? 0 ) );
		$item  = null === $link ? null : Items::get( (string) $link['item_id'] );

		if ( null === $link || null === $item ) {
			return 'ignored';
		}

		if ( self::older( $issue, $link ) ) {
			return 'unchanged';
		}

		$id      = (string) $item['id'];
		$trusted = self::trusted( $payload, Settings::login() );
		$values  = array();
		$seen    = array();
		$told    = array();
		$title   = IssueText::title( (string) ( $issue['title'] ?? '' ) );

		if ( '' !== $title && (string) $link['title_seen'] !== $title ) {
			$seen['title_seen'] = $title;

			if ( (string) $item['title'] !== $title && $trusted ) {
				$values['title'] = $title;
			} elseif ( (string) $item['title'] !== $title ) {
				/* translators: 1: who changed it, as their GitHub login, 2: the title they gave it. */
				$told[] = sprintf( __( 'The issue\'s title was changed in GitHub by %1$s to "%2$s". Forge kept its own.', 'blueworx-forge' ), self::sender( $payload ), $title );
			}
		}

		if ( array_key_exists( 'body', $issue ) ) {
			$body = (string) ( $issue['body'] ?? '' );
			$hash = hash( 'sha256', $body );

			// What Forge would write itself is Forge's own edit, come back
			// before its job noted it as seen: not worth a note.
			$own = 'forge' === (string) $link['origin'] && hash( 'sha256', IssueText::issue_body( $item, Notify::link( $item ) ) ) === $hash;

			if ( (string) $link['body_hash'] !== $hash ) {
				$seen['body_hash'] = $hash;

				if ( 'github' === (string) $link['origin'] && $trusted ) {
					$values['problem'] = IssueText::to_html( $body );
				} elseif ( ! $own ) {
					/* translators: %s: who changed the description, as their GitHub login. */
					$told[] = sprintf( __( 'The issue\'s description was changed in GitHub by %s. Forge kept its own; copy anything you need across.', 'blueworx-forge' ), self::sender( $payload ) );
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
			// GitHub's already. The rest is noted after: a failed write must
			// leave GitHub's next try something to do.
			if ( isset( $values['title'] ) ) {
				Links::seen( $id, array( 'title_seen' => $seen['title_seen'] ) );
			}

			if ( ! self::write( $id, $checked['values'] ) ) {
				return 'failed';
			}

			$outcome = 'updated';
		}

		if ( array() !== $told && null === self::note( $item, implode( ' ', $told ) ) ) {
			return 'failed';
		}

		// The same edit is taken in, or noted, once.
		Links::seen( $id, $seen );
		Links::updated( $id, Links::time_of( $issue ) );

		return array() !== $told && 'unchanged' === $outcome ? 'noted' : $outcome;
	}

	/**
	 * Whether a message is about an older state of the issue than the last
	 * one Forge saw. GitHub may deliver messages out of order.
	 *
	 * @param array<string, mixed> $issue The issue, as GitHub sends it.
	 * @param array<string, mixed> $link  The link, as read.
	 * @return bool
	 */
	public static function older( array $issue, array $link ): bool {
		return Links::time_of( $issue ) < (int) ( $link['updated_seen'] ?? 0 );
	}

	/**
	 * Whether a change made in GitHub may change the task. Anyone can open
	 * an issue on a public repo, and its author can always edit, close and
	 * reopen it, so only the connected account and people who belong to the
	 * repo count. Only they can change somebody else's issue, so a change
	 * by anyone other than its author counts too.
	 *
	 * @param array<string, mixed> $payload The decoded body.
	 * @param string               $login   The account Forge is connected as.
	 * @return bool
	 */
	public static function trusted( array $payload, string $login ): bool {
		$sender = (string) ( $payload['sender']['login'] ?? '' );
		$author = (string) ( $payload['issue']['user']['login'] ?? '' );

		if ( '' === $sender ) {
			return false;
		}

		if ( '' !== $login && 0 === strcasecmp( $login, $sender ) ) {
			return true;
		}

		if ( 0 !== strcasecmp( $author, $sender ) ) {
			return true;
		}

		return in_array( strtoupper( (string) ( $payload['issue']['author_association'] ?? '' ) ), array( 'OWNER', 'MEMBER', 'COLLABORATOR' ), true );
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
	 * the team gets a note instead. So does a close by someone who doesn't
	 * belong to the repo, such as the stranger who opened it. GitHub's state
	 * is recorded as seen, so a second message about the same close does
	 * nothing.
	 *
	 * @param array<string, mixed> $payload The decoded body.
	 * @return string moved, noted, nothing, unchanged, ignored or failed.
	 */
	private static function closed( array $payload ): string {
		$issue = (array) ( $payload['issue'] ?? array() );
		$link  = Links::for_issue( (int) ( $issue['id'] ?? 0 ) );
		$item  = null === $link ? null : Items::get( (string) $link['item_id'] );

		if ( null === $link || null === $item ) {
			return 'ignored';
		}

		if ( self::older( $issue, $link ) ) {
			return 'unchanged';
		}

		if ( 'closed' === (string) $link['state_seen'] ) {
			return 'nothing';
		}

		$reason   = (string) ( $issue['state_reason'] ?? '' );
		$decision = self::on_close( $item, $reason );
		$outcome  = 'nothing';

		if ( 'move' === $decision && ! self::trusted( $payload, Settings::login() ) ) {
			$decision = 'note';
		}

		// A refusal is a note: GitHub never works round a rule.
		if ( 'move' === $decision ) {
			/* translators: %s: who closed the issue, as their GitHub login. */
			$why      = sprintf( __( 'Closed in GitHub by %s.', 'blueworx-forge' ), self::sender( $payload ) );
			$decision = self::to_review( (string) $item['id'], $why ) ? 'moved' : 'note';
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
		Links::updated( (string) $item['id'], Links::time_of( $issue ) );

		return $outcome;
	}

	/**
	 * What reopening a linked issue in GitHub does (#682): never anything to
	 * the task, and the team is always told, unless it is Forge's own reopen
	 * coming back.
	 *
	 * @param array<string, mixed> $item      The task.
	 * @param array<string, mixed> $link      Its link, as read.
	 * @param bool                 $reopening Whether Forge is reopening the issue itself right now.
	 * @return string note or nothing.
	 */
	public static function on_reopen( array $item, array $link, bool $reopening ): string {
		// Finished in Forge, so whoever reopened it, Forge didn't.
		if ( 'closed' === Sync::state_for( $item )['state'] ) {
			return 'note';
		}

		// Closed in GitHub by a person, and now reopened there.
		if ( 'closed' === (string) ( $link['state_seen'] ?? '' ) && ! $reopening ) {
			return 'note';
		}

		return 'nothing';
	}

	/**
	 * A linked issue was reopened in GitHub (#682). Forge never reopens a
	 * task for it.
	 *
	 * @param array<string, mixed> $payload The decoded body.
	 * @return string noted, nothing, unchanged, ignored or failed.
	 */
	private static function reopened( array $payload ): string {
		$issue = (array) ( $payload['issue'] ?? array() );
		$link  = Links::for_issue( (int) ( $issue['id'] ?? 0 ) );
		$item  = null === $link ? null : Items::get( (string) $link['item_id'] );

		if ( null === $link || null === $item ) {
			return 'ignored';
		}

		if ( self::older( $issue, $link ) ) {
			return 'unchanged';
		}

		if ( 'open' === (string) $link['state_seen'] ) {
			return 'nothing';
		}

		$outcome   = 'nothing';
		$reopening = Jobs::RUNNING === Jobs::status( Outbound::SET_STATE, (string) $item['id'] );

		if ( 'note' === self::on_reopen( $item, $link, $reopening ) ) {
			/* translators: %s: who reopened the issue, as their GitHub login. */
			$body = sprintf( __( 'The issue was reopened in GitHub by %s. Forge has left this task where it is.', 'blueworx-forge' ), self::sender( $payload ) );

			if ( null === self::note( $item, $body ) ) {
				return 'failed';
			}

			$outcome = 'noted';
		}

		Links::seen( (string) $item['id'], array( 'state_seen' => 'open' ) );
		Links::updated( (string) $item['id'], Links::time_of( $issue ) );

		return $outcome;
	}

	/**
	 * Moves a task on to In review for a closed issue, with every check a
	 * person's move has. Someone may save the task at the same moment, so a
	 * version that has moved is read again and tried once more.
	 *
	 * @param string $id  The task.
	 * @param string $why What the history says about the move.
	 * @return bool Whether it moved.
	 */
	private static function to_review( string $id, string $why ): bool {
		for ( $try = 0; $try < 2; $try++ ) {
			$item = Items::get( $id );

			if ( null === $item ) {
				return false;
			}

			$moved = Transition::move( $item, 'in-review', (int) $item['record_version'], Settings::actor(), Events::VIA_GITHUB, '', $why );

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

		if ( ! Links::add( (string) $item['id'], $repo, $number, (int) $issue['id'], 'forge', (string) $item['title'], hash( 'sha256', $body ), Links::time_of( $issue ) ) ) {
			return 'duplicate';
		}

		// The task may have moved on, or ended, while its issue was made.
		Sync::linked( $item );

		return 'linked';
	}
}
