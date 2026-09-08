<?php
namespace Blueworx\PageEditor\v1;

/**
 * Every value is cleaned by what its field is, not by what the browser said it
 * was. A kind with no case here is treated as text, which is the safe end of
 * the range rather than a hole.
 */
final class Sanitise {

	/**
	 * Kinds that show something and take nothing back. field() answers null
	 * for one of these however it is called; values() drops it altogether, so
	 * a save never carries it as far as the store — writing the null as empty
	 * post meta would be the field saved anyway, for a value nobody sent.
	 */
	const DISPLAY_ONLY_KINDS = [ 'facts', 'table', 'copytext', 'preview', 'schedule' ];

	/**
	 * What a gantt phase's marker may be. It sets the bar's colour and its
	 * meaning: work before launch, the launch itself, work after. Closed for
	 * the same reason KINDS is — the design system draws three bars, so a
	 * fourth marker would register cleanly and render as the first.
	 */
	const GANTT_KINDS = [ 'pre', 'launch', 'post' ];

	public static function values( array $screen, array $values ): array {
		$out = [];
		foreach ( self::fields( $screen ) as $field ) {
			if ( in_array( $field['kind'] ?? 'text', self::DISPLAY_ONLY_KINDS, true ) ) {
				continue;
			}
			if ( array_key_exists( $field['id'], $values ) ) {
				$out[ $field['id'] ] = self::field( $field, $values[ $field['id'] ] );
			}
		}
		return $out;
	}

	/** @return array[] */
	public static function fields( array $screen ): array {
		$out = [];
		foreach ( $screen['tabs'] as $tab ) {
			foreach ( $tab['panels'] as $panel ) {
				foreach ( $panel['fields'] as $field ) {
					$out[] = $field;
				}
			}
		}
		return $out;
	}

	public static function field( array $field, $value ) {
		switch ( $field['kind'] ?? 'text' ) {
			case 'richtext':
				return wp_kses_post( (string) $value );

			case 'textarea':
				return implode( "\n", array_map( 'sanitize_text_field', explode( "\n", (string) $value ) ) );

			case 'number':
			case 'range':
				$n = (int) $value;
				if ( isset( $field['min'] ) ) {
					$n = max( (int) $field['min'], $n );
				}
				if ( isset( $field['max'] ) ) {
					$n = min( (int) $field['max'], $n );
				}
				return $n;

			case 'toggle':
				return (bool) $value;

			case 'colour':
				return preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $value ) ? (string) $value : '';

			case 'date':
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ? (string) $value : '';

			case 'datetime':
				return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string) $value ) ? (string) $value : '';

			case 'media':
			case 'file':
			case 'record':
				return (int) $value;

			case 'slug':
				// sanitize_title(), not sanitize_key(): this is a web address,
				// and it has to come out as the one WordPress itself would
				// have made. sanitize_key() drops spaces rather than turning
				// them into dashes, so "Under 12s Team" became
				// "under12steam" instead of "under-12s-team".
				return sanitize_title( (string) $value );

			case 'select':
			case 'radio':
				return self::oneOf( $field, (string) $value );

			case 'checkboxes':
			case 'scrolllist':
				$picked = is_array( $value ) ? $value : [];
				$out    = [];
				foreach ( $picked as $one ) {
					$kept = self::oneOf( $field, (string) $one );
					if ( '' !== $kept ) {
						$out[] = $kept;
					}
				}
				return array_values( array_unique( $out ) );

			case 'tokens':
				$given = is_array( $value ) ? $value : [];
				$out   = [];
				foreach ( $given as $one ) {
					$clean = sanitize_text_field( (string) $one );
					if ( '' !== $clean ) {
						$out[] = $clean;
					}
				}
				return array_values( array_unique( $out ) );

			case 'repeater':
				$rows = is_array( $value ) ? $value : [];
				$out  = [];
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$clean = [];
					foreach ( $field['fields'] as $cell ) {
						$clean[ $cell['id'] ] = self::field( $cell, $row[ $cell['id'] ] ?? '' );
					}
					$out[] = $clean;
				}
				return $out;

			// A gantt's rows are phases, and unlike a repeater their columns are
			// the library's own rather than the plugin's — so they are listed
			// here rather than read off the field. Anything else a browser
			// sends is dropped: a phase carries no free-form payload.
			case 'gantt':
				$rows = is_array( $value ) ? $value : [];
				$out  = [];
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					// An unrecognised marker falls back to pre-launch rather
					// than dropping the row. A phase that arrives with a
					// nonsense marker is still a real phase, and throwing it
					// away loses work somebody did.
					$kind = isset( $row['kind'] ) && in_array( $row['kind'], self::GANTT_KINDS, true )
						? $row['kind']
						: 'pre';
					$out[] = [
						'id'        => sanitize_key( $row['id'] ?? '' ),
						'title'     => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
						'desc'      => sanitize_text_field( (string) ( $row['desc'] ?? '' ) ),
						'start'     => max( 1, (int) ( $row['start'] ?? 1 ) ),
						'end'       => max( 1, (int) ( $row['end'] ?? 1 ) ),
						'milestone' => sanitize_text_field( (string) ( $row['milestone'] ?? '' ) ),
						'kind'      => $kind,
						'visible'   => ! empty( $row['visible'] ),
					];
				}
				return $out;

			// facts, table, copytext, preview and schedule are display-only on
			// the screen; nothing comes back, so nothing is accepted back.
			// values() never reaches this for one of them — see
			// DISPLAY_ONLY_KINDS — but a direct caller still gets the same
			// refusal.
			case 'facts':
			case 'table':
			case 'copytext':
			case 'preview':
			case 'schedule':
				return null;

			default:
				return sanitize_text_field( (string) $value );
		}
	}

	private static function oneOf( array $field, string $value ): string {
		foreach ( $field['options'] ?? [] as $option ) {
			if ( (string) $option['value'] === $value ) {
				return $value;
			}
		}
		return '';
	}
}
