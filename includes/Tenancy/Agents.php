<?php
/**
 * The agent: a member of staff that does the work and holds no login.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

use Blueworx\Forge\Capacity\Patterns;
use Blueworx\Forge\Data\Schema;

/**
 * #689 (2026-11-20). When Luke's AI agent does the build, it sits in the seat
 * itself, so the hours land on its own row in capacity rather than on the
 * person who only designs or reviews.
 *
 * It is an ordinary person marked `is_agent`, so the People screen, the seat
 * pickers and capacity show it with no special case, and its hours are edited
 * like anyone's. What it cannot do is act: it has no login and no inbox. So
 * whenever it is the agent's turn on a task, it is the deliverer's turn — the
 * reviewer is usually the client and cannot look after it. That is why a task
 * with the agent on it needs a person to deliver it.
 */
final class Agents {

	/**
	 * The agent's address. `.invalid` is reserved and can never receive mail.
	 */
	public const EMAIL = 'agent@blueworx-forge.invalid';

	/**
	 * What the agent is called.
	 */
	public const NAME = 'Agent';

	/**
	 * Its working day, Monday to Friday.
	 */
	public const HOURS_PER_DAY = 12.0;

	/**
	 * The ids of every agent, read once per request.
	 *
	 * @var array<int, string>|null
	 */
	private static ?array $ids = null;

	/**
	 * The agent's starting hours, by day column. Pure.
	 *
	 * @return array<string, float>
	 */
	public static function default_hours(): array {
		$hours = array();

		foreach ( Patterns::day_columns() as $column ) {
			$hours[ $column ] = in_array( $column, array( 'hours_sat', 'hours_sun' ), true ) ? 0.0 : self::HOURS_PER_DAY;
		}

		return $hours;
	}

	/**
	 * Creates the agent, with its hours, when the site has never had one.
	 *
	 * An agent somebody has since deactivated still counts: that was a
	 * decision, and this must not quietly undo it.
	 */
	public static function ensure(): void {
		global $wpdb;

		$table = Schema::users_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; the name cannot be a placeholder.
		$found = $wpdb->get_var( "SELECT id FROM {$table} WHERE is_agent = 1 LIMIT 1" );

		if ( null !== $found ) {
			return;
		}

		$agent = Users::create(
			array(
				'email'        => self::EMAIL,
				'display_name' => self::NAME,
				'is_agent'     => true,
			),
			0
		);

		self::$ids = null;

		if ( null === $agent ) {
			return;
		}

		Patterns::record( (string) $agent['id'], wp_date( 'Y-m-d' ), self::default_hours(), 0, __( 'The agent\'s starting hours.', 'blueworx-forge' ) );
	}

	/**
	 * The ids of every agent, in any status.
	 *
	 * @return array<int, string>
	 */
	public static function ids(): array {
		if ( null === self::$ids ) {
			self::$ids = array_values(
				array_map(
					static fn( array $person ): string => (string) $person['id'],
					array_filter( Users::all( null ), static fn( array $person ): bool => ! empty( $person['is_agent'] ) )
				)
			);
		}

		return self::$ids;
	}

	/**
	 * Why a task, as it would be after a write, cannot be saved with the
	 * agent where it is. Pure.
	 *
	 * @param array<string, mixed> $item        The task as it would be.
	 * @param array<int, string>   $agent_ids   Every agent.
	 * @param bool                 $self_review Whether the person saving holds
	 *                                          Principal (AUTH-3) and may review
	 *                                          their own work.
	 * @return array<string, string> Field to message; empty when it can be saved.
	 */
	public static function seat_refusals( array $item, array $agent_ids, bool $self_review = false ): array {
		$deliverer = (string) ( $item['deliverer_id'] ?? '' );

		if ( in_array( $deliverer, $agent_ids, true ) ) {
			return array( 'deliverer_id' => __( 'The agent can\'t deliver work. Pick a person to deliver it.', 'blueworx-forge' ) );
		}

		/*
		 * AUTH-3 through the agent. The deliverer approves for an agent
		 * reviewer, so a deliverer who also did the work would be approving
		 * their own — the case the self-review rule exists to stop.
		 */
		$reviews_own = '' !== $deliverer
			&& in_array( (string) ( $item['reviewer_id'] ?? '' ), $agent_ids, true )
			&& (string) ( $item['primary_user_id'] ?? '' ) === $deliverer;

		if ( $reviews_own && ! $self_review ) {
			return array( 'reviewer_id' => __( 'With the agent reviewing, the person delivering it would approve their own work. Pick somebody else to deliver it, or to review it.', 'blueworx-forge' ) );
		}

		if ( '' !== $deliverer ) {
			return array();
		}

		foreach ( array( 'primary_user_id', 'designer_id', 'reviewer_id' ) as $seat ) {
			if ( in_array( (string) ( $item[ $seat ] ?? '' ), $agent_ids, true ) ) {
				return array( 'deliverer_id' => __( 'With the agent on this task, pick a person to deliver it. They look after it while the agent has it.', 'blueworx-forge' ) );
			}
		}

		return array();
	}

	/**
	 * Refuses the agent in fields where nobody can act for it: a recurring
	 * chore's people and a payment check (they tick it themselves, and have
	 * no deliverer behind them), and the two people named when a request
	 * becomes a task (named before there is a deliverer). Pure.
	 *
	 * @param array<string, mixed> $values    Values; a field may hold an id or a list of them.
	 * @param array<int, string>   $fields    The fields to look in.
	 * @param array<int, string>   $agent_ids Every agent.
	 * @param string               $why       What to say.
	 * @return array<string, string> Field to message.
	 */
	public static function refuse_in( array $values, array $fields, array $agent_ids, string $why ): array {
		$errors = array();

		foreach ( $fields as $field ) {
			$held = array_map( 'strval', (array) ( $values[ $field ] ?? array() ) );

			if ( array() !== array_intersect( $held, $agent_ids ) ) {
				$errors[ $field ] = $why;
			}
		}

		return $errors;
	}

	/**
	 * Who acts for a seat: the deliverer when the agent holds it, otherwise
	 * whoever does. Pure.
	 *
	 * @param array<string, mixed> $item      The task.
	 * @param string               $field     The seat's field.
	 * @param array<int, string>   $agent_ids Every agent.
	 * @return string
	 */
	public static function stand_in( array $item, string $field, array $agent_ids ): string {
		$holder = (string) ( $item[ $field ] ?? '' );

		return '' !== $holder && in_array( $holder, $agent_ids, true ) ? (string) ( $item['deliverer_id'] ?? '' ) : $holder;
	}

	/**
	 * Whether this person acts for the agent in a seat: the agent holds it
	 * and they are the deliverer. Pure.
	 *
	 * @param array<string, mixed> $item      The task.
	 * @param string               $field     The seat's field.
	 * @param string               $user_id   Who is asking.
	 * @param array<int, string>   $agent_ids Every agent.
	 * @return bool
	 */
	public static function acts_for( array $item, string $field, string $user_id, array $agent_ids ): bool {
		$holder = (string) ( $item[ $field ] ?? '' );

		return '' !== $user_id && '' !== $holder && $holder !== $user_id && self::stand_in( $item, $field, $agent_ids ) === $user_id;
	}

	/**
	 * The people, leaving out any agent. Pure.
	 *
	 * @param array<int, array<string, mixed>> $people People.
	 * @return array<int, array<string, mixed>>
	 */
	public static function without( array $people ): array {
		return array_values( array_filter( $people, static fn( array $person ): bool => empty( $person['is_agent'] ) ) );
	}

	/**
	 * Whether this id is an agent.
	 *
	 * @param string $id A seat value.
	 * @return bool
	 */
	public static function is( string $id ): bool {
		return '' !== $id && in_array( $id, self::ids(), true );
	}
}
