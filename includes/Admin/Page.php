<?php
/**
 * The shell every studio screen renders inside.
 *
 * The foundation's page skeleton, in the order the design system fixes it:
 * wrap → page → header → panels. Nothing here decides what a screen says; it
 * decides only the shape all of them share, so that shape is in one file rather
 * than repeated across eleven.
 *
 * The studio's counterpart to client/includes/Admin/Page.php, and deliberately
 * the same file twice rather than one shared one: the two plugins are two
 * artifacts, and ARCH-1 is that a client's site cannot physically contain
 * studio code. A page shell is not worth reopening that.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

/**
 * The shared page shell.
 */
final class Page {

	/**
	 * Whether the page being rendered holds its panels in a narrow column.
	 *
	 * @var bool
	 */
	private static bool $narrow = false;

	/**
	 * Whether the open panel wraps its body and footer in a form.
	 *
	 * @var bool
	 */
	private static bool $in_form = false;

	/**
	 * Opens the page and its header.
	 *
	 * @param string $title   The page title.
	 * @param string $eyebrow Small label above the title. Optional.
	 * @param string $lede    A sentence under the title. Optional.
	 * @param bool   $narrow  Whether to hold the panels in one narrow column.
	 */
	public static function open( string $title, string $eyebrow = '', string $lede = '', bool $narrow = false ): void {
		self::$narrow = $narrow;

		echo '<div class="wrap bw-wrap"><div class="bw-admin bw-page">';
		echo '<header class="bw-pagehead">';
		echo '<div class="bw-pagehead__titles">';

		if ( '' !== $eyebrow ) {
			printf(
				'<p class="bw-pagehead__eyebrow">%s</p>',
				esc_html( $eyebrow )
			);
		}

		printf( '<h1 class="bw-pagehead__h1">%s</h1>', esc_html( $title ) );

		if ( '' !== $lede ) {
			printf( '<p class="bw-pagehead__lede">%s</p>', esc_html( $lede ) );
		}

		echo '</div></header>';

		// The panel column. ScreenLayout draws this as bw-panels when a screen
		// has no sidebar, and no studio screen has one.
		//
		// A screen that is mostly a form asks for the narrow variant. Left full
		// width, a labelled field runs the whole width of the monitor and the
		// input is a foot of empty box — which is what the availability screen
		// looked like. The body wrapper is what carries the gutter in that
		// case, so the panels stop being a direct child of the page and stop
		// padding themselves.
		echo self::$narrow
			? '<div class="bw-page__body bw-page__body--single"><div class="bw-panels">'
			: '<div class="bw-panels">';
	}

	/**
	 * Closes the page.
	 */
	public static function close(): void {
		echo self::$narrow ? '</div></div></div></div>' : '</div></div></div>';

		self::$narrow = false;
	}

	/**
	 * Opens a panel.
	 *
	 * A panel that is one form opens it here rather than inside the body, so
	 * the form can wrap the fields and the footer its submit belongs on.
	 *
	 * @param string                     $heading The panel heading.
	 * @param string                     $name    A name for tests and styling to hold on to.
	 * @param array<string, string>|null $form    Attributes for a wrapping form, or null for none.
	 * @param callable|null              $actions Echoes what sits on the right of the head.
	 */
	public static function panel_open( string $heading, string $name, ?array $form = null, ?callable $actions = null ): void {
		printf(
			'<section class="bw-card" data-bwx-panel="%s">',
			esc_attr( $name )
		);
		echo '<div class="bw-card__head"><div class="bw-card__titles">';
		printf( '<h2 class="bw-card__title">%s</h2>', esc_html( $heading ) );
		echo '</div>';

		// The head, not the body: what a thing's state is and the one button
		// that changes it belong beside its name. Emitted before the wrapping
		// form opens, so an action that is a form of its own is a sibling of it
		// rather than a form inside a form.
		if ( null !== $actions ) {
			echo '<div class="bw-card__actions">';
			$actions();
			echo '</div>';
		}

		echo '</div>';

		if ( null !== $form ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"';

			foreach ( $form as $name_ => $value ) {
				printf( ' %1$s="%2$s"', esc_attr( $name_ ), esc_attr( $value ) );
			}

			echo '>';
		}

		self::$in_form = null !== $form;

		echo '<div class="bw-card__body">';
	}

	/**
	 * Ends a panel's body and opens the bar its buttons sit on.
	 *
	 * A submit dropped at the end of a body has no space of its own: it sits
	 * hard against the last field above it and the edge of the card below it,
	 * which is how every form on these screens looked. The design system's
	 * answer is a footer — ruled off, padded, and the same on every card — so
	 * the buttons stop being wherever the markup happened to leave them.
	 */
	public static function actions_open(): void {
		echo '</div><div class="bw-card__foot">';
	}

	/**
	 * Closes a panel, whether its body or its footer is open.
	 */
	public static function panel_close(): void {
		echo '</div>';

		if ( self::$in_form ) {
			echo '</form>';
		}

		self::$in_form = false;

		echo '</section>';
	}

	/**
	 * Opens a section inside a panel.
	 *
	 * A card's body often holds several unrelated things — a client's details,
	 * who we are to them, their sites, their people. Run together they read as
	 * one undifferentiated column, which is what they looked like. Each is its
	 * own inset card instead, so the boundary between them is visible rather
	 * than implied by a heading.
	 *
	 * @param string $heading The section heading.
	 * @param string $name    A name for tests and styling to hold on to.
	 */
	public static function section_open( string $heading, string $name ): void {
		printf(
			'<section class="bw-card bw-card--sunken" data-bwx-section="%s">',
			esc_attr( $name )
		);
		echo '<div class="bw-card__head"><div class="bw-card__titles">';
		printf( '<h3 class="bw-card__title">%s</h3>', esc_html( $heading ) );
		echo '</div></div>';
		echo '<div class="bw-card__body bw-panel__loose">';
	}

	/**
	 * Closes a section.
	 */
	public static function section_close(): void {
		echo '</div></section>';
	}

	/**
	 * Opens the design system's accordion, as a <details>.
	 *
	 * The system's own accordion is a button whose open state React holds. A
	 * WordPress admin screen has no React and no bundle of its own, so the same
	 * thing is drawn with the element the browser already opens and closes —
	 * the classes, the chevron and the spacing are the system's, only the
	 * mechanism is different.
	 *
	 * @param string                $summary    The label on the head.
	 * @param array<string, string> $attributes Extra attributes on the details.
	 */
	public static function accordion_open( string $summary, array $attributes = array() ): void {
		echo '<details class="bw-accordion"';

		foreach ( $attributes as $name => $value ) {
			printf( ' %1$s="%2$s"', esc_attr( $name ), esc_attr( $value ) );
		}

		echo '>';
		echo '<summary class="bw-accordion__head">';
		printf( '<span class="bw-accordion__title">%s</span>', esc_html( $summary ) );
		echo '<i class="bw-icon bw-icon--14 bw-accordion__chev" data-lucide="chevron-down"></i>';
		echo '</summary>';
		echo '<div class="bw-accordion__body">';
	}

	/**
	 * Closes an accordion.
	 */
	public static function accordion_close(): void {
		echo '</div></details>';
	}

	/**
	 * A banner.
	 *
	 * The design system's Notice, in the one place every studio screen can
	 * reach it. Replaces the `notice notice-*` markup the screens wrote by
	 * hand, which was WordPress's own admin styling and the one piece of
	 * chrome that would have looked unmistakably unlike the rest of Blueworx
	 * on an otherwise rebuilt screen.
	 *
	 * @param string                $tone       success, warning, danger or info.
	 * @param string                $text       The sentence to show.
	 * @param array<string, string> $attributes Extra attributes on the banner.
	 * @param bool                  $html       Whether $text carries markup.
	 */
	public static function notice( string $tone, string $text, array $attributes = array(), bool $html = false ): void {
		$icons = array(
			'success' => 'circle-check',
			'warning' => 'triangle-alert',
			'danger'  => 'circle-alert',
			'info'    => 'info',
		);

		// Whole class names rather than a stem with the tone appended: the
		// admin UI check reads the classes a screen writes, and one assembled
		// from a variable is one it cannot see.
		$classes = array(
			'success' => 'bw-notice bw-notice--success',
			'warning' => 'bw-notice bw-notice--warning',
			'danger'  => 'bw-notice bw-notice--danger',
			'info'    => 'bw-notice bw-notice--info',
		);

		printf( '<div class="%s"', esc_attr( $classes[ $tone ] ?? $classes['info'] ) );

		foreach ( $attributes as $name => $value ) {
			printf( ' %1$s="%2$s"', esc_attr( $name ), esc_attr( $value ) );
		}

		printf( ' role="%s">', esc_attr( 'danger' === $tone ? 'alert' : 'status' ) );

		printf(
			'<i class="bw-icon bw-notice__icon" data-lucide="%s"></i>',
			esc_attr( $icons[ $tone ] ?? 'info' )
		);

		printf(
			'<div class="bw-notice__body"><p class="bw-notice__text">%s</p></div>',
			$html ? wp_kses_post( $text ) : esc_html( $text )
		);

		echo '</div>';
	}

	/**
	 * The few rules that are ours rather than the design system's.
	 *
	 * Inline, and deliberately short. assets/blueworx-admin-design.css is a
	 * verbatim copy of the skill's styles.css and CI compares the two, so
	 * nothing may be added to it — and nothing here restyles a component.
	 *
	 * It takes off the gutter wp-admin puts around the content column, because
	 * a full-bleed page is the shape the system draws. It gives <details> the
	 * accordion's open state, because the system's accordion is a React button
	 * and these screens have no React. And it takes the bullets off a panel
	 * column written as a list, which the system draws as divs and the specs
	 * need to be <li> elements.
	 *
	 * A field's width inside a toolbar is not here: bw-input is width:100% by
	 * design, so three in a row each took a line of their own, and the system's
	 * own bw-toolbar__search is the rule that sizes a control for a toolbar.
	 * The screens carry that class rather than this file inventing a width.
	 *
	 * The submit rules are the same repair the stylesheet already makes for
	 * .bw-input: wp-admin styles every submit input under .wp-core-ui, which
	 * outweighs a single class, so a primary button submitted a form looking
	 * like a secondary one. The values are the system's own tokens — this wins
	 * the argument, it does not change the answer.
	 *
	 * @return string
	 */
	private static function chrome(): string {
		return <<<'CSS'
#wpcontent{padding-left:0}
#wpbody-content{padding-bottom:0}
#wpfooter{display:none}
ul.bw-panels,ul.bw-panel__loose{list-style:none;margin:0;padding-left:0}
.bw-admin input[type="submit"].bw-btn--primary{background:var(--bw-primary-bg);border-color:var(--bw-primary-bg);color:var(--bw-primary-text)}
.bw-admin input[type="submit"].bw-btn--primary:hover{background:var(--bw-primary-bg-hover);border-color:var(--bw-primary-bg-hover);color:var(--bw-primary-text)}
.bw-admin input[type="submit"].bw-btn--secondary{background:var(--bw-control-bg);border-color:var(--bw-border-field);color:var(--bw-control-text)}
.bw-admin input[type="submit"].bw-btn--secondary:hover{background:var(--bw-control-bg-hover);border-color:var(--bw-border-strong);color:var(--bw-control-text)}
.bw-accordion>summary{list-style:none}
.bw-accordion>summary::-webkit-details-marker{display:none}
.bw-accordion[open]>summary .bw-accordion__chev{transform:rotate(180deg)}
CSS;
	}

	/**
	 * Whether a hook belongs to one of the studio's own screens.
	 *
	 * Matched on the slug prefix rather than against a list, because every
	 * studio screen's slug already begins with it and a list is one more thing
	 * to forget to add a screen to.
	 *
	 * @param string $hook The screen being loaded.
	 */
	public static function ours( string $hook ): bool {
		return false !== strpos( $hook, '_page_blueworx-forge' );
	}

	/**
	 * Loads the design system on the studio's own screens, and nowhere else.
	 *
	 * Not on every admin screen: the stylesheet is a whole design system, and a
	 * plugin that repaints the rest of somebody's wp-admin is a plugin they
	 * uninstall. style-isolation.spec.js is the spec that holds this.
	 *
	 * @param string $hook The screen being loaded.
	 */
	public static function enqueue( string $hook ): void {
		if ( ! self::ours( $hook ) ) {
			return;
		}

		// Asked for rather than enqueued by hand. Every BlueWorx plugin carries
		// its own copy of the design system under the same handle, so a site
		// running two of them used to wear whichever copy enqueued first — our
		// screens could be styled by another plugin's older stylesheet, with
		// nothing anywhere saying so. The registrar loaded in blueworx-forge.php
		// picks the newest copy on the site and enqueues that one, once.
		if ( ! function_exists( 'blueworx_admin_design_enqueue' ) ) {
			return;
		}

		blueworx_admin_design_enqueue();
		blueworx_admin_design_enqueue_icons();

		wp_add_inline_style( 'blueworx-admin-design', self::chrome() );
	}
}
