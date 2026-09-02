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

/**
 * The other half of asking (#130).
 *
 * Somebody who sends a request wants to know one thing when they come back: did
 * anybody look at it, and what did they say. So the screen is built around that
 * exchange rather than around the record. Each entry puts the client's own
 * words first and the studio's reply underneath them, indented — the shape of a
 * reply to a letter, which is what it is. A table with a status column would
 * have flattened the two into equals, when the whole point of REQ-1 is that one
 * of them is fixed and the other is ours to add.
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
	 * The states worth colouring, and the class each gets.
	 *
	 * @var array<string, string>
	 */
	private const TONES = array(
		'converted' => 'going',
		'accepted'  => 'going',
		'declined'  => 'closed',
	);

	/**
	 * Adds the menu entry.
	 */
	public static function register(): void {
		add_submenu_page(
			Screen::SLUG,
			__( 'What you asked for', 'blueworx-forge' ),
			__( 'What you asked for', 'blueworx-forge' ),
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

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'What you asked for', 'blueworx-forge' ) . '</h1>';

		Nav::render( self::SLUG );

		SyncNotice::render( $view['sync'], self::SLUG );

		if ( ! $view['ok'] ) {
			Denial::render( (string) $view['sync']['state'], Denial::REQUESTS, 'bwx-asked-unavailable' );
			echo '</div>';

			return;
		}

		self::contact( (array) $view['contact'] );

		if ( array() === $view['submissions'] ) {
			self::nothing_asked();
			echo '</div>';

			return;
		}

		echo '<div class="bwx-asked" data-testid="bwx-asked">';

		foreach ( (array) $view['submissions'] as $submission ) {
			self::entry( (array) $submission );
		}

		echo '</div></div>';
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
			return;
		}

		printf(
			'<p class="bwx-empty" data-testid="bwx-asked-contact">%s</p>',
			sprintf(
				/* translators: %s: the name of the client's contact at the studio. */
				esc_html__( 'Anything here you want to talk through, ask %s.', 'blueworx-forge' ),
				esc_html( $name )
			)
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
			'<article class="bwx-asked-entry" data-testid="bwx-asked-entry" data-bwx-state="%s">',
			esc_attr( $state )
		);

		echo '<header class="bwx-asked-head">';

		printf(
			'<h2 class="bwx-asked-title">%s</h2>',
			esc_html( (string) ( $submission['title'] ?? '' ) )
		);

		printf(
			'<span class="bwx-status bwx-status-%1$s" data-testid="bwx-asked-status">%2$s</span>',
			esc_attr( self::TONES[ $state ] ?? 'open' ),
			esc_html( (string) ( $submission['intake_label'] ?? '' ) )
		);

		echo '</header>';

		self::meta( $submission );
		self::asked( $submission );
		self::reply( $submission );

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

		$parts = array( self::type_label( (string) ( $submission['type'] ?? '' ) ) );

		if ( $sent > 0 ) {
			$parts[] = date_i18n( (string) get_option( 'date_format', 'j F Y' ), $sent );
		}

		if ( '' !== $by ) {
			/* translators: %s: the name of the person who sent the request. */
			$parts[] = sprintf( __( 'sent by %s', 'blueworx-forge' ), $by );
		}

		printf(
			'<p class="bwx-asked-meta">%s</p>',
			esc_html( implode( ' · ', $parts ) )
		);
	}

	/**
	 * The client's own words, exactly as they were sent (REQ-1).
	 *
	 * The three boxes the form offers are shown as one piece of prose rather
	 * than as a labelled record, because that is how they were written. A
	 * paragraph somebody left empty is left out, not shown as a blank field.
	 *
	 * @param array<string, mixed> $submission A submission.
	 */
	private static function asked( array $submission ): void {
		echo '<div class="bwx-asked-words">';

		foreach ( array( 'description', 'desired_outcome', 'evidence' ) as $field ) {
			$words = trim( (string) ( $submission[ $field ] ?? '' ) );

			if ( '' !== $words ) {
				printf( '<p>%s</p>', esc_html( $words ) );
			}
		}

		echo '</div>';
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
			printf(
				'<p class="bwx-empty bwx-asked-waiting">%s</p>',
				esc_html__( 'No reply yet.', 'blueworx-forge' )
			);

			return;
		}

		echo '<div class="bwx-asked-reply">';

		if ( '' !== $response ) {
			printf(
				'<p data-testid="bwx-asked-response">%s</p>',
				esc_html( $response )
			);
		}

		if ( array() !== $converted ) {
			printf(
				'<p class="bwx-asked-became">%s <a class="bwx-asked-link" data-testid="bwx-asked-converted" href="%s">%s</a> <span class="bwx-card-key">%s</span></p>',
				esc_html__( 'This became', 'blueworx-forge' ),
				esc_url( admin_url( 'admin.php?page=' . BoardScreen::SLUG ) . '#bwx-item-' . rawurlencode( (string) ( $converted['id'] ?? '' ) ) ),
				esc_html( (string) ( $converted['title'] ?? '' ) ),
				esc_html( (string) ( $converted['stage_label'] ?? '' ) )
			);
		}

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
		printf(
			'<p class="bwx-empty" data-testid="bwx-asked-empty">%s <a href="%s">%s</a>.</p>',
			esc_html__( "You haven't asked for anything yet. Whatever you send appears here, with what the studio said about it.", 'blueworx-forge' ),
			esc_url( admin_url( 'admin.php?page=' . AskScreen::SLUG ) ),
			esc_html__( 'Ask for something', 'blueworx-forge' )
		);
	}
}
