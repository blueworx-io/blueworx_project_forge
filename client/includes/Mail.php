<?php
/**
 * Whether this site can send mail.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * NOTIF-3 puts client email on the client's own site, through `wp_mail`, so
 * the site's own SMTP configuration handles delivery and mail arrives from a
 * domain the client recognises. Forge stores no credentials and does no
 * sending of its own.
 *
 * What the studio therefore needs to know is narrow: does that path exist here
 * at all. This answers it from what the site can already see — is `wp_mail`
 * callable, and did the last attempt fail — and never by sending a probe.
 *
 * A probe would go either to a real address, mailing somebody who did not ask
 * for it, or to an invented one, which teaches the site's mail provider that
 * this domain generates bounces. Neither is worth a slightly better answer.
 */
final class Mail {

	/**
	 * Option holding the last failure `wp_mail` reported, if any.
	 */
	public const OPTION_FAILURE = 'bwx_forge_client_mail_failure';

	/**
	 * Longest a detail may be, matching the varchar(191) it lands in on the
	 * studio. A mailer that hands back a page of SMTP transcript must not be
	 * able to fail the write that records it.
	 */
	public const MAX_DETAIL = 191;

	/**
	 * Subscribes to what WordPress already tells us about its own sending.
	 */
	public static function boot(): void {
		add_action( 'wp_mail_failed', array( self::class, 'note_failure' ) );
		add_action( 'wp_mail_succeeded', array( self::class, 'remember_success' ) );
	}

	/**
	 * This site's mail capability, as the studio records it.
	 *
	 * @return array{capable: string, detail: string}
	 */
	public static function capability(): array {
		if ( ! function_exists( 'wp_mail' ) ) {
			// A plugin can unhook wp_mail entirely. A site in that state will
			// silently deliver nothing, which is exactly the case worth seeing.
			return self::answer( 'no', 'wp_mail is not available on this site.' );
		}

		$failure = (string) get_option( self::OPTION_FAILURE, '' );

		if ( '' !== $failure ) {
			return self::answer( 'no', $failure );
		}

		return self::answer( 'yes', 'wp_mail is available and its last attempt succeeded.' );
	}

	/**
	 * What the last failure said, if there was one.
	 *
	 * Read rather than inferred: WordPress tells us why through
	 * `wp_mail_failed`, and passing that sentence back to the studio (#174) is
	 * the difference between a problem somebody can fix and one they can only
	 * watch. "Could not send" is not actionable; "SMTP connect() failed" is.
	 *
	 * @return string
	 */
	public static function last_failure(): string {
		return (string) get_option( self::OPTION_FAILURE, '' );
	}

	/**
	 * Records that a send failed.
	 *
	 * @param mixed $error The WP_Error wp_mail_failed carries.
	 */
	public static function note_failure( $error ): void {
		$message = is_object( $error ) && method_exists( $error, 'get_error_message' )
			? (string) $error->get_error_message()
			: '';

		self::remember_failure( '' === $message ? 'wp_mail reported a failure.' : $message );
	}

	/**
	 * Records a failure by its message.
	 *
	 * @param string $message What went wrong.
	 */
	public static function remember_failure( string $message ): void {
		update_option( self::OPTION_FAILURE, self::shorten( $message ) );
	}

	/**
	 * Records that a send worked, clearing any earlier failure.
	 *
	 * Without this a site that had one bad night would report itself unable to
	 * send mail forever, and the studio would go on showing a fault that had
	 * fixed itself.
	 */
	public static function remember_success(): void {
		delete_option( self::OPTION_FAILURE );
	}

	/**
	 * Builds the answer, with the detail trimmed to what will store.
	 *
	 * @param string $capable Yes or no.
	 * @param string $detail  Why.
	 * @return array{capable: string, detail: string}
	 */
	private static function answer( string $capable, string $detail ): array {
		return array(
			'capable' => $capable,
			'detail'  => self::shorten( $detail ),
		);
	}

	/**
	 * Trims a detail to the column it lands in.
	 *
	 * @param string $detail Detail.
	 * @return string
	 */
	private static function shorten( string $detail ): string {
		$detail = trim( $detail );

		return strlen( $detail ) > self::MAX_DETAIL ? substr( $detail, 0, self::MAX_DETAIL ) : $detail;
	}
}
