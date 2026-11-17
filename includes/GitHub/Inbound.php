<?php
/**
 * What Forge does with a message from GitHub.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

use Blueworx\Forge\Tenancy\ClientSites;
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
 * connected GitHub. Nothing else GitHub sends is acted on yet.
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

		if ( 'issues' !== $event || 'opened' !== ( $payload['action'] ?? '' ) ) {
			return 'ignored';
		}

		return self::opened( $payload );
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

		if ( null !== $own ) {
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

		// Not a field anyone edits, so the rules above never pass it through.
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
