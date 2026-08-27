<?php
/**
 * Sending what a client has to say about their work.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Connection;
use Blueworx\Forge\Client\Discussion;

/**
 * The second thing a client site sends rather than reads (#133).
 *
 * The first was a submission (#129), and the reasoning that made that one safe
 * applies here unchanged: it creates a record that belongs to the client
 * sending it, it names nothing outside that client, and the studio answers it
 * for whoever signed the request. The one thing it adds is a work item id, and
 * that is checked against the signing site on the far side before it is used
 * for anything — so an id belonging to another client reads as an id belonging
 * to nobody (D-1, D-2).
 *
 * **Nothing this handler can send moves work.** Three fields go up — what was
 * typed, a link, and which question is being answered — and none of them is a
 * stage. That is worth stating here as well as in the studio's own rules,
 * because this is the file somebody would edit if they ever wanted to: there is
 * no field to add and no route to add it to.
 *
 * Not cached and not retried, for the same reason a submission is not. A
 * comment that quietly went twice gives the studio two of the same remark and
 * the person who wrote it no way to tell which one anybody read.
 */
final class ItemActions {

	/**
	 * The form action.
	 */
	public const ACTION = 'bwx_forge_client_say';

	/**
	 * Hooks the handler up.
	 */
	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'send' ) );
	}

	/**
	 * Sends the form to the studio.
	 */
	public static function send(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to send this.', 'blueworx-forge' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ACTION );

		$item = isset( $_POST['item'] ) ? sanitize_text_field( wp_unslash( $_POST['item'] ) ) : '';

		if ( '' === $item ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . BoardScreen::SLUG ) );
			exit;
		}

		if ( ! Connection::is_configured() ) {
			self::back( $item, 'not_connected', '', array() );
		}

		$body    = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
		$url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$answers = isset( $_POST['answers'] ) ? sanitize_text_field( wp_unslash( $_POST['answers'] ) ) : '';

		$sent = array(

			/*
			 * Decided from what was filled in rather than chosen from a list.
			 * "Comment or evidence" is not a question anybody wants to be asked
			 * — somebody who attaches a link is showing us something, and that
			 * is what evidence is. The studio validates the answer either way,
			 * so the two sides disagreeing is a refusal rather than a wrong
			 * record.
			 */
			'kind'        => '' === $url ? 'comment' : 'evidence',
			'body'        => $body,
			'url'         => $url,
			'answers'     => $answers,
			'author_name' => self::who(),
		);

		$answer = Discussion::add( $item, $sent );

		if ( ! $answer['ok'] ) {
			// The words come back with the failure. Somebody who has just
			// written three paragraphs should not have to write them again
			// because a network call failed.
			self::back(
				$item,
				(string) $answer['result'],
				(string) $answer['message'],
				array(
					'body'    => $body,
					'url'     => $url,
					'answers' => $answers,
				)
			);
		}

		self::back( $item, 'added', '', array() );
	}

	/**
	 * Who this site says is speaking.
	 *
	 * Their display name on their own WordPress, so somebody at the studio
	 * replies to a human rather than to a site. Recorded as a name and used as
	 * one: NOTIF-1 resolves who to write to from verified client records, never
	 * from something typed on the far side of a connection.
	 *
	 * @return string
	 */
	private static function who(): string {
		$user = wp_get_current_user();

		return $user instanceof \WP_User ? (string) $user->display_name : '';
	}

	/**
	 * Returns to the item with the outcome, and stops.
	 *
	 * @param string               $item    The work item.
	 * @param string               $result  One of the result codes the screen knows.
	 * @param string               $message The studio's own words, where it gave any.
	 * @param array<string, mixed> $keep    What to put back in the form.
	 */
	private static function back( string $item, string $result, string $message, array $keep ): void {
		set_transient( ItemScreen::DRAFT, $keep, 5 * MINUTE_IN_SECONDS );

		$url = ItemScreen::url( $item, $result );

		if ( '' !== $message ) {
			$url = add_query_arg( 'bwx-why', rawurlencode( $message ), $url );
		}

		wp_safe_redirect( $url );
		exit;
	}
}
