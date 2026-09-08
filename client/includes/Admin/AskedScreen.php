<?php
/**
 * What a client asked for, and what became of it.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Denial;
use Blueworx\Forge\Client\Submissions;
use Blueworx\Forge\Client\Workspace;

/**
 * The other half of asking (#130).
 *
 * Somebody who sends a request wants to know one thing when they come back: did
 * anybody look at it, and what did they say. So the screen is built around that
 * exchange rather than around the record. Each entry puts the client's own
 * words first and the studio's reply underneath them — the shape of a reply to
 * a letter, which is what it is. A table with a status column would have
 * flattened the two into equals, when the whole point of REQ-1 is that one of
 * them is fixed and the other is ours to add.
 *
 * The status is the only coloured thing on the page, and only two of the five
 * states get a colour: work that is going ahead, and work that is not. The rest
 * are quiet, because a screen where every row shouts tells somebody nothing
 * about which row to read first.
 *
 * Nothing here is a control. There is no route on this artifact that could
 * change a submission's state, no form that could edit one, and no button that
 * could withdraw one — the same as every other client view, and for the same
 * reason (#128).
 */
final class AskedScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-client-asked';

	/**
	 * The states worth colouring, and the bw-badge tone each gets.
	 *
	 * Grouped the same way the class doc above argues it: accepted and
	 * converted are both "this is going ahead", so both read as success;
	 * declined is the one negative outcome, so it is the one danger tone.
	 * Received and in-review fall through to neutral — nothing has been
	 * decided yet, so nothing here should look decided.
	 *
	 * @var array<string, string>
	 */
	private const TONES = array(
		'converted' => 'success',
		'accepted'  => 'success',
		'declined'  => 'danger',
	);

	/**
	 * Adds the menu entry.
	 */
	public static function register(): void {
		add_submenu_page(
			Screen::SLUG,
			__( 'Requests', 'blueworx-forge' ),
			__( 'Requests', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$view = Submissions::view( SyncNotice::refresh_requested() );

		// The eyebrow needs the workspace's own read for whose workspace this
		// is (#128, #126) — the same reason WorkScreen reads it separately
		// from the board. Not a new read: it is cached the same as any other
		// read-through view.
		$workspace = Workspace::view( false );

		Page::open( __( 'Requests', 'blueworx-forge' ), Nav::scope_text( $workspace ) );

		SyncNotice::render( $view['sync'], self::SLUG );

		if ( ! $view['ok'] ) {
			Denial::render( (string) $view['sync']['state'], Denial::REQUESTS, 'bwx-asked-unavailable' );
			Page::close();

			return;
		}

		self::contact( (array) $view['contact'] );

		if ( array() === $view['submissions'] ) {
			self::nothing_asked();
			Page::close();

			return;
		}

		echo '<div class="bwx-asked" data-testid="bwx-asked">';

		foreach ( (array) $view['submissions'] as $submission ) {
			self::entry( (array) $submission );
		}

		echo '</div>';

		Page::close();
	}

	/**
	 * Who to chase.
	 *
	 * On this screen rather than only on the landing page because this is where
	 * somebody decides they want to talk to a person about an answer, and a
	 * status with nobody's name against it sends the reply to whichever address
	 * they can remember.
	 *
	 * @param array<string, mixed> $contact The contact, as the studio sent it.
	 */
	private static function contact( array $contact ): void {
		$name = (string) ( $contact['display_name'] ?? '' );

		if ( '' === $name ) {
			self::empty_state(
				'user',
				__( 'No contact assigned yet', 'blueworx-forge' ),
				__( 'Nobody is assigned to you yet. The studio is sorting that out; anything urgent can go to whoever set this site up.', 'blueworx-forge' )
			);

			return;
		}

		Page::notice(
			'info',
			sprintf(
				/* translators: %s: the name of the client's contact at the studio. */
				__( 'Anything here you want to talk through, ask %s.', 'blueworx-forge' ),
				$name
			),
			array( 'data-testid' => 'bwx-asked-contact' )
		);
	}

	/**
	 * One submission: what was asked, then what was said back.
	 *
	 * @param array<string, mixed> $submission As the studio's projection sent it.
	 */
	private static function entry( array $submission ): void {
		$state = (string) ( $submission['intake_state'] ?? '' );

		printf(
			'<article class="bw-card" data-testid="bwx-asked-entry" data-bwx-state="%s">',
			esc_attr( $state )
		);

		echo '<div class="bw-card__head"><div class="bw-card__titles">';

		printf(
			'<h2 class="bw-card__title">%s</h2>',
			esc_html( (string) ( $submission['title'] ?? '' ) )
		);

		echo '</div>';

		printf(
			'<div class="bw-card__actions"><span class="bw-badge bw-badge--%1$s" data-testid="bwx-asked-status">%2$s</span></div>',
			esc_attr( self::TONES[ $state ] ?? 'neutral' ),
			esc_html( (string) ( $submission['intake_label'] ?? '' ) )
		);

		echo '</div>';

		echo '<div class="bw-card__body">';

		self::meta( $submission );
		self::asked( $submission );
		self::reply( $submission );

		echo '</div>';

		self::outcome( $submission );

		echo '</article>';
	}

	/**
	 * The line that says what kind of thing this was, when, and from whom.
	 *
	 * @param array<string, mixed> $submission A submission.
	 */
	private static function meta( array $submission ): void {
		$sent = (int) ( $submission['created_at'] ?? 0 );
		$by   = (string) ( $submission['submitted_by'] ?? '' );

		/*
		 * What kind of thing this was is the part somebody scans for down a
		 * list, so it is a badge rather than the first item in a chain of
		 * middle dots. The chain read as one grey string in which nothing was
		 * more important than anything else — which is the opposite of true.
		 */
		echo '<p class="bwx-asked-meta">';

		printf(
			'<span class="bw-badge bw-badge--neutral">%s</span>',
			esc_html( self::type_label( (string) ( $submission['type'] ?? '' ) ) )
		);

		$said = array();

		if ( $sent > 0 ) {
			$said[] = date_i18n( (string) get_option( 'date_format', 'j F Y' ), $sent );
		}

		if ( '' !== $by ) {
			/* translators: %s: the name of the person who sent the request. */
			$said[] = sprintf( __( 'sent by %s', 'blueworx-forge' ), $by );
		}

		if ( array() !== $said ) {
			printf( '<span>%s</span>', esc_html( implode( ', ', $said ) ) );
		}

		echo '</p>';
	}

	/**
	 * The client's own words, exactly as they were sent (REQ-1), under the
	 * questions that were asked for them.
	 *
	 * These used to run together as one piece of prose, on the reasoning that
	 * prose is how they were written. In front of real requests that turned out
	 * to be wrong: three answers stacked with their questions removed read as
	 * "A fix for the break / The break fixed / Nothing yet", which is not prose
	 * and is barely English. The labels are the form's own words, so somebody
	 * reading a sent request sees the same questions they answered.
	 *
	 * A box somebody left empty is left out, not shown as a blank field.
	 *
	 * @param array<string, mixed> $submission A submission.
	 */
	private static function asked( array $submission ): void {
		$fields = array(
			'description'     => __( 'What you are asking for', 'blueworx-forge' ),
			'desired_outcome' => __( 'What good would look like', 'blueworx-forge' ),
			'evidence'        => __( 'Anything that helps', 'blueworx-forge' ),
		);

		$rows = array();

		foreach ( $fields as $field => $label ) {
			$words = trim( (string) ( $submission[ $field ] ?? '' ) );

			if ( '' !== $words ) {
				$rows[ $label ] = $words;
			}
		}

		if ( array() === $rows ) {
			return;
		}

		echo '<dl class="bw-dl bw-dl--stack bwx-asked-words">';

		/*
		 * Escaped first, then linked. A request that carries a screenshot
		 * carries it as an address (#287), and an address printed as text is one
		 * somebody has to select and copy — which on the screen where they went
		 * to look at it is the one thing it must not be.
		 */
		foreach ( $rows as $label => $words ) {
			printf(
				'<dt>%1$s</dt><dd>%2$s</dd>',
				esc_html( $label ),
				wp_kses_post( make_clickable( esc_html( $words ) ) )
			);
		}

		echo '</dl>';
	}

	/**
	 * What the studio said, and where the work went.
	 *
	 * An unanswered request says so plainly. "No reply yet" is a true and
	 * useful sentence; an empty space under somebody's request is not, and
	 * reads as a screen that failed to load the answer.
	 *
	 * @param array<string, mixed> $submission A submission.
	 */
	private static function reply( array $submission ): void {
		$response  = trim( (string) ( $submission['response'] ?? '' ) );
		$converted = (array) ( $submission['converted'] ?? array() );

		if ( '' === $response && array() === $converted ) {
			self::empty_state(
				'clock',
				__( 'No reply yet', 'blueworx-forge' ),
				__( 'The studio has not answered this one yet.', 'blueworx-forge' )
			);

			return;
		}

		if ( '' === $response ) {
			// Converted with nothing said about it. The outcome strip under the
			// card carries the whole answer, so a heading over an empty space
			// would only look like something failed to load.
			return;
		}

		/*
		 * A labelled divider, because this is where the voice changes. Set as
		 * one more paragraph among the client's own, a reply from us was
		 * indistinguishable from the sentence above it — which on a screen
		 * whose entire subject is "what did they say back" is the one thing it
		 * had to get right.
		 */
		printf(
			'<p class="bw-divider__labelled">%s</p>',
			esc_html__( 'Reply from the studio', 'blueworx-forge' )
		);

		printf(
			'<p data-testid="bwx-asked-response">%s</p>',
			esc_html( $response )
		);
	}

	/**
	 * Where the work went, as the card's own footer.
	 *
	 * An outcome rather than a remark. "This became X" is the single most
	 * useful fact on a request that has been accepted, and buried as the last
	 * line of a paragraph it read as an afterthought — so it sits in the
	 * sunken strip the design system gives a card for exactly this.
	 *
	 * @param array<string, mixed> $submission A submission.
	 */
	private static function outcome( array $submission ): void {
		$converted = (array) ( $submission['converted'] ?? array() );

		if ( array() === $converted ) {
			return;
		}

		echo '<div class="bw-card__foot">';

		printf(
			'<span>%1$s <a data-testid="bwx-asked-converted" href="%2$s">%3$s</a></span>',
			esc_html__( 'This became', 'blueworx-forge' ),
			esc_url( admin_url( 'admin.php?page=' . BoardScreen::SLUG ) . '#bwx-item-' . rawurlencode( (string) ( $converted['id'] ?? '' ) ) ),
			esc_html( (string) ( $converted['title'] ?? '' ) )
		);

		printf(
			'<span class="bw-badge bw-badge--neutral">%s</span>',
			esc_html( (string) ( $converted['stage_label'] ?? '' ) )
		);

		echo '</div>';
	}

	/**
	 * The design system's EmptyState: an icon, a short heading, and the
	 * fuller sentence underneath it.
	 *
	 * The same shape `Screen::nothing()` and `Denial::render()` already draw
	 * — kept as its own small helper here rather than a shared one, because
	 * this screen is the only one that needs to nest it inside a card body
	 * rather than a panel.
	 *
	 * @param string $icon    A lucide icon name shipped with the design system.
	 * @param string $title   Short label for what would appear here.
	 * @param string $text    The fuller sentence, in plain text — escaped
	 *                        here, so a caller who needs to fold in a link
	 *                        (as {@see self::nothing_asked()} does) writes its
	 *                        own markup instead of calling this.
	 * @param string $test_id A hook for tests. Optional.
	 */
	private static function empty_state( string $icon, string $title, string $text, string $test_id = '' ): void {
		printf(
			'<div class="bw-empty"%s>',
			'' === $test_id ? '' : sprintf( ' data-testid="%s"', esc_attr( $test_id ) )
		);
		printf( '<i class="bw-icon bw-empty__icon" data-lucide="%s"></i>', esc_attr( $icon ) );
		printf( '<h3 class="bw-empty__title">%s</h3>', esc_html( $title ) );
		printf( '<p class="bw-empty__text">%s</p>', esc_html( $text ) );
		echo '</div>';
	}

	/**
	 * What a client called the thing they sent.
	 *
	 * @param string $type One of the four kinds the form offers.
	 * @return string
	 */
	private static function type_label( string $type ): string {
		switch ( $type ) {
			/*
			 * #151. Never allowed to fall through to 'Request'. A client who
			 * said something was broken and is shown their own words back as a
			 * request has been quietly told we did not hear the urgent part.
			 */
			case 'bug':
				return __( 'Something broken', 'blueworx-forge' );
			case 'idea':
				return __( 'Idea', 'blueworx-forge' );
			case 'suggestion':
				return __( 'Suggestion', 'blueworx-forge' );
			default:
				return __( 'Request', 'blueworx-forge' );
		}
	}

	/**
	 * A client who has not asked for anything.
	 *
	 * An invitation rather than a shrug: the screen exists because asking is
	 * possible, so the empty state says where to do it.
	 */
	private static function nothing_asked(): void {
		echo '<div class="bw-empty" data-testid="bwx-asked-empty">';
		echo '<i class="bw-icon bw-empty__icon" data-lucide="message-square"></i>';

		printf(
			'<h3 class="bw-empty__title">%s</h3>',
			esc_html__( "You haven't asked for anything yet", 'blueworx-forge' )
		);

		printf(
			'<p class="bw-empty__text">%s <a href="%s">%s</a>.</p>',
			esc_html__( 'Whatever you send appears here, with what the studio said about it.', 'blueworx-forge' ),
			esc_url( admin_url( 'admin.php?page=' . AskScreen::SLUG ) ),
			esc_html__( 'New Request', 'blueworx-forge' )
		);

		echo '</div>';
	}
}
