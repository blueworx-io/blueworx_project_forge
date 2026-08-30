<?php
/**
 * The one door an answer to an onboarding step goes through.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;

/**
 * #167, #162. Recording what somebody said about a step.
 *
 * There is one door, and every screen is on the other side of it. That is the
 * whole design: ONB-3's rule about credentials, and ONB-2's rule that a client
 * may not approve their own work, are both properties of *writing an answer*
 * rather than properties of a particular screen. Put either in a controller and
 * the second controller has to remember it.
 *
 * What a client may write is a short closed list. Everything the studio decides
 * about a step — who reviews it, whether it gates a launch, when it is due,
 * where it sits — is deliberately absent from that list, so a client editing
 * their own step cannot reach round to any of it. The list is an allowlist and
 * not a set of exclusions, because a column added later is then refused by
 * default rather than quietly writable.
 */
final class Answers {

	/**
	 * Longest an answer may be. It lands in a text column and comes from
	 * somebody typing into a box, so it is bounded rather than unbounded.
	 */
	public const MAX_RESPONSE = 5000;

	/**
	 * Longest any of the short handover fields may be — they are varchars.
	 */
	public const MAX_FIELD = 191;

	/**
	 * What answering a step may change.
	 *
	 * The response, and the six things ONB-3 says Forge records instead of a
	 * credential. Nothing else.
	 *
	 * @var array<int, string>
	 */
	private const WRITABLE = array(
		'response',
		'provider',
		'account_identifier',
		'account_owner',
		'access_role',
		'invitation_status',
		'verification_outcome',
	);

	/**
	 * The two moves that are a client's to make.
	 *
	 * ONB-2: clients may never approve their own step, even a client-owned one,
	 * because self-certification makes the launch gate meaningless. Starting
	 * something and handing it over is the whole of what is theirs to say.
	 *
	 * @var array<int, string>
	 */
	private const CLIENT_MOVES = array(
		Statuses::IN_PROGRESS,
		Statuses::SUBMITTED,
	);

	/**
	 * What the person is told when they try to hand over a credential.
	 *
	 * It names the alternative, because ONB-3 has one. A refusal that stops at
	 * "no" leaves somebody stuck, and the way people get unstuck is emailing us
	 * the password instead — which is worse than what they tried to do.
	 */
	private const INSTEAD = 'Please do not put passwords, keys or card numbers here — we cannot store them. Invite our named account to the provider instead, and tell us the account you invited.';

	/**
	 * Why this answer may not be recorded.
	 *
	 * @param array<string, mixed> $fields The answer, as sent.
	 * @return array{field?: string, message?: string} Empty when it is fine.
	 */
	public static function refusal( array $fields ): array {
		$offending = Secrets::offending_field( self::writable( $fields ) );

		if ( '' === $offending ) {
			return array();
		}

		return array(
			'field'   => $offending,
			'message' => self::INSTEAD,
		);
	}

	/**
	 * The parts of what was sent that answering a step may actually change.
	 *
	 * @param array<string, mixed> $fields The answer, as sent.
	 * @return array<string, string>
	 */
	public static function writable( array $fields ): array {
		$writable = array();

		foreach ( self::WRITABLE as $name ) {
			if ( ! array_key_exists( $name, $fields ) || ! is_string( $fields[ $name ] ) ) {
				continue;
			}

			$limit             = 'response' === $name ? self::MAX_RESPONSE : self::MAX_FIELD;
			$writable[ $name ] = mb_substr( $fields[ $name ], 0, $limit );
		}

		return $writable;
	}

	/**
	 * Whether a client may put a step into this status.
	 *
	 * @param string $status Where they want it to go.
	 * @return bool
	 */
	public static function client_may_move_to( string $status ): bool {
		return in_array( $status, self::CLIENT_MOVES, true );
	}

	/**
	 * Records an answer, and the history entry that says who gave it.
	 *
	 * @param array<string, mixed> $step   The step, as read.
	 * @param array<string, mixed> $fields The answer, as sent.
	 * @param string               $moving Status to move to, or '' to stay put.
	 * @param int                  $actor  Who is answering, or 0 for a client site.
	 * @param string               $source Which interface they are on.
	 * @param string               $site   The signing client site, or '' for a person.
	 * @return array{step?: array<string, mixed>, field?: string, message?: string}
	 */
	public static function record( array $step, array $fields, string $moving, int $actor, string $source, string $site = '' ): array {
		$refusal = self::refusal( $fields );

		if ( array() !== $refusal ) {
			return $refusal;
		}

		if ( $actor <= 0 && '' === $site ) {
			// The same rule as the history itself: an answer nobody — and no
			// site — is recorded as having given proves nothing later.
			return array(
				'field'   => '',
				'message' => 'We could not tell who was answering, so nothing was saved.',
			);
		}

		$changes = self::writable( $fields );
		$from    = (string) ( $step['status'] ?? Statuses::NOT_STARTED );

		if ( '' !== $moving && $moving !== $from ) {
			$changes['status'] = $moving;
		}

		if ( array() === $changes ) {
			// An edit that changes nothing succeeded at changing nothing.
			return array( 'step' => $step );
		}

		$id      = (string) ( $step['id'] ?? '' );
		$version = (int) ( $step['record_version'] ?? 1 );

		if ( ! self::write( $id, $changes, $version ) ) {
			return array(
				'field'   => '',
				'message' => 'Somebody else changed this step while you were working on it. Reload it and try again.',
			);
		}

		StepEvents::append(
			array(
				'step_id'          => $id,
				'client_site_id'   => (string) ( $step['client_site_id'] ?? '' ),
				'action'           => isset( $changes['status'] ) ? StepEvents::MOVED : StepEvents::ANSWERED,
				'from_status'      => $from,
				'to_status'        => (string) ( $changes['status'] ?? $from ),
				'actor'            => $actor,
				'actor_site'       => $site,
				'source_interface' => $source,
			)
		);

		return array( 'step' => Steps::get( $id ) ?? $step );
	}

	/**
	 * Writes the changes, refusing a write made against a stale copy (ARCH-5).
	 *
	 * @param string                $id           Step id.
	 * @param array<string, string> $changes      Columns to set.
	 * @param int                   $sent_version Version the answer was made against.
	 * @return bool
	 */
	private static function write( string $id, array $changes, int $sent_version ): bool {
		global $wpdb;

		if ( '' === $id ) {
			return false;
		}

		$changes['updated_at']     = bwx_forge_now();
		$changes['record_version'] = $sent_version + 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$changed = $wpdb->update(
			Schema::onboarding_steps_table(),
			$changes,
			array(
				'id'             => $id,
				'record_version' => $sent_version,
			),
			Formats::for_row( $changes ),
			array( '%s', '%d' )
		);

		return (bool) $changed;
	}
}
