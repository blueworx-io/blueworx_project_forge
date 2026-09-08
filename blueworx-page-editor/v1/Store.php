<?php
namespace Blueworx\PageEditor\v1;

/**
 * Two places a screen's values can live, behind one door.
 *
 * A record's values are post meta keyed by post type and field id, so two
 * screens on one site cannot collide — except for the handful of ids that are
 * WordPress's own post columns, tags and featured image (see
 * PostStore::POST_COLUMNS), which have to be read and written through the
 * post itself or they do nothing: a status stored as meta does not publish
 * anything, and a slug stored as meta does not change the address. A settings
 * screen keeps everything in a single option, because it is one thing.
 */
abstract class Store {

	/** @var array */
	protected $screen;

	protected function __construct( array $screen ) {
		$this->screen = $screen;
	}

	public static function for( array $screen ): Store {
		if ( isset( $screen['read'], $screen['write'] ) ) {
			return new CallbackStore( $screen );
		}
		return 'option' === $screen['store'] ? new OptionStore( $screen ) : new PostStore( $screen );
	}

	abstract public function read( int $id = 0 ): array;

	abstract public function write( array $values, int $id = 0 ): bool;

	/** @return array[] */
	protected function fields(): array {
		return Sanitise::fields( $this->screen );
	}

	/**
	 * Post meta and a bare option value are stored as text: a value never
	 * saved reads back as '', and any scalar that was saved reads back as a
	 * string ('1'/'' for a bool, digits for an int) rather than the type that
	 * went in — WordPress casts it going in and never remembers what it was
	 * before. An array survives the round trip untouched, because it went
	 * through serialize()/unserialize() rather than a text cast. This puts a
	 * value back into the shape its field kind implies, so PostStore and
	 * OptionStore both hand back what Sanitise would have produced, not what
	 * the storage layer's own text-only memory happens to preserve. Sits
	 * beside PostStore::fromColumn() — that is a WordPress column's own
	 * translation, this is this library's.
	 *
	 * facts, table, copytext, preview and schedule are display-only and never submitted —
	 * Sanitise produces null for them on the way in — so nothing here tries
	 * to round-trip whatever shape they happen to read back as; nothing ever
	 * compares it.
	 */
	protected function castByKind( array $field, $value ) {
		switch ( $field['kind'] ?? 'text' ) {
			case 'toggle':
				return (bool) $value;

			case 'number':
			case 'range':
			case 'media':
			case 'file':
			case 'record':
				return (int) $value;

			case 'checkboxes':
			case 'scrolllist':
			case 'tokens':
			case 'repeater':
			case 'gantt':
				return is_array( $value ) ? $value : [];

			default:
				return (string) $value;
		}
	}
}

final class PostStore extends Store {

	/**
	 * Field ids that are columns on the post itself, not post meta. This is
	 * the one place that decides what is a column and what is meta — kept as
	 * a single list rather than duplicated between reading and writing.
	 */
	const POST_COLUMNS = [
		'post_status', 'post_name', 'post_excerpt', 'post_author',
		'post_date', 'post_parent', 'menu_order', 'comment_status',
		// The record's own title and body. Not on the Publish tab — a title
		// belongs at the top of the screen a plugin designs, not buried in
		// settings — but every bit as much a column as the rest: stored as
		// meta they set nothing, and the record stays "(no title)" in
		// wp-admin for ever.
		'post_title', 'post_content',
	];

	public function read( int $id = 0 ): array {
		$out  = [];
		$post = null;

		foreach ( $this->fields() as $field ) {
			$key = $field['id'];

			if ( in_array( $key, self::POST_COLUMNS, true ) ) {
				if ( null === $post ) {
					$post = get_post( $id );
				}
				// WP_Post hands every column back exactly as the database
				// driver returned it — a string, for a numeric column like
				// post_author or menu_order, same as post meta. fromColumn()
				// only translates the couple of columns with their own
				// storage shape (comment_status, post_date); castByKind()
				// then turns whatever comes out of that into what the
				// field's kind implies, same as it does for meta.
				$raw         = ( $post && isset( $post->$key ) ) ? $post->$key : '';
				$out[ $key ] = $this->castByKind( $field, $this->fromColumn( $key, $raw ) );
				continue;
			}

			if ( 'post_tags' === $key ) {
				$out[ $key ] = wp_get_post_terms( $id, 'post_tag', [ 'fields' => 'names' ] );
				continue;
			}

			if ( 'featured_image' === $key ) {
				// get_post_thumbnail_id() returns false, not 0, when there is
				// no thumbnail — castByKind() turns that into the int the
				// media kind expects.
				$out[ $key ] = $this->castByKind( $field, get_post_thumbnail_id( $id ) );
				continue;
			}

			$meta_key    = $this->key( $key );
			// metadata_exists() is the only way to ask "was this ever
			// written" — get_post_meta() returns '' both for an unset key
			// and for a genuinely empty saved value, so it cannot tell them
			// apart. A field that was never saved gets its declared default
			// rather than a cast of an empty string that was never there.
			// The default is read with ?? and run through castByKind() too:
			// Schema::validate() already checked it matches the field's kind,
			// but a hand-built screen that skipped Schema entirely may have
			// no 'default' key at all, or one of the wrong shape.
			$out[ $key ] = metadata_exists( 'post', $id, $meta_key )
				? $this->castByKind( $field, get_post_meta( $id, $meta_key, true ) )
				: $this->castByKind( $field, $field['default'] ?? '' );
		}

		return $out;
	}

	/**
	 * Post meta, and the post itself, have no transaction: a real failure
	 * part-way through leaves whatever came before it already committed. This
	 * stops at the first genuine failure rather than carrying on and
	 * pretending the fields after it were never attempted.
	 */
	public function write( array $values, int $id = 0 ): bool {
		$columns = [];

		foreach ( $values as $key => $value ) {
			if ( in_array( $key, self::POST_COLUMNS, true ) ) {
				if ( ! $this->skipColumn( $key, $value ) ) {
					$columns[ $key ] = $this->toColumn( $key, $value );
				}
				continue;
			}

			if ( 'post_tags' === $key ) {
				$result = wp_set_post_terms( $id, (array) $value, 'post_tag' );
				// wp_set_post_terms() reports failure as either false or a
				// WP_Error — both are truthy in a naive `if ( ! $result )`,
				// so both have to be checked for explicitly.
				if ( false === $result || is_wp_error( $result ) ) {
					return false;
				}
				continue;
			}

			if ( 'featured_image' === $key ) {
				if ( (int) $value > 0 ) {
					if ( ! $this->writeThumbnail( $id, (int) $value ) ) {
						return false;
					}
				} else {
					delete_post_thumbnail( $id );
				}
				continue;
			}

			if ( ! $this->writeMeta( $id, $key, $value ) ) {
				return false;
			}
		}

		if ( $columns && ! wp_update_post( array_merge( $columns, [ 'ID' => $id ] ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * update_post_meta() returns false both on a genuine failure and, in real
	 * WordPress, whenever the new value is identical to the one already
	 * stored. Comparing first means a false return here always means a real
	 * failure — and a no-op re-save is never mistaken for one.
	 *
	 * The comparison is against the meta-cast form of $value, not $value
	 * itself: get_post_meta() only ever hands back what a text column can
	 * hold — '1'/'' for a bool, digits for an int — never the PHP type
	 * Sanitise produced. Comparing the raw PHP value against that would never
	 * match, so an unchanged bool or number field would never be recognised
	 * as unchanged — this check would never actually fire for one.
	 *
	 * It only short-circuits once metadata_exists() confirms the row is
	 * already there: get_post_meta() also answers '' for a key that has
	 * never been written at all, and that is not the same fact as "already
	 * holds this value" — treating them alike would skip the write the very
	 * first time a field is saved false, empty or zero, and a never-touched
	 * field would then look indistinguishable from one deliberately set that
	 * way.
	 */
	private function writeMeta( int $id, string $field_id, $value ): bool {
		$key = $this->key( $field_id );
		if ( metadata_exists( 'post', $id, $key ) && get_post_meta( $id, $key, true ) === $this->metaScalar( $value ) ) {
			return true;
		}
		return (bool) update_post_meta( $id, $key, $value );
	}

	/**
	 * What get_post_meta() will read this value back as, once stored — see
	 * writeMeta() above. Kept distinct from castByKind(): that one turns a
	 * stored value back into the type a field kind implies; this one
	 * predicts the lossy text-column shape a value becomes on the way in,
	 * which does not depend on the field's kind at all.
	 */
	private function metaScalar( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( null === $value ) {
			return '';
		}
		return (string) $value;
	}

	/**
	 * set_post_thumbnail() calls update_post_meta() internally, so it has the
	 * exact same no-op-returns-false quirk as writeMeta() above.
	 */
	private function writeThumbnail( int $id, int $thumbnail_id ): bool {
		if ( get_post_thumbnail_id( $id ) === $thumbnail_id ) {
			return true;
		}
		return (bool) set_post_thumbnail( $id, $thumbnail_id );
	}

	/**
	 * A couple of columns store a different shape than the field kind that
	 * edits them: comment_status is WordPress's 'open'/'closed' string, not
	 * the bool a toggle gives Sanitise; post_date is 'Y-m-d H:i:s', not the
	 * 'Y-m-d\TH:i' a datetime field sends. Kept next to POST_COLUMNS so the
	 * one place that knows a field is a column is also the place that knows
	 * how to translate it — in both directions, see fromColumn() below.
	 */
	private function toColumn( string $key, $value ) {
		if ( 'comment_status' === $key ) {
			return $value ? 'open' : 'closed';
		}
		if ( 'post_date' === $key ) {
			return str_replace( 'T', ' ', (string) $value ) . ':00';
		}
		return $value;
	}

	/**
	 * Whether a column has to be left out of the array altogether rather than
	 * written. An empty post_date is not "leave it alone" to wp_update_post():
	 * it resets the publish date to now, so a site owner clearing the field —
	 * or a screen where it was never set — would quietly change when the
	 * record claims to have been published. Omitting the key is the only way
	 * to say nothing.
	 */
	private function skipColumn( string $key, $value ): bool {
		return 'post_date' === $key && '' === (string) $value;
	}

	private function fromColumn( string $key, $value ) {
		if ( 'comment_status' === $key ) {
			return 'open' === $value;
		}
		if ( 'post_date' === $key ) {
			$raw = (string) $value;
			// An all-zero date is WordPress's "no date". Sent as it stands the
			// browser would show 0000-00-00, which is worse than blank.
			if ( strlen( $raw ) < 16 || 0 === strpos( $raw, '0000-00-00' ) ) {
				return '';
			}
			return substr( str_replace( ' ', 'T', $raw ), 0, 16 );
		}
		return $value;
	}

	private function key( string $field ): string {
		return $this->screen['post_type'] . '_' . $field;
	}
}

final class OptionStore extends Store {

	public function read( int $id = 0 ): array {
		$saved = get_option( $this->screen['option_name'], [] );
		$saved = is_array( $saved ) ? $saved : [];
		$out   = [];
		foreach ( $this->fields() as $field ) {
			// The key being absent from the saved array is this store's
			// version of "was this ever written" — an option is one array,
			// so there is no metadata_exists() to ask; array_key_exists()
			// answers the same question. The default is read with ?? and
			// cast the same way as PostStore::read() — see there for why.
			$out[ $field['id'] ] = array_key_exists( $field['id'], $saved )
				? $this->castByKind( $field, $saved[ $field['id'] ] )
				: $this->castByKind( $field, $field['default'] ?? '' );
		}
		return $out;
	}

	/**
	 * update_option() has the same no-op-returns-false quirk as
	 * update_post_meta() — see PostStore::writeMeta() — so this only calls it
	 * once the merged value genuinely differs from what is already saved.
	 */
	public function write( array $values, int $id = 0 ): bool {
		$saved  = get_option( $this->screen['option_name'], [] );
		$saved  = is_array( $saved ) ? $saved : [];
		$merged = array_merge( $saved, $values );

		if ( $merged === $saved ) {
			return true;
		}

		return update_option( $this->screen['option_name'], $merged );
	}
}

/**
 * A screen that keeps its values somewhere this library does not know about.
 *
 * The case that asked for it: a settings screen whose switches are not
 * settings at all. A page controller's switch IS a page's post status — stored
 * as an option it would be a second copy of that fact, and publishing the page
 * from anywhere else would make the two disagree with no way to tell which was
 * right.
 *
 * The plugin supplies read() and write(); everything else the library does is
 * unchanged — the schema, the capability filtering in both directions, the
 * sanitising, the validation, the save bar and its states all still apply, and
 * a value still reaches write() already cleaned by its field's kind.
 *
 * Values still go through castByKind() on the way out, so a plugin returning
 * '1' for a toggle is corrected here rather than reading back as a dirty
 * screen. A field the callback says nothing about falls back to its declared
 * default, exactly as the other two stores do.
 */
final class CallbackStore extends Store {

	public function read( int $id = 0 ): array {
		$saved = call_user_func( $this->screen['read'], $id );
		$saved = is_array( $saved ) ? $saved : [];

		$out = [];
		foreach ( $this->fields() as $field ) {
			$out[ $field['id'] ] = array_key_exists( $field['id'], $saved )
				? $this->castByKind( $field, $saved[ $field['id'] ] )
				: $this->castByKind( $field, $field['default'] ?? '' );
		}
		return $out;
	}

	/**
	 * Whatever the callback returns is taken as its own answer about whether
	 * the write succeeded. A callback that returns nothing is treated as
	 * having failed rather than as having quietly worked: this library tells
	 * a site owner plainly when a save may not have landed, and it cannot do
	 * that on a guess.
	 */
	public function write( array $values, int $id = 0 ): bool {
		return true === call_user_func( $this->screen['write'], $values, $id );
	}
}
