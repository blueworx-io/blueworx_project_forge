<?php
/**
 * Gathering what the day's list is worked out from.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Standup;

use Blueworx\Forge\Capacity\Periods;
use Blueworx\Forge\Capacity\Position;
use Blueworx\Forge\Notifications\Register;
use Blueworx\Forge\Onboarding\Steps;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Health;
use Blueworx\Forge\Tenancy\Integrations;
use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Tenancy\Users;
use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\Stages;
use Blueworx\Forge\Work\Submissions;
use Blueworx\Forge\Work\Transition;
use Blueworx\Forge\Work\Transitions;

/**
 * #169. The reading half: {@see Rules} decides, this fetches.
 *
 * The split is the point of both files. Rules is pure and can be argued with in
 * a test; this knows about tables and knows nothing about what any of it means.
 * When the two are one class, "why is this on my list" can only be answered by
 * running it against a real database.
 *
 * **Reach is applied before anything else and on its own.** Standup spans
 * clients by design — that is what a daily list is — so the scoping is the only
 * thing standing between one person's board and every client's work. It filters
 * the records first, and the rules run over what survived; never one combined
 * pass where a mistake in a rule could widen what is visible rather than narrow
 * it. The same order the request queue uses, for the same reason.
 *
 * **This is not cheap, and that is known rather than overlooked.** Working out
 * whether a piece of work is stuck at a gate means asking the workflow engine,
 * and the engine reads that item's gate records — one query per item. The
 * alternative was a second, cheaper implementation of the gates inside the
 * standup rules, which is exactly the disagreement this product keeps refusing
 * to create: a board saying an item is ready while the transition route refuses
 * it. A board drawn a few times a day can afford the queries; #183 is where the
 * count gets looked at properly.
 */
final class Board {

	/**
	 * The day's list for whoever is asking.
	 *
	 * @param array<string, mixed> $reach The caller's reach.
	 * @param string               $today YYYY-MM-DD.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_reach( array $reach, string $today ): array {
		if ( Reach::is_nothing( $reach ) ) {
			return array();
		}

		return Rules::evaluate( self::state( $reach, $today ), $today );
	}

	/**
	 * Everything the rules are worked out from.
	 *
	 * @param array<string, mixed> $reach The caller's reach.
	 * @param string               $today YYYY-MM-DD.
	 * @return array<string, mixed>
	 */
	public static function state( array $reach, string $today ): array {
		$sites    = Reach::keep_sites( $reach, ClientSites::all( 'active' ), 'id' );
		$site_ids = array_column( $sites, 'id' );

		return array(
			'items'            => self::items( $sites ),
			'submissions'      => Reach::keep_sites( $reach, Submissions::all() ),
			'onboarding_steps' => self::steps( $site_ids ),
			'capacity'         => self::capacity( $today ),
			'interventions'    => self::interventions( $reach, $site_ids ),
		);
	}

	/**
	 * The open work on those sites, each carrying what is holding it up.
	 *
	 * Released work is left out here rather than filtered by the rules. It is
	 * the bulk of a mature site's records and none of it can ever match, so
	 * fetching it to discard it would be the whole cost of the board for
	 * nothing.
	 *
	 * @param array<int, array<string, mixed>> $sites Sites in reach.
	 * @return array<int, array<string, mixed>>
	 */
	private static function items( array $sites ): array {
		$items = array();

		foreach ( $sites as $site ) {
			foreach ( Items::for_site( (string) $site['id'] ) as $item ) {
				if ( Stages::RELEASED === (string) $item['stage'] ) {
					continue;
				}

				$item['unmet'] = self::unmet( $item );

				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * What is standing between one item and its next step.
	 *
	 * Only where there is exactly one way forward. Work that could go two ways
	 * is not stuck — somebody has a choice to make, which is a different thing
	 * from a requirement nobody has met, and putting it on the list as though
	 * it were the same would fill the board with decisions rather than tasks.
	 *
	 * @param array<string, mixed> $item The item.
	 * @return array<int, array<string, mixed>>
	 */
	private static function unmet( array $item ): array {
		$next = Transitions::next_from( (string) $item['stage'], (string) $item['work_type'] );

		if ( 1 !== count( $next ) ) {
			return array();
		}

		return Transition::readiness( $item, (string) $next[0] )['unmet'];
	}

	/**
	 * The onboarding steps on those sites.
	 *
	 * @param array<int, string> $site_ids Sites in reach.
	 * @return array<int, array<string, mixed>>
	 */
	private static function steps( array $site_ids ): array {
		$steps = array();

		foreach ( Steps::for_sites( $site_ids ) as $of_site ) {
			foreach ( $of_site as $step ) {
				$steps[] = $step;
			}
		}

		return $steps;
	}

	/**
	 * Who is over their hours this week.
	 *
	 * This week only. Somebody over-committed next month is a planning problem
	 * and belongs on the capacity screen; a daily list is about today.
	 *
	 * Not scoped by reach, and it is worth saying why: capacity is counted
	 * across every client by definition (#138), and a person committed on one
	 * client must not look free on another. The answer names the studio's own
	 * people and no client, so there is nothing here to leak.
	 *
	 * @param string $today YYYY-MM-DD.
	 * @return array<int, array<string, mixed>>
	 */
	private static function capacity( string $today ): array {
		$week   = Periods::weeks( $today, $today );
		$from   = (string) ( $week[0]['from'] ?? $today );
		$to     = (string) ( $week[0]['to'] ?? $today );
		$people = Users::all( 'active' );

		$positions = Position::for_people( array_column( $people, 'id' ), $from, $to );
		$over      = array();

		foreach ( $people as $person ) {
			$id       = (string) $person['id'];
			$position = $positions[ $id ] ?? array();

			if ( array() === $position ) {
				continue;
			}

			$over[] = array_merge(
				$position,
				array(
					'user_id'      => $id,
					'display_name' => (string) $person['display_name'],
					'from'         => $from,
				)
			);
		}

		return $over;
	}

	/**
	 * What the product tried to do and could not.
	 *
	 * Two kinds, and both are things nobody would otherwise find out about: an
	 * email that failed on its way to a client, and a client site that has
	 * stopped talking to us. Each is invisible by nature — the failure is that
	 * nothing happened — so a list of what needs attention is exactly where
	 * they belong.
	 *
	 * @param array<string, mixed> $reach    The caller's reach.
	 * @param array<int, string>   $site_ids Sites in reach.
	 * @return array<int, array<string, mixed>>
	 */
	private static function interventions( array $reach, array $site_ids ): array {
		$problems = array();

		foreach ( Register::failed_for_sites( $site_ids ) as $event ) {
			$problems[] = array(
				'id'           => (string) $event['id'],
				'subject_type' => 'notification',
				'about'        => (string) $event['subject_id'],
				'kind'         => (string) $event['event_kind'],

				/*
				 * What the mailer complained about (#174). Carried onto the card
				 * because "an email failed" is not something anybody can act on
				 * and "SMTP connect() failed" is, and the person reading this
				 * list is the person who has to act on it.
				 */
				'detail'       => (string) $event['last_detail'],
				'attempts'     => (int) $event['attempts'],
				'since'        => (int) $event['settled_at'],
			);
		}

		foreach ( Reach::keep_sites( $reach, Integrations::all() ) as $integration ) {
			$state = Health::state( $integration );

			if ( ! Health::needs_attention( $state ) ) {
				continue;
			}

			$problems[] = array(
				'id'           => (string) $integration['id'],
				'subject_type' => 'client_site',
				'about'        => (string) $integration['client_site_id'],
				'kind'         => $state,
				'since'        => (int) ( $integration['last_report_at'] ?? 0 ),
			);
		}

		return $problems;
	}
}
