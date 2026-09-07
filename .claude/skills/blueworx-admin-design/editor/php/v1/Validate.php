<?php
namespace Blueworx\PageEditor\v1;

use Blueworx\PageEditor\v1\Sanitise;

/**
 * Errors are keyed by field id so the screen can put each message under the
 * field it belongs to. Every message names the fix — "Invalid input" tells a
 * site owner nothing they can act on.
 */
final class Validate {

	/**
	 * @param array      $screen       the fields to check — on a save, the ones this
	 *                                 user may actually write.
	 * @param array      $values       the cleaned values for those fields.
	 * @param array|null $whole_screen every field on the screen, writable or not.
	 *                                 A depends_on may name a field the user is not
	 *                                 allowed to edit; resolving it against the
	 *                                 writable schema alone would leave it
	 *                                 unresolvable, and the fail-safe below would
	 *                                 then validate a required field the browser is
	 *                                 hiding and block the save for ever. Defaults
	 *                                 to $screen.
	 * @param array|null $whole_values the values behind $whole_screen, used only to
	 *                                 decide whether a condition holds — never
	 *                                 validated and never written. Defaults to
	 *                                 $values.
	 * @return array<string,string>
	 */
	public static function run( array $screen, array $values, ?array $whole_screen = null, ?array $whole_values = null ): array {
		$errors       = [];
		$fields       = Sanitise::fields( $screen );
		$whole_values = null === $whole_values ? $values : $whole_values;

		$field_ids = [];
		foreach ( Sanitise::fields( null === $whole_screen ? $screen : $whole_screen ) as $field ) {
			$field_ids[ $field['id'] ] = true;
		}

		foreach ( $fields as $field ) {
			$value = $values[ $field['id'] ] ?? '';

			if ( ! self::applies( $field, $whole_values, $field_ids ) ) {
				continue;
			}

			if ( $field['required'] && self::isEmpty( $value ) ) {
				$errors[ $field['id'] ] = sprintf( '%s needs a value before this can be saved.', $field['label'] );
				continue;
			}
			if ( self::isEmpty( $value ) ) {
				continue;
			}

			if ( 'email' === ( $field['format'] ?? '' ) && '' === sanitize_email( (string) $value ) ) {
				$errors[ $field['id'] ] = 'That is not a valid address. It needs a domain, like dan@coastalbloom.co.';
				continue;
			}
			if ( 'url' === ( $field['format'] ?? '' ) && '' === esc_url_raw( (string) $value ) ) {
				$errors[ $field['id'] ] = 'That is not a valid address. It needs to start with https://.';
				continue;
			}
			if ( isset( $field['max_length'] ) && strlen( (string) $value ) > (int) $field['max_length'] ) {
				$errors[ $field['id'] ] = sprintf( 'Keep this to %d characters or fewer.', (int) $field['max_length'] );
				continue;
			}
			if ( 'gantt' === $field['kind'] ) {
				$problem = self::timelineProblem( is_array( $value ) ? $value : [] );
				if ( null !== $problem ) {
					$errors[ $field['id'] ] = $problem;
				}
				continue;
			}
		}

		if ( isset( $screen['validate'] ) && is_callable( $screen['validate'] ) ) {
			$extra = call_user_func( $screen['validate'], $values );
			if ( is_array( $extra ) ) {
				$errors = array_merge( $errors, $extra );
			}
		}

		return $errors;
	}

	/**
	 * A field that only exists while its condition holds is not validated when
	 * the condition is false — it is not on the screen, so it cannot be wrong.
	 */
	private static function applies( array $field, array $values, array $field_ids ): bool {
		$on = $field['depends_on'] ?? null;
		if ( empty( $on ) || ! is_array( $on ) || ! array_key_exists( 'field', $on ) ) {
			return true;
		}

		if ( ! isset( $field_ids[ $on['field'] ] ) ) {
			// An unresolvable dependency means we do not know whether the field
			// is on the screen. Schema::validate() rejects this at registration,
			// but a screen built by hand never passes through it, so this cannot
			// assume the reference resolves. Validating a field that turns out
			// to be hidden is a visible, fixable error; skipping one that turns
			// out to be visible lets bad data through silently, so we fail safe
			// and validate it.
			//
			// This is meant to fire on a typo, never on a field the user simply
			// may not edit — hence run()'s $whole_screen, which resolves every
			// dependency against the whole screen rather than against the part
			// of it this user may write.
			return true;
		}

		return ( $values[ $on['field'] ] ?? null ) == $on['value']; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- a checkbox sends "1" for the boolean true a schema declares.
	}

	/**
	 * The first thing wrong with a timeline, as a sentence naming the fix, or
	 * null when it is workable. One message, because errors are keyed by field
	 * and a gantt is one field however many phases it holds — so the first
	 * problem is the one to fix, and the next save reports the next.
	 *
	 * Weeks themselves are not checked against each other: phases are allowed
	 * to overlap, sit apart, or run in any order. Only a phase that ends before
	 * it starts is impossible.
	 */
	private static function timelineProblem( array $phases ): ?string {
		$launches = 0;

		foreach ( $phases as $phase ) {
			$title = trim( (string) ( $phase['title'] ?? '' ) );
			$start = (int) ( $phase['start'] ?? 1 );
			$end   = (int) ( $phase['end'] ?? 1 );

			if ( $end < $start ) {
				return sprintf(
					'"%s" ends before it starts. Set its end week to week %d or later.',
					'' === $title ? 'This phase' : $title,
					$start
				);
			}
			if ( 'launch' === ( $phase['kind'] ?? '' ) ) {
				$launches++;
			}
		}

		if ( $launches > 1 ) {
			return sprintf(
				'A timeline has one launch milestone, and this one has %d. It is what separates project work from work after launch, so mark one phase as the launch and change the others.',
				$launches
			);
		}

		return null;
	}

	private static function isEmpty( $value ): bool {
		if ( is_array( $value ) ) {
			return 0 === count( $value );
		}
		return '' === trim( (string) $value );
	}
}
