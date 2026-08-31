<?php
/**
 * What a client-facing email says.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Notifications;

/**
 * NOTIF-3's three templates, and NOTIF-2's distinction between the last two.
 *
 * **Ready is not done, and the wording carries that.** Completed is an internal
 * readiness state: the work is approved and waiting to go out. Released is the
 * client's confirmation that it is live. Telling somebody their work is done
 * before it is live is the message that generates the support ticket, so the
 * Completed email says what it means — approved, and going out — and only the
 * Released one says it is finished.
 *
 * Plain text, and no markup anywhere. Three reasons, in order of how much they
 * cost when ignored: it goes through the client's own mail configuration and we
 * do not know what that will do with HTML; a plain-text email cannot carry a
 * tracking pixel or a styled link that looks like something it is not; and the
 * subject and body are then the same thing a person would have typed, which is
 * the tone these should have anyway.
 *
 * Nothing here is stored. The wording is worked out when the email is sent,
 * from the record as it stands, so correcting a typo in a template does not
 * mean a migration — and an email already sent is already gone, which is the
 * only version of it that ever mattered.
 */
final class Templates {

	/**
	 * The longest a subject may be.
	 *
	 * Mail clients cut a subject off somewhere near here anyway, and a title
	 * long enough to push the useful part out of view is a subject that tells
	 * the reader nothing in their inbox list.
	 */
	public const MAX_SUBJECT = 120;

	/**
	 * The email one event sends.
	 *
	 * @param string               $kind  One of {@see Events::ALL}.
	 * @param array<string, mixed> $about title, client_name, reference, and for a
	 *                                    release, where it went.
	 * @return array{subject: string, body: string} Empty strings for an unknown kind.
	 */
	public static function render( string $kind, array $about ): array {
		$title  = self::plain( (string) ( $about['title'] ?? '' ) );
		$client = self::plain( (string) ( $about['client_name'] ?? '' ) );
		$ref    = self::plain( (string) ( $about['reference'] ?? '' ) );

		if ( Events::RECEIVED === $kind ) {
			return self::email(
				'We have your request: ' . $title,
				array(
					'Thanks — we have your request and it is with us.',
					'',
					'You asked for: ' . $title,
					'',
					'We will look at it and come back to you. You can see where it has got to at any time on your own site, under Forge.',
				),
				$ref
			);
		}

		if ( Events::COMPLETED === $kind ) {
			return self::email(
				'Ready to go live: ' . $title,
				array(
					'This is done our end and approved. It is not live yet — we will send you one more email when it is.',
					'',
					'What is ready: ' . $title,
					'',
					'Nothing is needed from you. If you were expecting something different, reply and tell us before it goes out.',
				),
				$ref
			);
		}

		if ( Events::RELEASED === $kind ) {
			$where = self::plain( (string) ( $about['destination'] ?? '' ) );

			return self::email(
				'Now live: ' . $title,
				array(
					'This is live.',
					'',
					'What went out: ' . $title,
					'' === $where ? '' : 'Where: ' . $where,
					'',
					'Please have a look when you get a moment. If something is not as you expected, reply and let us know.',
				),
				$ref
			);
		}

		return array(
			'subject' => '',
			'body'    => '',
		);
	}

	/**
	 * Assembles one, with the reference at the foot.
	 *
	 * The reference is on every email and always in the same place, because the
	 * first thing anybody does with a confusing email is forward it to us and
	 * ask what it is about.
	 *
	 * @param string             $subject Subject line.
	 * @param array<int, string> $lines   Body lines.
	 * @param string             $ref     The record's id.
	 * @return array{subject: string, body: string}
	 */
	private static function email( string $subject, array $lines, string $ref ): array {
		if ( '' !== $ref ) {
			$lines[] = '';
			$lines[] = 'Reference: ' . $ref;
		}

		// A blank line in the middle is deliberate spacing; a run of them at the
		// end is an accident of one field being empty.
		while ( array() !== $lines && '' === end( $lines ) ) {
			array_pop( $lines );
		}

		return array(
			'subject' => self::shorten( $subject ),
			'body'    => implode( "\n", $lines ),
		);
	}

	/**
	 * A value from a record, safe to put in a plain-text email.
	 *
	 * Newlines out, because a title carrying one could otherwise add a line to
	 * the body — or, in a subject, add a header. Header injection through a
	 * work item's title is the one way an email like this could be turned into
	 * something else.
	 *
	 * @param string $value Whatever the record holds.
	 * @return string
	 */
	private static function plain( string $value ): string {
		return trim( (string) preg_replace( '/[\r\n]+/', ' ', $value ) );
	}

	/**
	 * Trims a subject to something an inbox will show.
	 *
	 * @param string $subject Subject.
	 * @return string
	 */
	private static function shorten( string $subject ): string {
		if ( mb_strlen( $subject ) <= self::MAX_SUBJECT ) {
			return $subject;
		}

		return rtrim( mb_substr( $subject, 0, self::MAX_SUBJECT - 1 ) ) . '…';
	}
}
