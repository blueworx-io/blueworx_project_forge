<?php
namespace Blueworx\PageEditor\v1;

/**
 * WordPress's own post settings, in their own tab, last, identical on every
 * screen we build. A plugin does not write these and cannot reorder them —
 * that sameness is the point.
 *
 * The tab is run through Schema::normaliseTab() before it is handed back, so
 * every field here gets the same defaults — wide, required, help, depends_on,
 * locked_help, capability — as a field a plugin declared. The browser reads
 * those keys on every field; without this, this tab's fields would be a
 * second, thinner shape it would have to special-case.
 */
final class Settings {

	/**
	 * What a screen is allowed to say about this tab, and what it gets when it
	 * says nothing. Every default here is the whole tab as it has always been,
	 * so a screen that declares no 'publishing' key is untouched.
	 *
	 * A screen may leave a part out; it may never add to this tab, reorder it
	 * or reword it. That is still the point — a record editor that keeps the
	 * excerpt looks exactly like every other one that keeps it.
	 */
	private const DEFAULTS = [
		'slug'       => 'edit',
		'excerpt'    => true,
		'comments'   => true,
		'taxonomies' => true,
		'parent'     => true,
	];

	/** How a screen may present the slug. */
	private const SLUG_MODES = [ 'edit', 'readonly', 'hidden' ];

	/** Everything a screen's 'publishing' key may name. */
	public static function preferences(): array {
		return array_keys( self::DEFAULTS );
	}

	/** Everything 'slug' may be set to. */
	public static function slugModes(): array {
		return self::SLUG_MODES;
	}

	public static function tab( array $screen ): ?array {
		if ( 'post' !== $screen['store'] ) {
			return null;
		}

		$wants = array_merge( self::DEFAULTS, $screen['publishing'] ?? [] );

		$tab = [
			'id'     => 'publish',
			'label'  => 'Publish & settings',
			'panels' => [
				[
					'id'       => 'status',
					'eyebrow'  => 'Publishing',
					'title'    => 'Status, slug and excerpt',
					'note'     => 'Where this sits on the site, and who can reach it.',
					'hideable' => false,
					'fields'   => [
						// wp_update_post() does no capability checking of its
						// own, so this declaration is the only thing between
						// a user who holds the screen's capability and a
						// record they have just put live. Same shape as
						// post_author below: shown, read-only, saying who
						// can change it.
						[
							'id'          => 'post_status',
							'kind'        => 'select',
							'label'       => 'Status',
							'help'        => 'A draft is only visible to you.',
							'capability'  => self::publishCapability( $screen ),
							'locked_help' => 'Only someone who can publish can change the status.',
							'options'     => [
								[ 'value' => 'draft', 'label' => 'Draft' ],
								[ 'value' => 'publish', 'label' => 'Published' ],
								[ 'value' => 'private', 'label' => 'Private' ],
							],
						],
						[ 'id' => 'post_date', 'kind' => 'datetime', 'label' => 'Published' ],
						[ 'id' => 'post_author', 'kind' => 'record', 'label' => 'Author', 'capability' => 'edit_others_posts', 'locked_help' => 'Only an editor can change the author.' ],
						[ 'id' => 'post_name', 'kind' => 'slug', 'label' => 'Slug', 'help' => 'Changing this breaks links already shared; the old address is not redirected.' ],
						[ 'id' => 'post_excerpt', 'kind' => 'textarea', 'label' => 'Excerpt', 'help' => 'Used where the site shows a summary rather than the whole page.' ],
						[ 'id' => 'comment_status', 'kind' => 'toggle', 'label' => 'Allow comments' ],
					],
				],
				[
					'id'       => 'taxonomies',
					'eyebrow'  => 'Publishing',
					'title'    => 'Categories and tags',
					'note'     => 'How this record is grouped and found.',
					'hideable' => false,
					'fields'   => [
						[ 'id' => 'post_tags', 'kind' => 'tokens', 'label' => 'Tags', 'help' => 'Press Enter after each one.' ],
						[ 'id' => 'featured_image', 'kind' => 'media', 'label' => 'Featured image' ],
					],
				],
				[
					'id'       => 'parent',
					'eyebrow'  => 'Publishing',
					'title'    => 'Parent and template',
					'note'     => 'Where this sits in the site structure.',
					'hideable' => false,
					'fields'   => [
						[ 'id' => 'post_parent', 'kind' => 'record', 'label' => 'Parent', 'help' => 'Leave this empty to keep the record at the top level.' ],
						[ 'id' => 'menu_order', 'kind' => 'number', 'label' => 'Order', 'help' => 'Lower numbers come first.', 'min' => 0 ],
					],
				],
			],
		];

		return Schema::normaliseTab( self::prune( $tab, $wants ), $screen['slug'] );
	}

	/**
	 * Take out the parts this screen said it did not want.
	 *
	 * The tab is written out in full above and cut down here rather than
	 * assembled piece by piece, so the declaration stays one readable list of
	 * what the tab is — and so leaving something out can never quietly change
	 * the order of what is left.
	 *
	 * Nothing here touches Schema::reservedFieldIds(), which asks for the tab
	 * with no screen preferences at all: a field a screen stops drawing is
	 * still a field this library owns, and a plugin may not take its id.
	 */
	private static function prune( array $tab, array $wants ): array {
		$drop_fields = [];
		if ( 'hidden' === $wants['slug'] ) {
			$drop_fields[] = 'post_name';
		}
		if ( ! $wants['excerpt'] ) {
			$drop_fields[] = 'post_excerpt';
		}
		if ( ! $wants['comments'] ) {
			$drop_fields[] = 'comment_status';
		}

		$drop_panels = [];
		foreach ( [ 'taxonomies', 'parent' ] as $panel ) {
			if ( ! $wants[ $panel ] ) {
				$drop_panels[] = $panel;
			}
		}

		$panels = [];
		foreach ( $tab['panels'] as $panel ) {
			if ( in_array( $panel['id'], $drop_panels, true ) ) {
				continue;
			}
			$panel['fields'] = array_values(
				array_filter(
					$panel['fields'],
					static function ( array $field ) use ( $drop_fields ): bool {
						return ! in_array( $field['id'], $drop_fields, true );
					}
				)
			);
			foreach ( $panel['fields'] as $i => $field ) {
				if ( 'post_name' === $field['id'] && 'readonly' === $wants['slug'] ) {
					// Read-only rather than absent: the address is the one
					// thing a record editor is most often opened to copy, and
					// a field nobody may change still has to be readable.
					$panel['fields'][ $i ]['readonly'] = true;
					$panel['fields'][ $i ]['help']     = 'This is the address the record is served at. It cannot be changed here.';
				}
			}
			$panels[] = $panel;
		}

		$tab['panels'] = $panels;

		// The first panel is named after what it holds, so it stops promising
		// an excerpt it no longer shows.
		$tab['panels'][0]['title'] = self::statusTitle( $tab['panels'][0]['fields'] );

		return $tab;
	}

	/**
	 * What to call the first panel, given what survived.
	 */
	private static function statusTitle( array $fields ): string {
		$ids   = array_column( $fields, 'id' );
		$parts = [ 'Status' ];
		if ( in_array( 'post_name', $ids, true ) ) {
			$parts[] = 'slug';
		}
		if ( in_array( 'post_excerpt', $ids, true ) ) {
			$parts[] = 'excerpt';
		}
		if ( count( $parts ) === 1 ) {
			return 'Status';
		}
		$last = array_pop( $parts );
		return implode( ', ', $parts ) . ' and ' . $last;
	}

	/**
	 * Which capability a person needs to change this record's status. Taken
	 * from the post type itself, because a post type registered with its own
	 * capability_type has its own publisher: asking for the generic
	 * publish_posts would lock the field for the person actually allowed to
	 * a record of that type, and leave it open to a plain Author who happens
	 * to hold publish_posts for ordinary posts.
	 *
	 * Falls back to publish_posts when there is nothing to derive from — a
	 * post type registered without its own capabilities maps to exactly that
	 * anyway, and at registration time (plugins_loaded, before init) no post
	 * type exists yet, so the object is null and the tab is only being built
	 * for its field ids.
	 */
	private static function publishCapability( array $screen ): string {
		$type = get_post_type_object( $screen['post_type'] ?? '' );
		$cap  = ( $type && isset( $type->cap->publish_posts ) ) ? $type->cap->publish_posts : '';
		return ( is_string( $cap ) && '' !== $cap ) ? $cap : 'publish_posts';
	}
}
