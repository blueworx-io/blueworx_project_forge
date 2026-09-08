<?php
namespace Blueworx\PageEditor\v1;

/**
 * What this user may see and write. The two directions genuinely differ:
 *
 * - outbound (the schema, and a locked field's value on load): a locked
 *   field stays visible, read-only, with locked_help as its help — a
 *   read-only field with nothing in it is useless.
 * - inbound (a value on the way back in on save): a locked field's value is
 *   dropped, even though the field itself was shown — it must never be
 *   writable.
 *
 * Reads capability and locked_help with ?? '' rather than direct array access:
 * a hand-built screen (never passed through Schema::validate()) may not have
 * either key set.
 *
 * mayWrite() and isShown() below are the only two places that decide either
 * question. Every public method here calls one of them rather than repeating
 * the test: three copies of the rule would drift, and the way it drifts is a
 * locked field quietly becoming writable.
 */
final class Capabilities {

	public static function filterSchema( array $screen ): array {
		return self::reduce( $screen, 'isShown', true );
	}

	/**
	 * The screen reduced to the fields this user may actually change. This is
	 * what a save is sanitised and validated against, never the screen as
	 * declared: filterValues() has already dropped every value the user may
	 * not write, so a field validated from the bare screen always reads empty
	 * — and a required one gated behind a capability the user lacks would then
	 * fail every save for ever, naming a control the screen renders read-only.
	 */
	public static function writableSchema( array $screen ): array {
		return self::reduce( $screen, 'mayWrite', false );
	}

	/**
	 * The screen with every field the given rule drops removed. One walk,
	 * shared by both filters above, so the two can never come to disagree
	 * about the shape of a screen.
	 *
	 * @param string $rule mayWrite or isShown.
	 * @param bool   $lock whether a field the user may not write is kept and
	 *                     marked read-only. Only the outbound schema does
	 *                     that; the writable one has no such field left in it.
	 */
	private static function reduce( array $screen, string $rule, bool $lock ): array {
		foreach ( $screen['tabs'] as $t => $tab ) {
			foreach ( $tab['panels'] as $p => $panel ) {
				$kept = [];
				foreach ( $panel['fields'] as $field ) {
					if ( ! self::$rule( $field ) ) {
						continue;
					}
					// Where knowing the field exists matters, it is sent locked
					// with a line naming who can change it — never editable.
					if ( $lock && ! self::mayWrite( $field ) ) {
						$field['readonly'] = true;
						$field['help']     = $field['locked_help'] ?? '';
					}
					$kept[] = $field;
				}
				$screen['tabs'][ $t ]['panels'][ $p ]['fields'] = array_values( $kept );
			}
		}
		return $screen;
	}

	/** @return string[] */
	public static function allowed( array $screen ): array {
		return self::ids( $screen, 'mayWrite' );
	}

	public static function filterValues( array $screen, array $values ): array {
		$allowed = array_flip( self::allowed( $screen ) );
		return array_intersect_key( $values, $allowed );
	}

	/**
	 * Every field id kept by filterSchema() — writable, or locked but shown.
	 * This is the outbound list: a locked field's value belongs on the
	 * screen even though the field itself is not writable.
	 *
	 * @return string[]
	 */
	public static function visible( array $screen ): array {
		return self::ids( $screen, 'isShown' );
	}

	/**
	 * The outbound counterpart to filterValues(): a locked field's value is
	 * included, because the field is shown read-only rather than hidden.
	 * save() still uses filterValues(), never this, so a locked field's
	 * value can never be written even though it can be seen.
	 */
	public static function filterValuesForDisplay( array $screen, array $values ): array {
		$visible = array_flip( self::visible( $screen ) );
		return array_intersect_key( $values, $visible );
	}

	/**
	 * Every field id on the screen that the given rule keeps, in screen order.
	 *
	 * @param string $rule mayWrite or isShown.
	 * @return string[]
	 */
	private static function ids( array $screen, string $rule ): array {
		$ids = [];
		foreach ( $screen['tabs'] as $tab ) {
			foreach ( $tab['panels'] as $panel ) {
				foreach ( $panel['fields'] as $field ) {
					if ( self::$rule( $field ) ) {
						$ids[] = $field['id'];
					}
				}
			}
		}
		return $ids;
	}

	/** May this user change the field? The inbound question, and the only one that governs writing. */
	private static function mayWrite( array $field ): bool {
		$capability = $field['capability'] ?? '';
		return '' === $capability || current_user_can( $capability );
	}

	/**
	 * Should the field appear on the screen? Writable, or locked with a line
	 * naming who can change it. Always a superset of mayWrite(): a field this
	 * returns false for never reaches the browser at all.
	 */
	private static function isShown( array $field ): bool {
		return self::mayWrite( $field ) || '' !== ( $field['locked_help'] ?? '' );
	}
}
