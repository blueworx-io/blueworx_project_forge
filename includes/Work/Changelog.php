<?php
/**
 * What a change to a work item says about itself.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * #99. Every material change recorded, in the shape the data model fixes:
 * actor, action, previous and new values, time and timezone, source interface,
 * reason.
 *
 * This is the part that decides what an entry says; Work\Events is the part that
 * writes it, and has no way to change one afterwards. The split is deliberate —
 * "what counts as a change" is a rule with edge cases worth reading, and it does
 * not need a database to be one.
 *
 * **One entry per field, never one per edit.** "Somebody changed four things"
 * is not an answer to "when did the due date move", and the second question is
 * the one anybody actually asks months later.
 */
final class Changelog {

	/**
	 * How much of a value is kept.
	 *
	 * The log records what changed, not a second copy of every draft of a
	 * requirements document. A value longer than this is cut, because an entry
	 * nobody can skim is an entry nobody reads.
	 */
	public const MAX_VALUE = 191;

	/**
	 * The entries an edit produces.
	 *
	 * @param array<string, mixed> $before  The item as it stands.
	 * @param array<string, mixed> $changes The values the edit would write.
	 * @param array<string, mixed> $context source_interface, reason, actor.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_edit( array $before, array $changes, array $context ): array {
		$entries = array();

		foreach ( $changes as $field => $value ) {
			$was = self::render( $before[ $field ] ?? '' );
			$now = self::render( $value );

			// An edit that changes nothing records nothing. A log full of
			// "changed the title from X to X" is one nobody reads.
			if ( $was === $now ) {
				continue;
			}

			$entries[] = self::entry( $before, (string) $field, $was, $now, $context );
		}

		return $entries;
	}

	/**
	 * One entry, filled in.
	 *
	 * @param array<string, mixed> $item    The item it happened to.
	 * @param string               $field   Which field changed.
	 * @param string               $was     What it was.
	 * @param string               $now     What it became.
	 * @param array<string, mixed> $context source_interface, reason, actor.
	 * @return array<string, mixed>
	 */
	private static function entry( array $item, string $field, string $was, string $now, array $context ): array {
		return array(
			'item_id'          => (string) ( $item['id'] ?? '' ),

			// Carried so the tenant boundary applies to history as well as to
			// records: reading somebody's changelog is reading their work.
			'client_site_id'   => (string) ( $item['client_site_id'] ?? '' ),
			'action'           => Events::EDITED,
			'field'            => $field,
			'previous_value'   => $was,
			'new_value'        => $now,

			/*
			 * Which interface it came from. The same edit made by us and made by
			 * the client are different facts, and nothing else in the row can
			 * say which it was — the actor is a person, and a person can be on
			 * either side of it.
			 */
			'source_interface' => (string) ( $context['source_interface'] ?? '' ),
			'reason'           => (string) ( $context['reason'] ?? '' ),
			'actor'            => (int) ( $context['actor'] ?? 0 ),
			'cycle'            => max( 1, (int) ( $item['cycle'] ?? 1 ) ),
			'attempt'          => max( 1, (int) ( $item['review_attempt'] ?? 1 ) ),
		);
	}

	/**
	 * A value as the log holds it.
	 *
	 * Everything becomes a string, because the log is read rather than
	 * calculated with — and because "0" and "" and false have to be told apart
	 * on the page, which they are not if each is stored in its own type and
	 * rendered by whatever happens to be displaying it.
	 *
	 * @param mixed $value The value.
	 * @return string
	 */
	public static function render( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		if ( is_array( $value ) ) {
			$value = implode( ', ', array_map( array( self::class, 'render' ), $value ) );
		}

		return mb_substr( (string) $value, 0, self::MAX_VALUE );
	}
}
