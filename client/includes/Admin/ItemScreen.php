<?php
/**
 * One piece of work, and what a client may say about it.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Denial;
use Blueworx\Forge\Client\Discussion;
use Blueworx\Forge\Client\Sync;

/**
 * The client's view of one work item, with the three things they may do (#133).
 *
 * This is the first client screen that is not purely a read, and it is worth
 * being exact about what changed and what did not. A client may comment, attach
 * evidence and answer something the studio asked — all three at any stage
 * (AUTH-2). A client may not move the work, and nothing on this page could: the
 * form posts words to a route that accepts words, there is no stage in the
 * markup, no control that names one, and no code on this artifact that could
 * reach a transition (§14, ARCH-1).
 *
 * The work's own detail is drawn by the shared card, so a client cannot see
 * more of an item here than on the board. What is added below it is the
 * conversation, and the conversation is drawn as one — the studio and the
 * client interleaved in the order things were said, each labelled by who said
 * it. A table with an "author" column would have made it a record; it is a
 * correspondence, and reading it in order is the point.
 *
 * **Questions come first, above everything.** If the studio is waiting on an
 * answer, that is the single most useful thing this page can tell somebody, and
 * burying it at the bottom of a thread is how a piece of work sits still for a
 * fortnight over a question nobody scrolled to.
 *
 * Every control here is drawn only where the studio has said it is allowed. The
 * permission answer travels with the thread rather than being decided on this
 * side — a client plugin holding its own copy of the matrix would offer things
 * the server then refuses, which is the exact failure #134 is about.
 */
final class ItemScreen {

	/**
	 * The page this is drawn on.
	 *
	 * The board's, not one of its own. A view of a single card is the board
	 * zoomed in, and registering it as a page would mean either a standing menu
	 * entry pointing at whichever item somebody last opened, or one of
	 * WordPress's hidden-page tricks — an `add_submenu_page` with no parent,
	 * which is deprecated, or a `remove_submenu_page` after the fact, which
	 * removes the entry the capability check reads and turns the page into
	 * "Sorry, you are not allowed to access this page."
	 *
	 * A query argument on a page that already exists needs none of that, and it
	 * is also the truth: this is where the board takes you when you click a
	 * card.
	 */
	public const SLUG = BoardScreen::SLUG;

	/**
	 * The query argument naming which piece of work is on screen.
	 */
	public const PARAM = 'item';

	/**
	 * Where a failed send parks what was typed, so the form can offer it back.
	 */
	public const DRAFT = 'bwx_forge_client_say_draft';

	/**
	 * Whether this request is asking for one item rather than the board.
	 *
	 * @return string The item id, or '' for the board itself.
	 */
	public static function requested(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which record to read is not a change; every read it drives is scoped to this site by the signature on it, so a made-up id here reads nothing.
		return isset( $_GET[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) : '';
	}

	/**
	 * This screen's URL for one item, optionally carrying a result to report.
	 *
	 * @param string $item_id The work item.
	 * @param string $result  A result code, or an empty string.
	 * @return string
	 */
	public static function url( string $item_id, string $result = '' ): string {
		$args = array( self::PARAM => $item_id );

		if ( '' !== $result ) {
			$args['bwx-result'] = $result;
		}

		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	/**
	 * Renders one item.
	 *
	 * @param string $item_id The work item, already read off the request by the
	 *                        board screen this is drawn on.
	 */
	public static function render( string $item_id ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';

		$view = Discussion::view( $item_id, SyncNotice::refresh_requested() );
		$item = (array) $view['item'];

		printf(
			'<h1>%s</h1>',
			esc_html( '' === (string) ( $item['title'] ?? '' ) ? __( 'Work', 'blueworx-forge' ) : (string) $item['title'] )
		);

		Nav::render( BoardScreen::SLUG );

		self::back_link();
		self::result_notice();

		$state = (string) $view['sync']['state'];

		/*
		 * A refusal is not a sync problem, so it does not get a sync notice.
		 * The studio was reached and answered; what it said was that this is
		 * not work this site can see, and "last synced two minutes ago" above
		 * that sentence is noise at best and an invitation to keep pressing
		 * "check again" at worst (#134).
		 */
		if ( ! Sync::is_refusal( $state ) ) {
			SyncNotice::render( $view['sync'], self::SLUG, array( self::PARAM => $item_id ) );
		}

		/*
		 * Three ways there is nothing to draw, and they get two answers.
		 *
		 * A refusal and an answer with no item in it are one sentence, because
		 * they are one fact: this is not work this site can see. Which of the
		 * two it was — an id that never existed, or one belonging to another
		 * client — is exactly what the studio's matching 404s exist to hide
		 * (D-1, D-2), and a client screen is not the place to give it back.
		 *
		 * A studio that could not be reached is the other, and gets its own.
		 */
		if ( Sync::is_refusal( $state ) || ( $view['ok'] && array() === $item ) ) {
			/*
			 * Told as a refusal either way, including the case where the read
			 * itself succeeded. From this screen's side they are the same fact:
			 * the studio answered, and there is no such work here. A separate
			 * sentence for "answered, but empty" would only ever differ by
			 * saying something the refusal is careful not to.
			 */
			Denial::render( Sync::STATE_REFUSED, Denial::ITEM, 'bwx-item-missing' );
			echo '</div>';

			return;
		}

		if ( ! $view['ok'] ) {
			Denial::render( $state, Denial::ITEM, 'bwx-item-unavailable' );
			echo '</div>';

			return;
		}

		echo '<div class="bwx-work">';
		Card::render( $item, true, false );
		echo '</div>';

		self::questions( $item_id, (array) $view['outstanding'], (array) $view['may'] );
		self::thread( (array) $view['comments'] );
		self::say( $item_id, (array) $view['may'] );
		self::nothing_moves();

		echo '</div>';
	}

	/**
	 * A way back to the board.
	 */
	private static function back_link(): void {
		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . BoardScreen::SLUG ) ),
			esc_html__( '← Back to the board', 'blueworx-forge' )
		);
	}

	/**
	 * What happened last time, if anything did.
	 */
	private static function result_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A result code chooses which sentence to print and changes nothing; the send it reports on was itself nonce-checked.
		$result = isset( $_GET['bwx-result'] ) ? sanitize_key( wp_unslash( $_GET['bwx-result'] ) ) : '';

		if ( '' === $result ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same: it is the studio's own sentence being shown back, and it changes nothing.
		$detail = isset( $_GET['bwx-why'] ) ? sanitize_text_field( wp_unslash( $_GET['bwx-why'] ) ) : '';

		$notices = array(
			'added'         => array( 'success', __( 'Sent. The studio can see it.', 'blueworx-forge' ) ),
			'refused'       => array( 'error', __( 'That was not sent.', 'blueworx-forge' ) ),
			'unreachable'   => array( 'error', __( 'The studio could not be reached, so nothing was sent. What you wrote is still here — try again in a moment.', 'blueworx-forge' ) ),
			'not_connected' => array( 'warning', __( 'This site is not connected to the studio yet, so there is nowhere to send this.', 'blueworx-forge' ) ),
		);

		if ( ! isset( $notices[ $result ] ) ) {
			return;
		}

		/*
		 * The studio's own words, where it gave any. A refusal that says
		 * exactly what was wrong is the difference between somebody fixing it
		 * and somebody giving up — and a client who is told only "that did not
		 * work" reaches for email instead, which is the failure the whole
		 * intake side of this product exists to prevent.
		 */
		$message = $notices[ $result ][1] . ( '' === $detail ? '' : ' ' . $detail );

		printf(
			'<div class="notice notice-%1$s" data-bwx-result="%2$s"><p>%3$s</p></div>',
			esc_attr( $notices[ $result ][0] ),
			esc_attr( $result ),
			esc_html( $message )
		);
	}

	/**
	 * What the studio is waiting on, and the box to answer it in.
	 *
	 * Each question carries its own form, rather than one form with a dropdown
	 * of questions. Somebody answering "which page is this on" should be typing
	 * underneath that sentence, not choosing it from a list first — and a
	 * dropdown of questions is how an answer ends up filed against the wrong
	 * one.
	 *
	 * @param string                           $item_id     The work item.
	 * @param array<int, array<string, mixed>> $outstanding The questions waiting.
	 * @param array<string, mixed>             $may         What the studio says
	 *                                                      this client may do.
	 */
	private static function questions( string $item_id, array $outstanding, array $may ): void {
		if ( array() === $outstanding ) {
			return;
		}

		echo '<section class="bwx-asked" data-testid="bwx-questions">';

		printf( '<h2>%s</h2>', esc_html__( 'The studio has asked you something', 'blueworx-forge' ) );

		foreach ( $outstanding as $question ) {
			$id = (string) ( $question['id'] ?? '' );

			echo '<article class="bwx-asked-entry" data-testid="bwx-question">';

			printf( '<p>%s</p>', esc_html( (string) ( $question['body'] ?? '' ) ) );

			printf(
				'<p class="bwx-asked-meta">%s</p>',
				esc_html( self::said_by( $question ) )
			);

			if ( empty( $may['answer'] ) ) {
				/*
				 * No form, and a sentence instead of a disabled box. Somebody
				 * who cannot answer needs to know who can, not a control that
				 * looks broken (#134).
				 */
				printf(
					'<p class="bwx-empty" data-testid="bwx-question-denied">%s</p>',
					esc_html__( 'Answering this is your administrator\'s to do.', 'blueworx-forge' )
				);

				echo '</article>';

				continue;
			}

			self::form(
				$item_id,
				array(
					'answers'  => $id,
					'testid'   => 'bwx-answer-form',
					'label'    => __( 'Your answer', 'blueworx-forge' ),
					'submit'   => __( 'Send this answer', 'blueworx-forge' ),
					'evidence' => false,
				)
			);

			echo '</article>';
		}

		echo '</section>';
	}

	/**
	 * Everything said about this work, in the order it was said.
	 *
	 * Questions the client has already answered stay in the thread rather than
	 * disappearing with the prompt at the top. The answer only makes sense next
	 * to what it answered.
	 *
	 * @param array<int, array<string, mixed>> $comments The client-visible thread.
	 */
	private static function thread( array $comments ): void {
		echo '<section class="bwx-asked" data-testid="bwx-thread">';

		printf( '<h2>%s</h2>', esc_html__( 'Conversation', 'blueworx-forge' ) );

		if ( array() === $comments ) {
			printf(
				'<p class="bwx-empty" data-testid="bwx-thread-empty">%s</p>',
				esc_html__( 'Nothing has been said about this yet. Anything you write below, the studio sees.', 'blueworx-forge' )
			);

			echo '</section>';

			return;
		}

		foreach ( $comments as $comment ) {
			$mine = ! empty( $comment['from_client'] );

			printf(
				'<article class="bwx-asked-entry" data-testid="bwx-thread-entry" data-bwx-from="%s">',
				esc_attr( $mine ? 'client' : 'studio' )
			);

			$body = trim( (string) ( $comment['body'] ?? '' ) );
			$url  = trim( (string) ( $comment['url'] ?? '' ) );

			if ( '' !== $body ) {
				printf( '<p>%s</p>', esc_html( $body ) );
			}

			if ( '' !== $url ) {
				printf(
					'<p><a class="bwx-asked-link" href="%1$s" rel="noreferrer noopener" target="_blank">%1$s</a></p>',
					esc_url( $url )
				);
			}

			printf(
				'<p class="bwx-asked-meta">%s</p>',
				esc_html( self::said_by( $comment ) )
			);

			echo '</article>';
		}

		echo '</section>';
	}

	/**
	 * The box for saying something new.
	 *
	 * @param string               $item_id The work item.
	 * @param array<string, mixed> $may     What the studio says this client may do.
	 */
	private static function say( string $item_id, array $may ): void {
		if ( empty( $may['comment'] ) && empty( $may['evidence'] ) ) {
			printf(
				'<p class="bwx-empty" data-testid="bwx-say-denied">%s</p>',
				esc_html__( 'You can read this work but not add to it. Somebody with an administrator account on this site can.', 'blueworx-forge' )
			);

			return;
		}

		echo '<section class="bwx-asked" data-testid="bwx-say">';

		printf( '<h2>%s</h2>', esc_html__( 'Say something about this', 'blueworx-forge' ) );

		self::form(
			$item_id,
			array(
				'answers'  => '',
				'testid'   => 'bwx-say-form',
				'label'    => __( 'Your comment', 'blueworx-forge' ),
				'submit'   => __( 'Send to the studio', 'blueworx-forge' ),
				'evidence' => ! empty( $may['evidence'] ),
			)
		);

		echo '</section>';
	}

	/**
	 * One form: a box, optionally a link, and a button.
	 *
	 * There is nothing else in it, and there is nothing else that could go in
	 * it. No stage, no status, no dropdown of what to do next — a client
	 * contributes words to their work, and the form has exactly the fields that
	 * are words.
	 *
	 * @param string               $item_id The work item.
	 * @param array<string, mixed> $shape   answers, testid, label, submit, evidence.
	 */
	private static function form( string $item_id, array $shape ): void {
		$draft   = get_transient( self::DRAFT );
		$draft   = is_array( $draft ) ? $draft : array();
		$answers = (string) $shape['answers'];

		// The draft is offered back only to the form it came from. A failed
		// answer reappearing in the general comment box would have somebody
		// send it to the wrong place.
		$mine = (string) ( $draft['answers'] ?? '' ) === $answers;
		$name = 'bwx-say-' . ( '' === $answers ? 'new' : $answers );

		printf(
			'<form method="post" action="%s" data-testid="%s">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( (string) $shape['testid'] )
		);

		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( ItemActions::ACTION ) );
		printf( '<input type="hidden" name="item" value="%s" />', esc_attr( $item_id ) );
		printf( '<input type="hidden" name="answers" value="%s" />', esc_attr( $answers ) );
		wp_nonce_field( ItemActions::ACTION );

		printf(
			'<p><label for="%1$s"><strong>%2$s</strong></label><br /><textarea class="large-text" rows="4" id="%1$s" name="body">%3$s</textarea></p>',
			esc_attr( $name ),
			esc_html( (string) $shape['label'] ),
			esc_textarea( $mine ? (string) ( $draft['body'] ?? '' ) : '' )
		);

		if ( ! empty( $shape['evidence'] ) ) {
			printf(
				'<p><label for="%1$s-url">%2$s</label><br /><input type="url" class="regular-text" id="%1$s-url" name="url" value="%3$s" /><br /><span class="description">%4$s</span></p>',
				esc_attr( $name ),
				esc_html__( 'A link to something that helps', 'blueworx-forge' ),
				esc_attr( $mine ? (string) ( $draft['url'] ?? '' ) : '' ),
				esc_html__( 'Optional. A page, a screenshot somewhere we can see it, an error message.', 'blueworx-forge' )
			);
		}

		submit_button( (string) $shape['submit'], 'primary', 'submit', false );

		echo '</form>';
	}

	/**
	 * The promise, said out loud once per page.
	 *
	 * Not an apology for a missing feature. A client reading their own board
	 * needs to know that nothing they do here changes where the work is — both
	 * so they do not go looking for a control that will never exist, and so
	 * they know that what they say lands with a person rather than moving a
	 * card.
	 */
	private static function nothing_moves(): void {
		printf(
			'<p class="bwx-empty" data-testid="bwx-no-moves">%s</p>',
			esc_html__( 'Where work sits is the studio\'s to change. Anything you add here reaches them without moving it.', 'blueworx-forge' )
		);
	}

	/**
	 * Who said one thing, and when.
	 *
	 * @param array<string, mixed> $comment A thread entry.
	 * @return string
	 */
	private static function said_by( array $comment ): string {
		$name = trim( (string) ( $comment['author_name'] ?? '' ) );
		$who  = empty( $comment['from_client'] )
			? ( '' === $name ? __( 'The studio', 'blueworx-forge' ) : $name )
			: ( '' === $name ? __( 'Someone here', 'blueworx-forge' ) : $name );

		$at = (int) ( $comment['created_at'] ?? 0 );

		if ( $at <= 0 ) {
			return $who;
		}

		return $who . ' · ' . date_i18n( (string) get_option( 'date_format', 'j F Y' ), $at );
	}
}
