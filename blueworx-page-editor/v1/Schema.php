<?php
namespace Blueworx\PageEditor\v1;

use InvalidArgumentException;

/**
 * A screen definition is data, so every mistake in it is caught here, loudly,
 * at registration — never as a silently missing field on a live screen.
 *
 * KINDS is closed on purpose. It is the design system's control list; a plugin
 * that needs something else adds it to the design system first.
 */
final class Schema {

	const KINDS = [
		'text', 'textarea', 'richtext', 'number', 'range', 'colour', 'date', 'datetime',
		'copytext', 'select', 'radio', 'checkboxes', 'toggle', 'tokens', 'scrolllist',
		'media', 'file', 'repeater', 'record', 'facts', 'table', 'gantt', 'title', 'slug',
		'preview', 'schedule',
	];

	const CHOICE_KINDS = [ 'select', 'radio', 'checkboxes', 'scrolllist', 'record' ];

	/**
	 * What a repeater row may hold. Still narrower than KINDS, and still for
	 * the same reason: this list says what the browser actually draws in a
	 * row, so a kind may only be added here once Repeater() in
	 * blueworx-page-editor.js has a case for it. A wider list than the screen
	 * can keep to is how a plugin ends up with a select that registers
	 * cleanly, renders as a text box and saves whatever was typed into it —
	 * which is what this list existed to prevent when it held two kinds.
	 *
	 * Sanitise::field() already cleans each cell by its own kind, so nothing
	 * on the server side had to change to widen it.
	 *
	 * A url or email cell is a 'text' with a 'format', not a kind of its own.
	 */
	const REPEATER_KINDS = [ 'text', 'number', 'textarea', 'select', 'toggle', 'media' ];

	/**
	 * The Publish and settings tab the library appends to every record screen
	 * (see Settings::tab()) uses these ids. A plugin's own screen is rejected
	 * if it tries to reuse one, so the appended tab never collides with a
	 * plugin-authored tab or panel of the same id. This only applies to a
	 * record ("post") screen — a settings ("option") screen never gains the
	 * tab, so nothing to collide with.
	 */
	const RESERVED_TAB_IDS   = [ 'publish' ];
	const RESERVED_PANEL_IDS = [ 'status', 'taxonomies', 'parent' ];

	/**
	 * The two post columns the library owns that the Publish tab does not
	 * carry: the record's own title and body. A record screen is expected to
	 * declare them — that is how a record gets a title at all, and
	 * PostStore::POST_COLUMNS routes them to the post rather than to meta.
	 * So unlike the Publish tab's ids they are reserved only inside a
	 * repeater, where a row's cells are stored nested and a cell with one of
	 * these ids reads as if it set the record's title and sets nothing.
	 */
	const POST_COLUMN_FIELD_IDS = [ 'post_title', 'post_content' ];

	/**
	 * A hideable panel gets a field auto-declared on it — <panel_id>__shown —
	 * so its shown/hidden state flows through Capabilities, Sanitise, Validate
	 * and Store like any other value, rather than being invented by the
	 * browser and dropped by Sanitise::values(), which only keeps values for
	 * fields the schema actually declares. Hence the suffix is reserved: a
	 * plugin field with the same ending on a hideable panel would collide
	 * with the one this library adds.
	 */
	const PANEL_SWITCH_SUFFIX = '__shown';

	/**
	 * The Publish and settings tab's own field ids are reserved the same way:
	 * a plugin field called post_status or post_tags would register fine and
	 * then have its value silently redirected into the post column instead of
	 * its own meta. Derived from Settings::tab() itself, never written out a
	 * second time, so this cannot drift from what that tab actually declares.
	 */
	private static function reservedFieldIds( string $slug ): array {
		$tab = Settings::tab( [ 'store' => 'post', 'slug' => $slug ] );
		$ids = [];
		foreach ( $tab['panels'] ?? [] as $panel ) {
			foreach ( $panel['fields'] as $field ) {
				$ids[] = $field['id'];
			}
		}
		return $ids;
	}

	public static function validate( array $screen ): array {
		if ( empty( $screen['slug'] ) || ! is_string( $screen['slug'] ) ) {
			throw new InvalidArgumentException( 'This editor screen needs a slug.' );
		}
		if ( empty( $screen['title'] ) ) {
			throw new InvalidArgumentException( sprintf( 'The "%s" editor screen needs a title.', $screen['slug'] ) );
		}

		$screen['store']      = $screen['store'] ?? 'post';
		$screen['capability'] = $screen['capability'] ?? 'manage_options';
		$screen['eyebrow']    = $screen['eyebrow'] ?? '';
		$screen['lede']       = $screen['lede'] ?? '';
		$screen['tabs']       = $screen['tabs'] ?? [];

		// Shape first, everything else after. A tabs list that is not a list —
		// or, below, a tab, panel or field that is not an array — would
		// otherwise reach a typed parameter and raise a raw PHP TypeError,
		// which names an internal method and an argument position rather than
		// the part of the schema that is wrong.
		if ( ! is_array( $screen['tabs'] ) ) {
			throw new InvalidArgumentException( sprintf( 'The "%s" editor screen has tabs that are not a list. Give it a list of tabs, each one an array.', $screen['slug'] ) );
		}

		if ( ! in_array( $screen['store'], [ 'post', 'option' ], true ) ) {
			throw new InvalidArgumentException( sprintf( 'The "%s" editor screen stores to "%s". It must store to "post" or "option".', $screen['slug'], $screen['store'] ) );
		}
		if ( 'post' === $screen['store'] && empty( $screen['post_type'] ) ) {
			throw new InvalidArgumentException( sprintf( 'The "%s" editor screen stores a record, so it needs a post_type.', $screen['slug'] ) );
		}
		$owns_storage = isset( $screen['read'] ) || isset( $screen['write'] );
		if ( $owns_storage ) {
			self::checkOwnStorage( $screen );
		}
		if ( 'option' === $screen['store'] && ! $owns_storage && empty( $screen['option_name'] ) ) {
			throw new InvalidArgumentException( sprintf( 'The "%s" editor screen stores to options, so it needs an option_name.', $screen['slug'] ) );
		}

		$screen['publishing'] = self::publishing( $screen );

		$seen            = [];
		$tab_ids         = [];
		$panel_ids       = [];
		$dependencies    = [];
		$repeater_scopes = [];
		$check_reserved  = ( 'post' === $screen['store'] );
		$reserved_fields = $check_reserved ? self::reservedFieldIds( $screen['slug'] ) : [];
		foreach ( $screen['tabs'] as $t => $tab ) {
			if ( ! is_array( $tab ) ) {
				throw new InvalidArgumentException( sprintf( 'The "%s" editor screen has a tab that is not an array. Every tab is an array with an id, a label and panels.', $screen['slug'] ) );
			}
			$screen['tabs'][ $t ] = self::tab( $tab, $screen['slug'], $seen, $tab_ids, $panel_ids, $dependencies, $repeater_scopes, $check_reserved, $reserved_fields );
		}

		self::checkDependencies( $screen['slug'], $seen, $repeater_scopes, $dependencies );

		$screen['summary'] = self::summary( $screen );

		return $screen;
	}

	/**
	 * What this screen asked to leave off the Publish and settings tab.
	 *
	 * Checked strictly, and by name: a typo here is a screen that quietly
	 * keeps drawing the field somebody meant to take away, and nobody would
	 * find that by looking at it.
	 *
	 * @return array<string,mixed>
	 */
	private static function publishing( array $screen ): array {
		if ( ! isset( $screen['publishing'] ) ) {
			return [];
		}
		if ( ! is_array( $screen['publishing'] ) ) {
			throw new InvalidArgumentException( sprintf( 'The "%s" editor screen has a publishing setting that is not an array. Give it a list of what to leave off the Publish and settings tab.', $screen['slug'] ) );
		}
		if ( 'post' !== $screen['store'] && [] !== $screen['publishing'] ) {
			throw new InvalidArgumentException( sprintf( 'The "%s" editor screen stores to options, so it has no Publish and settings tab and nothing to leave off it.', $screen['slug'] ) );
		}

		$allowed = Settings::preferences();
		foreach ( $screen['publishing'] as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new InvalidArgumentException( sprintf( 'The "%1$s" editor screen asks about "%2$s" on the Publish and settings tab. It can only ask about: %3$s.', $screen['slug'], $key, implode( ', ', $allowed ) ) );
			}
			if ( 'slug' === $key ) {
				if ( ! in_array( $value, Settings::slugModes(), true ) ) {
					throw new InvalidArgumentException( sprintf( 'The "%1$s" editor screen sets the slug to "%2$s". It must be one of: %3$s.', $screen['slug'], is_scalar( $value ) ? (string) $value : gettype( $value ), implode( ', ', Settings::slugModes() ) ) );
				}
				continue;
			}
			if ( ! is_bool( $value ) ) {
				throw new InvalidArgumentException( sprintf( 'The "%1$s" editor screen sets "%2$s" on the Publish and settings tab to something that is not true or false.', $screen['slug'], $key ) );
			}
		}

		return $screen['publishing'];
	}

	/**
	 * The strip of derived figures under the page header, which stays put while
	 * the tabs beneath it change.
	 *
	 * A cell says what to work out, never how — `sum` and `count` are read in
	 * the browser, so the strip moves as somebody types rather than after a
	 * save. That is the whole reason it is declared rather than computed here:
	 * a PHP callback cannot be sent to the browser, and a round trip per
	 * keystroke is not a live figure.
	 *
	 * A cell shows something and saves nothing, so it is not a field.
	 *
	 * @param array $screen the screen, with its tabs already walked.
	 * @return array<int,array>
	 */
	private static function summary( array $screen ): array {
		$declared = $screen['summary'] ?? [];
		if ( ! is_array( $declared ) ) {
			throw new InvalidArgumentException( sprintf( 'The "%s" editor screen has a summary that is not a list. Give it a list of cells, each one an array.', $screen['slug'] ) );
		}

		$fields = [];
		foreach ( $screen['tabs'] as $tab ) {
			foreach ( $tab['panels'] as $panel ) {
				foreach ( $panel['fields'] as $field ) {
					$fields[ $field['id'] ] = $field;
				}
			}
		}

		$out = [];
		foreach ( $declared as $cell ) {
			if ( ! is_array( $cell ) || empty( $cell['id'] ) || empty( $cell['label'] ) ) {
				throw new InvalidArgumentException( sprintf(
					'A summary cell on the "%s" editor screen needs an id and a label. The summary is the strip of figures under the header, and every figure in it is labelled.',
					$screen['slug']
				) );
			}

			$has_sum   = ! empty( $cell['sum'] );
			$has_count = ! empty( $cell['count'] );
			if ( $has_sum === $has_count ) {
				throw new InvalidArgumentException( sprintf(
					'The summary cell "%s" on the "%s" editor screen needs a sum or a count, and not both. Use sum for a figure added up from a cell, count for how many rows there are.',
					$cell['id'],
					$screen['slug']
				) );
			}

			$targets = self::summaryTargets( $has_sum ? $cell['sum'] : $cell['count'], $has_sum ? 'sum' : 'count', $cell['id'], $screen['slug'] );
			foreach ( $targets as $target ) {
				if ( $has_sum ) {
					self::summaryTarget( $target, 'number', 'sum', $cell['id'], $screen['slug'], $fields );
				} else {
					self::summaryCountable( $target, $cell['id'], $screen['slug'], $fields );
				}
			}

			$wheres = self::summaryWheres( $cell, $targets, $screen['slug'] );
			foreach ( $wheres as $where ) {
				if ( '' !== $where ) {
					self::summaryTarget( $where, 'toggle', 'where', $cell['id'], $screen['slug'], $fields );
				}
			}

			$out[] = [
				'id'     => sanitize_key( $cell['id'] ),
				'label'  => (string) $cell['label'],
				'sum'    => $has_sum ? $targets : [],
				'count'  => $has_count ? $targets : [],
				'where'  => $wheres,
				'suffix' => isset( $cell['suffix'] ) ? (string) $cell['suffix'] : '',
				'foot'   => isset( $cell['foot'] ) ? (string) $cell['foot'] : '',
			];
		}

		return $out;
	}

	/**
	 * A cell's sum or count, always answered as a list.
	 *
	 * One figure may be added up from more than one list, because a figure a
	 * person reads as one number often is: the hours a support package has to
	 * cover are the project work plus the work planned after launch, and
	 * showing that as two cells side by side leaves somebody adding up in
	 * their head. A single target is still written as a plain string, which
	 * is what almost every cell is.
	 *
	 * @param mixed  $declared what the plugin wrote.
	 * @param string $option   sum, count or where, for the message.
	 * @param string $cell_id  the cell, for the message.
	 * @param string $slug     the screen, for the message.
	 * @return array<int,string>
	 */
	private static function summaryTargets( $declared, string $option, string $cell_id, string $slug ): array {
		$list = is_array( $declared ) ? array_values( $declared ) : [ $declared ];
		$out  = [];
		foreach ( $list as $one ) {
			if ( ! is_string( $one ) ) {
				throw new InvalidArgumentException( sprintf(
					'The summary cell "%s" on the "%s" editor screen has something in its %s that is not a field name. Give it one name, or a list of names.',
					$cell_id,
					$slug,
					$option
				) );
			}
			$out[] = trim( $one );
		}
		return $out;
	}

	/**
	 * The filters that go with a cell's targets, one for each.
	 *
	 * A filter names a toggle inside the list it filters, so a cell adding up
	 * two lists needs two filters — one filter cannot name a cell in both.
	 * An empty string means that list is not filtered at all.
	 *
	 * @param array  $cell    the cell as declared.
	 * @param array  $targets its targets, already normalised.
	 * @param string $slug    the screen, for the message.
	 * @return array<int,string>
	 */
	private static function summaryWheres( array $cell, array $targets, string $slug ): array {
		$declared = $cell['where'] ?? '';
		if ( ! is_array( $declared ) && '' === (string) $declared ) {
			return array_fill( 0, count( $targets ), '' );
		}

		$wheres = self::summaryTargets( $declared, 'where', $cell['id'], $slug );
		if ( count( $wheres ) !== count( $targets ) ) {
			throw new InvalidArgumentException( sprintf(
				'The summary cell "%s" on the "%s" editor screen works %d lists out but gives %d filters. A filter names a toggle inside the list it filters, so there has to be one for each — use an empty string for a list you do not want filtered.',
				$cell['id'],
				$slug,
				count( $targets ),
				count( $wheres )
			) );
		}
		return $wheres;
	}

	/**
	 * Resolves "fieldId.cellId" against the screen and insists the cell it
	 * names is of the kind the option needs. Named this way rather than as two
	 * keys because a figure reads as one thing — the hours on a line item —
	 * and splitting it makes a schema harder to scan, not easier.
	 */
	private static function summaryTarget( string $path, string $wants, string $option, string $cell_id, string $slug, array $fields ): void {
		$parts = explode( '.', $path );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			throw new InvalidArgumentException( sprintf(
				'The summary cell "%s" on the "%s" editor screen sets %s to "%s". It names a cell inside a repeater, written as "field.cell".',
				$cell_id,
				$slug,
				$option,
				$path
			) );
		}

		$field = $fields[ $parts[0] ] ?? null;
		if ( null === $field ) {
			throw new InvalidArgumentException( sprintf(
				'The summary cell "%s" on the "%s" editor screen sets %s to "%s", but there is no field called "%s" on this screen.',
				$cell_id,
				$slug,
				$option,
				$path,
				$parts[0]
			) );
		}
		if ( 'repeater' !== $field['kind'] ) {
			throw new InvalidArgumentException( sprintf(
				'The summary cell "%s" on the "%s" editor screen sets %s to "%s", but "%s" is a "%s", not a repeater. Only a repeater has cells to add up.',
				$cell_id,
				$slug,
				$option,
				$path,
				$parts[0],
				$field['kind']
			) );
		}

		foreach ( $field['fields'] as $sub_field ) {
			if ( $sub_field['id'] !== $parts[1] ) {
				continue;
			}
			if ( $sub_field['kind'] !== $wants ) {
				throw new InvalidArgumentException( sprintf(
					'The summary cell "%s" on the "%s" editor screen sets %s to "%s", which is a "%s" cell. It has to be a "%s" cell.',
					$cell_id,
					$slug,
					$option,
					$path,
					$sub_field['kind'],
					$wants
				) );
			}
			return;
		}

		throw new InvalidArgumentException( sprintf(
			'The summary cell "%s" on the "%s" editor screen sets %s to "%s", but the repeater "%s" has no cell called "%s".',
			$cell_id,
			$slug,
			$option,
			$path,
			$parts[0],
			$parts[1]
		) );
	}

	/** A count needs a field that holds a list of rows — a repeater or a gantt. */
	private static function summaryCountable( string $id, string $cell_id, string $slug, array $fields ): void {
		$field = $fields[ $id ] ?? null;
		if ( null === $field ) {
			throw new InvalidArgumentException( sprintf(
				'The summary cell "%s" on the "%s" editor screen counts "%s", but there is no field called "%s" on this screen.',
				$cell_id,
				$slug,
				$id,
				$id
			) );
		}
		if ( ! in_array( $field['kind'], [ 'repeater', 'gantt' ], true ) ) {
			throw new InvalidArgumentException( sprintf(
				'The summary cell "%s" on the "%s" editor screen counts "%s", which is a "%s". Only a repeater or a gantt holds rows to count.',
				$cell_id,
				$slug,
				$id,
				$field['kind']
			) );
		}
	}

	/**
	 * Runs one tab through the same id-uniqueness checks and default-filling
	 * every tab on a registered screen gets, without knowing about any other
	 * tab on the screen. Used to normalise the Publish and settings tab
	 * (Settings::tab()) so it produces fields with the same shape — wide,
	 * required, help, depends_on, locked_help, capability — as a field that
	 * came from a plugin's own schema, rather than a hand-shaped second kind
	 * of field the browser would have to special-case.
	 *
	 * Reserved-id checking never applies here: this is how the reserved ids
	 * themselves get onto the screen.
	 */
	public static function normaliseTab( array $tab, string $slug ): array {
		$seen            = [];
		$tab_ids         = [];
		$panel_ids       = [];
		$dependencies    = [];
		$repeater_scopes = [];

		$tab = self::tab( $tab, $slug, $seen, $tab_ids, $panel_ids, $dependencies, $repeater_scopes );
		self::checkDependencies( $slug, $seen, $repeater_scopes, $dependencies );

		return $tab;
	}

	private static function tab( array $tab, string $slug, array &$seen, array &$tab_ids, array &$panel_ids, array &$dependencies, array &$repeater_scopes, bool $check_reserved = false, array $reserved_fields = [] ): array {
		if ( empty( $tab['id'] ) || empty( $tab['label'] ) ) {
			throw new InvalidArgumentException( sprintf( 'Every tab on the "%s" editor screen needs an id and a label.', $slug ) );
		}
		if ( isset( $tab_ids[ $tab['id'] ] ) ) {
			throw new InvalidArgumentException( sprintf( 'The "%s" editor screen uses the tab id "%s" twice. Tab ids must be unique across the whole screen.', $slug, $tab['id'] ) );
		}
		if ( $check_reserved && in_array( $tab['id'], self::RESERVED_TAB_IDS, true ) ) {
			throw new InvalidArgumentException( sprintf(
				'The "%s" editor screen uses the tab id "%s", which is reserved for the Publish and settings tab the library adds. Choose a different id.',
				$slug,
				$tab['id']
			) );
		}
		$tab_ids[ $tab['id'] ] = true;

		$tab['panels'] = $tab['panels'] ?? [];
		if ( ! is_array( $tab['panels'] ) ) {
			throw new InvalidArgumentException( sprintf( 'The tab "%s" on the "%s" editor screen has panels that are not a list. Give it a list of panels, each one an array.', $tab['id'], $slug ) );
		}
		foreach ( $tab['panels'] as $p => $panel ) {
			if ( ! is_array( $panel ) ) {
				throw new InvalidArgumentException( sprintf( 'The tab "%s" on the "%s" editor screen has a panel that is not an array. Every panel is an array with an id, a title and fields.', $tab['id'], $slug ) );
			}
			$tab['panels'][ $p ] = self::panel( $panel, $slug, $seen, $panel_ids, $dependencies, $repeater_scopes, $check_reserved, $reserved_fields );
		}
		return $tab;
	}

	private static function panel( array $panel, string $slug, array &$seen, array &$panel_ids, array &$dependencies, array &$repeater_scopes, bool $check_reserved = false, array $reserved_fields = [] ): array {
		if ( empty( $panel['id'] ) || empty( $panel['title'] ) ) {
			throw new InvalidArgumentException( sprintf( 'Every panel on the "%s" editor screen needs an id and a title.', $slug ) );
		}
		if ( isset( $panel_ids[ $panel['id'] ] ) ) {
			throw new InvalidArgumentException( sprintf( 'The "%s" editor screen uses the panel id "%s" twice. Panel ids must be unique across the whole screen.', $slug, $panel['id'] ) );
		}
		if ( $check_reserved && in_array( $panel['id'], self::RESERVED_PANEL_IDS, true ) ) {
			throw new InvalidArgumentException( sprintf(
				'The "%s" editor screen uses the panel id "%s", which is reserved for the Publish and settings tab the library adds. Choose a different id.',
				$slug,
				$panel['id']
			) );
		}
		$panel_ids[ $panel['id'] ] = true;

		$panel['eyebrow']  = $panel['eyebrow'] ?? '';
		$panel['note']     = $panel['note'] ?? '';
		$panel['hideable'] = (bool) ( $panel['hideable'] ?? false );
		$panel['fields']   = $panel['fields'] ?? [];

		if ( ! is_array( $panel['fields'] ) ) {
			throw new InvalidArgumentException( sprintf( 'The panel "%s" on the "%s" editor screen has fields that are not a list. Give it a list of fields, each one an array.', $panel['id'], $slug ) );
		}
		foreach ( $panel['fields'] as $given ) {
			if ( ! is_array( $given ) ) {
				throw new InvalidArgumentException( sprintf( 'The panel "%s" on the "%s" editor screen has a field that is not an array. Every field is an array with an id, a kind and a label.', $panel['id'], $slug ) );
			}
		}

		if ( $panel['hideable'] ) {
			foreach ( $panel['fields'] as $existing ) {
				if ( isset( $existing['id'] ) && self::endsWithPanelSwitchSuffix( $existing['id'] ) ) {
					throw new InvalidArgumentException( sprintf(
						'The field "%s" on the "%s" editor screen ends in "%s", which is reserved for a hideable panel\'s own show/hide switch. Choose a different id.',
						$existing['id'],
						$slug,
						self::PANEL_SWITCH_SUFFIX
					) );
				}
			}
		}

		foreach ( $panel['fields'] as $f => $field ) {
			$panel['fields'][ $f ] = self::field( $field, $slug, $seen, null, $dependencies, $repeater_scopes, $reserved_fields );
		}

		// Added after the plugin's own fields are validated, so it never
		// collides with a field id the loop above already claimed, and after
		// the reserved-suffix check above, so this is the only field ever
		// allowed to end in the reserved suffix.
		if ( $panel['hideable'] ) {
			$panel['fields'][] = self::field( [
				'id'           => $panel['id'] . self::PANEL_SWITCH_SUFFIX,
				'kind'         => 'toggle',
				'label'        => 'Shown',
				'panel_switch' => true,
				// A panel nobody has touched has not been hidden — the kind's
				// own default (false) would collapse every hideable panel on
				// a brand-new record, so this one field overrides it.
				'default'      => true,
			], $slug, $seen, null, $dependencies, $repeater_scopes, $reserved_fields );
		}

		return $panel;
	}

	private static function endsWithPanelSwitchSuffix( string $id ): bool {
		$suffix = self::PANEL_SWITCH_SUFFIX;
		return substr( $id, -strlen( $suffix ) ) === $suffix;
	}

	/** The value Store::read() hands back for a field of this kind when it has never been saved. */
	private static function defaultForKind( array $field ) {
		switch ( $field['kind'] ) {
			case 'toggle':
				return false;

			case 'number':
			case 'range':
				return self::defaultZeroClampedToRange( $field );

			case 'media':
			case 'file':
			case 'record':
				return 0;

			case 'checkboxes':
			case 'scrolllist':
			case 'tokens':
			case 'repeater':
			case 'gantt':
				return [];

			default:
				return '';
		}
	}

	/**
	 * 0 is the default a number/range field gets when it declares none of
	 * its own — except Sanitise clamps every value to a declared min/max and
	 * can never actually produce a 0 outside that range, so a fresh screen
	 * would open already showing a value the field would refuse the moment
	 * anyone saved it. Only min above zero or max below zero can ever move
	 * this: any range straddling zero already accepts 0 as-is.
	 */
	/**
	 * A screen may keep its values somewhere this library does not know about,
	 * by supplying both a read and a write callback. See CallbackStore.
	 *
	 * Settings screens only. A record screen's values belong to its post —
	 * that is what "records are post types" means, and a record editor that
	 * quietly stored its values elsewhere would keep the post's own status,
	 * slug and revisions while writing everything else out of reach of them.
	 *
	 * Both or neither: a screen that reads from one place and writes to
	 * another loses every edit on reload, and does it silently.
	 */
	private static function checkOwnStorage( array $screen ): void {
		if ( 'post' === $screen['store'] ) {
			throw new InvalidArgumentException( sprintf(
				'The "%s" editor screen supplies its own read and write, which only a settings screen may do. A record screen stores to its post.',
				$screen['slug']
			) );
		}
		if ( ! isset( $screen['read'], $screen['write'] ) ) {
			throw new InvalidArgumentException( sprintf(
				'The "%s" editor screen supplies only one of read and write. Supply both, or neither.',
				$screen['slug']
			) );
		}
		foreach ( [ 'read', 'write' ] as $which ) {
			if ( ! is_callable( $screen[ $which ] ) ) {
				throw new InvalidArgumentException( sprintf(
					'The "%s" editor screen\'s %s is not callable.',
					$screen['slug'],
					$which
				) );
			}
		}
	}

	/**
	 * A text field's optional list of values to pick from, offered as a
	 * <datalist>. Unlike options on a select these are a shortcut and not a
	 * constraint — the field stays free text and Sanitise never checks a
	 * value against them, because the whole point is a field whose likely
	 * answers are known but whose possible answers are not. A link field is
	 * the case that asked for it: most links point at one of the site's own
	 * pages, and plenty do not.
	 *
	 * Only meaningful on a text field. Declared anywhere else it is a
	 * mistake worth naming rather than ignoring, because the control that
	 * kind draws has nowhere to put it.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	private static function suggestions( array $field, string $slug ): array {
		if ( ! array_key_exists( 'suggestions', $field ) ) {
			return [];
		}
		if ( 'text' !== $field['kind'] ) {
			throw new InvalidArgumentException( sprintf(
				'The field "%s" on the "%s" editor screen offers suggestions, which only a "text" field can — this one is a "%s".',
				$field['id'],
				$slug,
				$field['kind']
			) );
		}
		if ( ! is_array( $field['suggestions'] ) ) {
			throw new InvalidArgumentException( sprintf(
				'The field "%s" on the "%s" editor screen declares suggestions that are not a list.',
				$field['id'],
				$slug
			) );
		}

		$out = [];
		foreach ( $field['suggestions'] as $suggestion ) {
			if ( ! is_array( $suggestion ) || ! isset( $suggestion['value'] ) ) {
				throw new InvalidArgumentException( sprintf(
					'A suggestion on the field "%s" of the "%s" editor screen has no value. Each one needs a value, and a label to show beside it.',
					$field['id'],
					$slug
				) );
			}
			$value = (string) $suggestion['value'];
			if ( '' === $value ) {
				continue;
			}
			$out[] = [
				'value' => $value,
				// A suggestion with no label of its own shows its own value,
				// which is still usable — an address is readable, if plain.
				'label' => (string) ( $suggestion['label'] ?? $value ),
			];
		}
		return $out;
	}

	private static function defaultZeroClampedToRange( array $field ) {
		$value = 0;
		if ( isset( $field['min'] ) && (int) $field['min'] > $value ) {
			$value = (int) $field['min'];
		}
		if ( isset( $field['max'] ) && (int) $field['max'] < $value ) {
			$value = (int) $field['max'];
		}
		return $value;
	}

	/**
	 * Whether a declared default is even the right shape for its field's
	 * kind. Checked at registration, where this library puts every loud
	 * failure: Store::read() also runs a default through castByKind() on
	 * the way out, but that is a defensive fallback for a hand-built screen
	 * that skipped Schema::validate() entirely, not a substitute for telling
	 * whoever wrote the schema what they got wrong.
	 */
	private static function defaultMatchesKind( $value, string $kind ): bool {
		switch ( $kind ) {
			case 'toggle':
				return is_bool( $value );

			case 'number':
			case 'range':
			case 'media':
			case 'file':
			case 'record':
				return is_int( $value );

			case 'checkboxes':
			case 'scrolllist':
			case 'tokens':
			case 'repeater':
			case 'gantt':
				return is_array( $value );

			default:
				return is_string( $value );
		}
	}

	private static function defaultTypeLabel( string $kind ): string {
		switch ( $kind ) {
			case 'toggle':
				return 'a boolean';

			case 'number':
			case 'range':
			case 'media':
			case 'file':
			case 'record':
				return 'an integer';

			case 'checkboxes':
			case 'scrolllist':
			case 'tokens':
			case 'repeater':
			case 'gantt':
				return 'an array';

			default:
				return 'a string';
		}
	}

	/**
	 * Validates one field. Also called, with a fresh $seen set and the
	 * repeater's own id, to validate a repeater's sub-fields — so a repeater
	 * cell gets the same kind/label/options checks and the same defaults as a
	 * top-level field, without a second copy of those checks.
	 *
	 * $dependencies collects every depends_on found anywhere on the screen, to
	 * be resolved once the whole screen is known — see checkDependencies().
	 * That lets a field depend on one declared later, in a later tab or panel.
	 */
	private static function field( array $field, string $slug, array &$seen, ?string $repeater_id, array &$dependencies, array &$repeater_scopes, array $reserved_fields = [] ): array {
		if ( empty( $field['id'] ) ) {
			throw new InvalidArgumentException( sprintf( 'Every field on the "%s" editor screen needs an id.', $slug ) );
		}
		if ( isset( $seen[ $field['id'] ] ) ) {
			throw new InvalidArgumentException( sprintf( 'The "%s" editor screen uses the field id "%s" twice. Every field id is saved as its own value, so they must be unique across the whole screen.', $slug, $field['id'] ) );
		}
		// A repeater's sub-fields are checked too. Their values are stored
		// nested, so nothing would be redirected into a post column — but a
		// sub-field called post_status reads as if it sets the status, and a
		// name that means one thing at the top of a screen and something else
		// inside a repeater is a trap for whoever writes the schema next.
		if ( in_array( $field['id'], $reserved_fields, true ) ) {
			throw new InvalidArgumentException( sprintf(
				'The "%s" editor screen uses the field id "%s", which is reserved for the Publish and settings tab the library adds. Choose a different id.',
				$slug,
				$field['id']
			) );
		}
		// The record's own title and body are a screen's to declare — see
		// POST_COLUMN_FIELD_IDS — but only at the top of it. $reserved_fields
		// is empty on a settings screen, which has no post and so nothing to
		// confuse, so this never fires there.
		if ( null !== $repeater_id && $reserved_fields && in_array( $field['id'], self::POST_COLUMN_FIELD_IDS, true ) ) {
			throw new InvalidArgumentException( sprintf(
				'The field "%s" in the repeater "%s" on the "%s" editor screen has the same id as the record\'s own %s, which a row cannot set — a row\'s values are stored inside the row. Choose a different id.',
				$field['id'],
				$repeater_id,
				$slug,
				'post_title' === $field['id'] ? 'title' : 'body'
			) );
		}
		$seen[ $field['id'] ] = true;

		if ( array_key_exists( 'depends_on', $field ) && null !== $field['depends_on'] ) {
			$on = $field['depends_on'];
			if ( ! is_array( $on ) || ! array_key_exists( 'field', $on ) || ! array_key_exists( 'value', $on ) ) {
				throw new InvalidArgumentException( sprintf( 'The field "%s" on the "%s" editor screen has a depends_on that is not usable. It needs a "field" and a "value".', $field['id'], $slug ) );
			}
			$dependencies[] = [
				'field_id'    => $field['id'],
				'repeater_id' => $repeater_id,
				'target'      => $on['field'],
			];
		}

		if ( empty( $field['kind'] ) || ! in_array( $field['kind'], self::KINDS, true ) ) {
			throw new InvalidArgumentException( sprintf(
				'The field "%s" on the "%s" editor screen asks for "%s", which is not a control the design system has. Use one of: %s. If you need something else, add it to the design system first.',
				$field['id'],
				$slug,
				$field['kind'] ?? '',
				implode( ', ', self::KINDS )
			) );
		}
		if ( null !== $repeater_id && 'repeater' === $field['kind'] ) {
			throw new InvalidArgumentException( sprintf( 'The repeater "%s" on the "%s" editor screen contains another repeater, "%s". A repeater cannot contain a repeater.', $repeater_id, $slug, $field['id'] ) );
		}
		if ( null !== $repeater_id && ! in_array( $field['kind'], self::REPEATER_KINDS, true ) ) {
			throw new InvalidArgumentException( sprintf(
				'The field "%s" in the repeater "%s" on the "%s" editor screen is a "%s". A repeater row can only hold: %s. Move it out of the repeater, or use one of those.',
				$field['id'],
				$repeater_id,
				$slug,
				$field['kind'],
				implode( ', ', self::REPEATER_KINDS )
			) );
		}
		if ( empty( $field['label'] ) ) {
			throw new InvalidArgumentException( sprintf( 'The field "%s" on the "%s" editor screen needs a label.', $field['id'], $slug ) );
		}
		if ( in_array( $field['kind'], self::CHOICE_KINDS, true ) && empty( $field['options'] ) && 'record' !== $field['kind'] ) {
			throw new InvalidArgumentException( sprintf( 'The field "%s" on the "%s" editor screen is a %s, so it needs options.', $field['id'], $slug, $field['kind'] ) );
		}
		$field['suggestions'] = self::suggestions( $field, $slug );

		// A gantt numbers its phases in weeks, and can also show them as dates.
		// It cannot work the dates out on its own — week 1 is whenever the
		// screen's own record says the work starts — so the plugin names the
		// day to count forward from. Empty means the browser counts from today,
		// which is right for a schedule with no start date of its own.
		if ( 'gantt' === $field['kind'] ) {
			$field['origin'] = isset( $field['origin'] ) ? (string) $field['origin'] : '';
		}

		// A preview shows a page of the site in a device frame, so somebody can
		// see what they are editing without leaving the screen. The plugin says
		// which address to show, because only it knows how one of its records
		// turns into a page; everything else about the frame belongs to the
		// design system. An empty address is not a mistake — a record with no
		// page yet has nothing to show, and the frame says so.
		if ( 'preview' === $field['kind'] ) {
			$field['url'] = isset( $field['url'] ) ? (string) $field['url'] : '';
		}

		$field['help']        = $field['help'] ?? '';
		$field['required']    = (bool) ( $field['required'] ?? false );
		$field['capability']  = $field['capability'] ?? '';
		$field['locked_help'] = $field['locked_help'] ?? '';
		// A list whose rows are settled: the screen offers no way to add one,
		// remove one or reorder them, and every cell still edits. Only a list
		// has rows to fix, so declaring it anywhere else is a mistake worth
		// saying out loud rather than ignoring.
		$field['fixed']       = (bool) ( $field['fixed'] ?? false );
		if ( $field['fixed'] && ! in_array( $field['kind'], [ 'repeater', 'gantt' ], true ) ) {
			throw new InvalidArgumentException( sprintf(
				'The field "%s" on the "%s" editor screen is a "%s" and sets fixed. Only a repeater or a gantt holds rows to fix.',
				$field['id'],
				$slug,
				$field['kind']
			) );
		}
		// A control that shows its value and refuses to change it. Declared by
		// a plugin for a value something other than this screen owns — a row
		// copied from a library that decides which phase the work belongs to —
		// and set here as well by Capabilities::reduce() for a field the
		// current user may see but not write, which is why it is only given a
		// default rather than validated against a kind: every kind can be read.
		$field['readonly']    = (bool) ( $field['readonly'] ?? false );
		$field['depends_on']  = $field['depends_on'] ?? null;
		$field['wide']        = (bool) ( $field['wide'] ?? in_array( $field['kind'], [ 'richtext', 'repeater', 'media', 'file', 'table', 'facts', 'gantt', 'schedule', 'title', 'preview' ], true ) );
		// What Store::read() hands back for this field when it has never
		// been saved. A plugin may declare its own; otherwise it follows the
		// kind, so a never-touched toggle reads false and a never-touched
		// list reads [] rather than every kind sharing the single '' a bare
		// unset value would otherwise be.
		if ( array_key_exists( 'default', $field ) ) {
			if ( ! self::defaultMatchesKind( $field['default'], $field['kind'] ) ) {
				throw new InvalidArgumentException( sprintf(
					'The field "%s" on the "%s" editor screen declares a default that is not %s, which a "%s" field needs.',
					$field['id'],
					$slug,
					self::defaultTypeLabel( $field['kind'] ),
					$field['kind']
				) );
			}
		} else {
			$field['default'] = self::defaultForKind( $field );
		}

		if ( null === $repeater_id && 'repeater' === $field['kind'] ) {
			if ( empty( $field['fields'] ) ) {
				throw new InvalidArgumentException( sprintf( 'The repeater "%s" on the "%s" editor screen needs at least one sub-field.', $field['id'], $slug ) );
			}
			if ( ! is_array( $field['fields'] ) ) {
				throw new InvalidArgumentException( sprintf( 'The repeater "%s" on the "%s" editor screen has sub-fields that are not a list. Give it a list of sub-fields, each one an array.', $field['id'], $slug ) );
			}
			$sub_seen = [];
			foreach ( $field['fields'] as $sf => $sub_field ) {
				if ( ! is_array( $sub_field ) ) {
					throw new InvalidArgumentException( sprintf( 'The repeater "%s" on the "%s" editor screen has a sub-field that is not an array. Every sub-field is an array with an id, a kind and a label.', $field['id'], $slug ) );
				}
				$field['fields'][ $sf ] = self::field( $sub_field, $slug, $sub_seen, $field['id'], $dependencies, $repeater_scopes, $reserved_fields );
			}
			$repeater_scopes[ $field['id'] ] = $sub_seen;
			self::groupingOptions( $field, $slug );
		}

		return $field;
	}

	/**
	 * A repeater whose rows fall into named groups, each with a subtotal — an
	 * estimate's phases, say. Both options name one of the repeater's own
	 * cells, so this runs after the sub-fields are walked and every cell id is
	 * known.
	 *
	 * A repeater that sets neither still answers for both, so a consumer can
	 * read the keys without checking they exist first.
	 *
	 * @param array  $field the repeater, by reference.
	 * @param string $slug  the screen, for the error message.
	 */
	private static function groupingOptions( array &$field, string $slug ): void {
		foreach ( [ 'group_by' => 'select', 'subtotal_of' => 'number' ] as $option => $wants ) {
			if ( ! array_key_exists( $option, $field ) || null === $field[ $option ] || '' === $field[ $option ] ) {
				$field[ $option ] = '';
				continue;
			}

			$target = null;
			foreach ( $field['fields'] as $sub_field ) {
				if ( $sub_field['id'] === $field[ $option ] ) {
					$target = $sub_field;
					break;
				}
			}

			if ( null === $target ) {
				throw new InvalidArgumentException( sprintf(
					'The repeater "%s" on the "%s" editor screen sets %s to "%s", which is not one of its own cells. Use the id of a cell inside this repeater.',
					$field['id'],
					$slug,
					$option,
					$field[ $option ]
				) );
			}
			if ( $target['kind'] !== $wants ) {
				throw new InvalidArgumentException( sprintf(
					'The repeater "%s" on the "%s" editor screen sets %s to "%s", which is a "%s" cell. It has to be a "%s" cell.',
					$field['id'],
					$slug,
					$option,
					$field[ $option ],
					$target['kind'],
					$wants
				) );
			}

			$field[ $option ] = (string) $field[ $option ];
		}

		// What a subtotal is counted in ("hrs"), and what the group of rows
		// whose group cell is empty is called. Both are labels, so neither is
		// checked against anything.
		$field['subtotal_suffix']   = isset( $field['subtotal_suffix'] ) ? (string) $field['subtotal_suffix'] : '';
		$field['group_empty_label'] = isset( $field['group_empty_label'] ) ? (string) $field['group_empty_label'] : 'Ungrouped';
	}

	/**
	 * Resolves every depends_on collected while walking the screen, now that
	 * every field id — top-level and, per repeater, sub-field — is known. Runs
	 * after the whole screen is walked so a field may depend on one declared
	 * later.
	 *
	 * A top-level field may only depend on another top-level field; a repeater
	 * sub-field may only depend on another sub-field in the same repeater. Sub-
	 * field values live inside rows, so a dependency that crosses that boundary
	 * has no meaning.
	 */
	private static function checkDependencies( string $slug, array $seen, array $repeater_scopes, array $dependencies ): void {
		foreach ( $dependencies as $dep ) {
			$field_id    = $dep['field_id'];
			$repeater_id = $dep['repeater_id'];
			$target      = $dep['target'];

			if ( null === $repeater_id ) {
				if ( isset( $seen[ $target ] ) ) {
					continue;
				}
				if ( self::inAnyRepeater( $target, $repeater_scopes ) ) {
					throw new InvalidArgumentException( sprintf(
						'The field "%s" on the "%s" editor screen depends on "%s", which is a field inside a repeater. A top-level field can only depend on another top-level field.',
						$field_id,
						$slug,
						$target
					) );
				}
				throw new InvalidArgumentException( sprintf(
					'The field "%s" on the "%s" editor screen depends on "%s", which is not a field on the "%s" editor screen.',
					$field_id,
					$slug,
					$target,
					$slug
				) );
			}

			if ( isset( $repeater_scopes[ $repeater_id ][ $target ] ) ) {
				continue;
			}
			if ( isset( $seen[ $target ] ) || self::inAnyRepeater( $target, $repeater_scopes ) ) {
				throw new InvalidArgumentException( sprintf(
					'The field "%s" in the repeater "%s" on the "%s" editor screen depends on "%s", which is not a field in the same repeater. A repeater sub-field can only depend on another field in the same repeater.',
					$field_id,
					$repeater_id,
					$slug,
					$target
				) );
			}
			throw new InvalidArgumentException( sprintf(
				'The field "%s" in the repeater "%s" on the "%s" editor screen depends on "%s", which is not a field on the "%s" editor screen.',
				$field_id,
				$repeater_id,
				$slug,
				$target,
				$slug
			) );
		}
	}

	private static function inAnyRepeater( string $target, array $repeater_scopes ): bool {
		foreach ( $repeater_scopes as $scope ) {
			if ( isset( $scope[ $target ] ) ) {
				return true;
			}
		}
		return false;
	}
}
