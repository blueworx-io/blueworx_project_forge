<?php
/**
 * What a client is told when something is not theirs to see or do.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * Denial states and their messaging (#134): one place that turns "you cannot"
 * into a sentence.
 *
 * Before this, every client screen wrote its own. That is how the failure this
 * issue is about happens — not by anybody deciding to be unhelpful, but by six
 * screens each handling the two states somebody thought of at the time, and a
 * seventh state arriving later that none of them knows about. The result is a
 * screen that says "your work cannot be read right now" when what actually
 * happened was that the site's key was revoked, and a person who spends a week
 * blaming their internet connection.
 *
 * So there is one table, it is keyed by the two things that decide the wording,
 * and every state has a row for every subject. A state nobody has written a
 * sentence for falls back to one that is at least true, and the test suite
 * fails rather than letting that be the answer anybody sees.
 *
 * **Three rules the sentences all follow**, and they are worth stating because
 * they are what makes a denial usable rather than merely polite:
 *
 * - **Say which of the three it is.** Not connected, cannot be reached, and not
 *   yours are three different problems with three different next steps, and a
 *   sentence that covers all three helps with none of them.
 * - **Never say "you have nothing".** An empty board and an unreadable board
 *   look identical, and telling a client their work has gone when it has not is
 *   the worst thing any of these screens can say.
 * - **Say what to do, where there is something.** A site nobody has connected
 *   yet has a screen to connect it on; a studio that is down has a "check
 *   again"; a record that is not this site's has neither, and says so rather
 *   than offering a control that cannot help.
 */
final class Denial {

	/**
	 * The workspace record itself — who this client is, and their contact.
	 */
	public const WORKSPACE = 'workspace';

	/**
	 * The work on the board, timeline and calendar.
	 */
	public const WORK = 'work';

	/**
	 * One piece of work, opened.
	 */
	public const ITEM = 'item';

	/**
	 * What this client has asked for, and what became of it.
	 */
	public const REQUESTS = 'requests';

	/**
	 * Asking for something in the first place.
	 *
	 * The one subject that is an action rather than a record, and it is here
	 * for the reason this issue exists: the form used to be drawn on a site
	 * with nowhere to send it, and refused on submit. A denial that arrives
	 * after somebody has written three paragraphs is a denial that should have
	 * been a sentence before they started.
	 */
	public const ASKING = 'asking';

	/**
	 * Every subject a client screen can fail to show.
	 *
	 * Listed so the test that proves every state has a sentence has something
	 * to walk, and so a new screen has to add itself here rather than inventing
	 * its own wording quietly.
	 *
	 * @var array<int, string>
	 */
	public const SUBJECTS = array( self::WORKSPACE, self::WORK, self::ITEM, self::REQUESTS, self::ASKING );

	/**
	 * Whether a sync state is one a screen has to explain.
	 *
	 * Stale is not on this list, and that is the distinction the whole class
	 * turns on: a stale record is still shown, with its age on it, and the
	 * screen carries on. These three are the states where there is nothing to
	 * show at all.
	 *
	 * @param string $state One of the Sync STATE_ constants.
	 * @return bool
	 */
	public static function applies( string $state ): bool {
		return in_array(
			$state,
			array( Sync::STATE_NOT_CONFIGURED, Sync::STATE_REFUSED, Sync::STATE_UNREACHABLE ),
			true
		);
	}

	/**
	 * What to tell somebody.
	 *
	 * @param string $state   One of the Sync STATE_ constants.
	 * @param string $subject One of the SUBJECTS.
	 * @return string
	 */
	public static function sentence( string $state, string $subject ): string {
		switch ( $state ) {
			case Sync::STATE_NOT_CONFIGURED:
				return self::not_connected( $subject );
			case Sync::STATE_REFUSED:
				return self::refused( $subject );
			default:
				return self::unreachable( $subject );
		}
	}

	/**
	 * Renders the sentence, and whatever can actually be done about it.
	 *
	 * @param string $state   One of the Sync STATE_ constants.
	 * @param string $subject One of the SUBJECTS.
	 * @param string $test_id A hook for tests and styling.
	 */
	public static function render( string $state, string $subject, string $test_id = 'bwx-denial' ): void {
		printf(
			'<p class="bwx-empty" data-testid="%1$s" data-bwx-denial="%2$s" data-bwx-subject="%3$s">%4$s',
			esc_attr( $test_id ),
			esc_attr( $state ),
			esc_attr( $subject ),
			esc_html( self::sentence( $state, $subject ) )
		);

		/*
		 * The one state with something to do about it from here. A studio that
		 * cannot be reached already has "check again" in the sync notice above,
		 * and a refusal has nothing anybody on this site can do — offering a
		 * control there would be the dead control this issue exists to remove.
		 */
		if ( Sync::STATE_NOT_CONFIGURED === $state && current_user_can( 'manage_options' ) ) {
			printf(
				' <a href="%s">%s</a>.',
				esc_url( admin_url( 'admin.php?page=' . Admin\ConnectionScreen::SLUG ) ),
				esc_html__( 'Connect this site', 'blueworx-forge' )
			);
		}

		echo '</p>';
	}

	/**
	 * A site nobody has connected yet.
	 *
	 * Written as a promise rather than as a fault. Nothing is broken; this is
	 * simply a site that has not been introduced to the studio, and the screen
	 * should read as one waiting to be useful rather than one that has failed.
	 *
	 * @param string $subject One of the SUBJECTS.
	 * @return string
	 */
	private static function not_connected( string $subject ): string {
		switch ( $subject ) {
			case self::WORK:
				return __( 'Once this site is connected to the studio, your work appears here.', 'blueworx-forge' );
			case self::ITEM:
				return __( 'Once this site is connected to the studio, your work and everything said about it appears here.', 'blueworx-forge' );
			case self::REQUESTS:
				return __( 'Once this site is connected to the studio, everything you have asked for appears here.', 'blueworx-forge' );
			case self::ASKING:
				return __( 'This site is not connected to the studio yet, so there is nowhere to send a request. Nothing is broken — it just has not been set up.', 'blueworx-forge' );
			default:
				return __( 'Once this site is connected to the studio, its workspace appears here.', 'blueworx-forge' );
		}
	}

	/**
	 * The studio was reached and said no.
	 *
	 * The sentence has to do two things at once: stop somebody chasing a
	 * network problem that does not exist, and not say which of the two
	 * refusals it was. "That record belongs to another client" is the
	 * disclosure the studio's own 404 exists to avoid (D-1, D-2), and a client
	 * screen is not the place to undo it.
	 *
	 * @param string $subject One of the SUBJECTS.
	 * @return string
	 */
	private static function refused( string $subject ): string {
		switch ( $subject ) {
			case self::ITEM:
				return __( 'There is no such work. Your connection to the studio is working — this is not something this site can see.', 'blueworx-forge' );
			case self::ASKING:
				return __( 'The studio is not accepting requests from this site. Your connection is working, so this is something at their end — get in touch with whoever set this site up.', 'blueworx-forge' );
			default:
				return __( 'The studio did not recognise this site. Your connection is working, but it is not being answered — somebody at the studio needs to look at it.', 'blueworx-forge' );
		}
	}

	/**
	 * The studio could not be reached at all.
	 *
	 * "Nothing has been lost" is in every one of these on purpose. It is the
	 * sentence that stops a person ringing up in a panic, and it is true: the
	 * studio holds the record, this site only shows it.
	 *
	 * @param string $subject One of the SUBJECTS.
	 * @return string
	 */
	private static function unreachable( string $subject ): string {
		switch ( $subject ) {
			case self::WORK:
				return __( 'Your work cannot be read from the studio right now. Nothing has been lost.', 'blueworx-forge' );
			case self::ITEM:
				return __( 'This work cannot be read from the studio right now. Nothing has been lost.', 'blueworx-forge' );
			case self::REQUESTS:
				return __( 'What you have asked for cannot be read from the studio right now. Nothing has been lost.', 'blueworx-forge' );
			case self::ASKING:
				return __( 'The studio cannot be reached right now, so there is nowhere to send this. Try again in a moment — nothing has been lost.', 'blueworx-forge' );
			default:
				return __( 'Your workspace cannot be read from the studio right now. Nothing has been lost.', 'blueworx-forge' );
		}
	}
}
