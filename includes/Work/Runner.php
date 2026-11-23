<?php
/**
 * What the Forge Runner reaches on a card, and what it may change.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use WP_Error;

/**
 * The rules behind Rest\RunnerController, kept apart from the database so
 * each one is tested on its own.
 *
 * The runner reaches a card only while the Agent is doing it — the Agent in
 * the Primary User seat — and the card is not archived. It sees that card's
 * own definition and comments, never another card's. It makes two moves,
 * Up Next to In development and In development to In review, through the
 * same transition service and gates as anybody; and on hand-over it writes
 * four things: the hours, how to test it, the test steps and the checklist's
 * ticks. Everything else is refused by name rather than quietly dropped.
 */
final class Runner {

	/**
	 * The runner's moves, by route: where each starts and where it goes.
	 */
	public const MOVES = array(
		'claim'     => array( 'up-next', 'in-development' ),
		'hand-over' => array( 'in-development', 'in-review' ),
	);

	/**
	 * The stages the runner's list can be asked for.
	 */
	public const STAGES = array( 'up-next', 'in-development', 'in-review' );

	/**
	 * What hand-over may be sent. Anything else is refused.
	 */
	private const HANDOVER = array( 'hours_primary', 'test_description', 'test_steps', 'tick', 'record_version' );

	/**
	 * What a comment may be sent.
	 */
	private const COMMENT = array( 'body' );

	/**
	 * What the list shows of each card.
	 */
	private const LIGHT = array( 'id', 'title', 'stage', 'level', 'work_type', 'priority', 'github_repo', 'design_url', 'planned_start', 'planned_due', 'review_target', 'release_target', 'hours_primary' );

	/**
	 * What the card in full shows: its definition, its seats, hours and dates.
	 */
	private const DEFINITION = array(
		'id',
		'title',
		'stage',
		'level',
		'work_type',
		'priority',
		'problem',
		'scope',
		'non_goals',
		'requirements',
		'acceptance_criteria',
		'references',
		'design_url',
		'test_description',
		'test_steps',
		'checklist',
		'links',
		'github_repo',
		'primary_user_id',
		'designer_id',
		'reviewer_id',
		'deliverer_id',
		'hours_primary',
		'hours_designer',
		'hours_review',
		'hours_delivery',
		'design_target',
		'planned_start',
		'planned_due',
		'review_target',
		'release_target',
		'record_version',
	);

	/**
	 * What a refusal from the workflow may still carry. Anything else — the
	 * stale card in full, the client's support balance — is dropped.
	 */
	private const REFUSAL_DATA = array( 'status', 'from', 'attempted', 'available', 'sent_version', 'current_version', 'fields' );

	/**
	 * Whether the runner reaches a card: the Agent is doing it, and it is not
	 * archived. Pure.
	 *
	 * @param array<string, mixed> $item      The card.
	 * @param array<int, string>   $agent_ids Every agent.
	 * @return bool
	 */
	public static function reaches( array $item, array $agent_ids ): bool {
		$primary = (string) ( $item['primary_user_id'] ?? '' );

		return '' !== $primary && in_array( $primary, $agent_ids, true ) && empty( $item['archived'] );
	}

	/**
	 * The stage the list is asked for: Up Next when none, null for any stage
	 * the runner does not read. Pure.
	 *
	 * @param string $asked As sent.
	 * @return string|null
	 */
	public static function stage( string $asked ): ?string {
		$asked = '' === $asked ? 'up-next' : $asked;

		return in_array( $asked, self::STAGES, true ) ? $asked : null;
	}

	/**
	 * Where the runner may move a card from a stage, or null when it may not
	 * move it at all. Pure.
	 *
	 * @param string $stage The card's stage.
	 * @return string|null
	 */
	public static function next_move( string $stage ): ?string {
		foreach ( self::MOVES as $move ) {
			if ( $move[0] === $stage ) {
				return $move[1];
			}
		}

		return null;
	}

	/**
	 * A card as the list shows it: enough to choose work by. Pure.
	 *
	 * @param array<string, mixed> $item The card.
	 * @return array<string, mixed>
	 */
	public static function light( array $item ): array {
		$light = self::pick( $item, self::LIGHT );

		$light['has_acceptance_criteria'] = '' !== Fields::plain( (string) ( $item['acceptance_criteria'] ?? '' ) );
		$light['record_version']          = (int) ( $item['record_version'] ?? 0 );

		return $light;
	}

	/**
	 * A card in full, as the runner reads it. Pure.
	 *
	 * @param array<string, mixed> $item The card.
	 * @return array<string, mixed>
	 */
	public static function definition( array $item ): array {
		return self::pick( $item, self::DEFINITION );
	}

	/**
	 * What a gate is still waiting on, in Forge's own words, and nothing that
	 * names anybody else's workload or a client's balance. Pure.
	 *
	 * @param array<string, mixed> $readiness Transition::readiness().
	 * @return array{ready: bool, unmet: array<int, array<string, string>>, checks: array<int, array<string, string>>}
	 */
	public static function readiness_view( array $readiness ): array {
		$unmet = array_map(
			static fn( array $one ): array => array(
				'id'           => (string) ( $one['id'] ?? '' ),
				'label'        => (string) ( $one['label'] ?? '' ),
				'satisfied_by' => (string) ( $one['satisfied_by'] ?? '' ),
			),
			array_values( (array) ( $readiness['unmet'] ?? array() ) )
		);

		$checks = array_map(
			static fn( array $one ): array => array(
				'id'     => (string) ( $one['id'] ?? '' ),
				'label'  => (string) ( $one['label'] ?? '' ),
				'result' => (string) ( $one['result'] ?? '' ),
			),
			array_values( (array) ( $readiness['checks'] ?? array() ) )
		);

		return array(
			'ready'  => array() === $unmet,
			'unmet'  => $unmet,
			'checks' => $checks,
		);
	}

	/**
	 * A refusal, keeping its words and dropping anything that could carry
	 * another record. Pure.
	 *
	 * @param WP_Error $error The refusal.
	 * @return WP_Error
	 */
	public static function scrub( WP_Error $error ): WP_Error {
		$data = (array) $error->get_error_data();

		return new WP_Error( $error->get_error_code(), $error->get_error_message(), array_intersect_key( $data, array_flip( self::REFUSAL_DATA ) ) );
	}

	/**
	 * What hand-over will write, checked as Forge checks any edit, or why it
	 * cannot. Pure apart from Validate's own escaping.
	 *
	 * @param array<string, mixed> $body What was sent.
	 * @param array<string, mixed> $item The card as it stands.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function handover( array $body, array $item ): array {
		$errors = self::refuse_others( $body, self::HANDOVER );
		$input  = array();

		if ( ! array_key_exists( 'hours_primary', $body ) || ! is_numeric( $body['hours_primary'] ) ) {
			$errors['hours_primary'] = __( 'Say how many hours the work took.', 'blueworx-forge' );
		} else {
			$input['hours_primary'] = $body['hours_primary'];
		}

		if ( '' === Fields::plain( (string) ( $body['test_description'] ?? '' ) ) ) {
			$errors['test_description'] = __( 'Say how to test it, so the reviewer has something to go on.', 'blueworx-forge' );
		} else {
			$input['test_description'] = (string) $body['test_description'];
		}

		if ( array_key_exists( 'test_steps', $body ) ) {
			$input['test_steps'] = is_array( $body['test_steps'] ) ? array_map( array( self::class, 'line' ), array_values( $body['test_steps'] ) ) : $body['test_steps'];
		}

		if ( array_key_exists( 'tick', $body ) ) {
			$ticked = self::ticked( $body['tick'], (array) ( $item['checklist'] ?? array() ) );

			if ( null === $ticked ) {
				$errors['tick'] = __( 'A tick names a line of the checklist by its number, counting from 0.', 'blueworx-forge' );
			} else {
				$input['checklist'] = $ticked;
			}
		}

		$checked = Validate::item( $input, true );
		$errors  = array_merge( $checked['errors'], $errors );

		return array(
			'values' => array() === $errors ? self::ordered( $checked['values'] ) : array(),
			'errors' => $errors,
		);
	}

	/**
	 * The card as it would be once hand-over is written, so the gate can be
	 * asked before anything is. Pure.
	 *
	 * @param array<string, mixed> $item   The card.
	 * @param array<string, mixed> $values handover()'s values.
	 * @return array<string, mixed>
	 */
	public static function after( array $item, array $values ): array {
		foreach ( $values as $field => $value ) {
			if ( in_array( $field, Fields::LISTS, true ) ) {
				$value = json_decode( (string) $value, true );
				$value = is_array( $value ) ? $value : array();
			}

			$item[ $field ] = 'hours_primary' === $field ? (float) $value : $value;
		}

		return $item;
	}

	/**
	 * What a comment will say, or why it cannot be written. Pure.
	 *
	 * @param array<string, mixed> $body What was sent.
	 * @return array{body: string, errors: array<string, string>}
	 */
	public static function comment( array $body ): array {
		$errors = self::refuse_others( $body, self::COMMENT );
		$text   = trim( is_string( $body['body'] ?? null ) ? $body['body'] : '' );

		if ( '' === $text ) {
			$errors['body'] = __( 'A comment needs something in it.', 'blueworx-forge' );
		} elseif ( mb_strlen( $text ) > Comments::MAX_BODY ) {
			$errors['body'] = __( 'That comment is too long.', 'blueworx-forge' );
		}

		return array(
			'body'   => $text,
			'errors' => $errors,
		);
	}

	/**
	 * A refusal for every field sent that the runner may not send.
	 *
	 * @param array<string, mixed> $body    What was sent.
	 * @param array<int, string>   $allowed What may be.
	 * @return array<string, string>
	 */
	private static function refuse_others( array $body, array $allowed ): array {
		$errors = array();

		foreach ( array_diff( array_keys( $body ), $allowed ) as $field ) {
			$errors[ (string) $field ] = __( 'The runner can\'t change that.', 'blueworx-forge' );
		}

		return $errors;
	}

	/**
	 * The checklist with the named lines ticked, or null when a tick names no
	 * line.
	 *
	 * @param mixed                            $ticks     The line numbers sent.
	 * @param array<int, array<string, mixed>> $checklist The checklist as it stands.
	 * @return array<int, array{text: string, done: bool}>|null
	 */
	private static function ticked( $ticks, array $checklist ): ?array {
		if ( ! is_array( $ticks ) ) {
			return null;
		}

		$lines = array_map(
			static fn( array $row ): array => array(
				'text' => (string) ( $row['text'] ?? '' ),
				'done' => ! empty( $row['done'] ),
			),
			array_values( $checklist )
		);

		foreach ( $ticks as $tick ) {
			if ( ! is_int( $tick ) || ! isset( $lines[ $tick ] ) ) {
				return null;
			}

			$lines[ $tick ]['done'] = true;
		}

		return $lines;
	}

	/**
	 * A test step as sent, as a line: its words, or a line already shaped.
	 *
	 * @param mixed $step One step.
	 * @return array<string, mixed>
	 */
	private static function line( $step ): array {
		return is_array( $step ) ? $step : array(
			'text' => (string) $step,
			'done' => false,
		);
	}

	/**
	 * The values in one order, whatever order they were sent in.
	 *
	 * @param array<string, mixed> $values Checked values.
	 * @return array<string, mixed>
	 */
	private static function ordered( array $values ): array {
		return array_intersect_key( array_merge( array_flip( array( 'hours_primary', 'test_description', 'test_steps', 'checklist' ) ), $values ), $values );
	}

	/**
	 * The named fields of a card, in that order.
	 *
	 * @param array<string, mixed> $item   The card.
	 * @param array<int, string>   $fields Which.
	 * @return array<string, mixed>
	 */
	private static function pick( array $item, array $fields ): array {
		$picked = array();

		foreach ( $fields as $field ) {
			$picked[ $field ] = $item[ $field ] ?? '';
		}

		return $picked;
	}
}
