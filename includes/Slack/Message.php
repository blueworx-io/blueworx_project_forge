<?php
/**
 * What a Slack message says.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Slack;

/**
 * Pure: the facts in, `{ text, blocks }` out, so every wording can be read
 * in a test rather than in a Slack channel. Short, because it is a ping:
 * what, whose, when it is due, and a link to the task. The link goes to
 * the app page with the item in the hash, which the app opens on load.
 */
final class Message {

	/**
	 * "Assigned to you".
	 *
	 * @param array<string, mixed> $item   The task.
	 * @param string               $seat   Which seat, in words.
	 * @param bool                 $fresh  Whether the task is new.
	 * @param string               $client Whose work it is.
	 * @param string               $link   Where to open it.
	 * @return array{text: string, blocks: array<int, array<string, mixed>>}
	 */
	public static function assigned( array $item, string $seat, bool $fresh, string $client, string $link ): array {
		$title = (string) $item['title'];
		$lead  = $fresh
			/* translators: %s: the seat, e.g. "to do" */
			? sprintf( __( 'New work for you %s', 'blueworx-forge' ), $seat )
			/* translators: %s: the seat, e.g. "to do" */
			: sprintf( __( 'Assigned to you %s', 'blueworx-forge' ), $seat );

		return self::card( $lead, $title, $client, (string) ( $item['planned_due'] ?? '' ), $link );
	}

	/**
	 * "Ready for your review" / "Ready to ship".
	 *
	 * @param array<string, mixed> $item   The task.
	 * @param string               $what   'review' or 'delivery'.
	 * @param string               $client Whose work it is.
	 * @param string               $link   Where to open it.
	 * @return array{text: string, blocks: array<int, array<string, mixed>>}
	 */
	public static function ready( array $item, string $what, string $client, string $link ): array {
		$lead = 'review' === $what
			? __( 'Ready for your review', 'blueworx-forge' )
			: __( 'Ready for you to ship', 'blueworx-forge' );

		return self::card( $lead, (string) $item['title'], $client, (string) ( $item['planned_due'] ?? '' ), $link );
	}

	/**
	 * "Someone commented".
	 *
	 * @param array<string, mixed> $item   The task.
	 * @param string               $author Who commented.
	 * @param string               $body   What they said.
	 * @param string               $client Whose work it is.
	 * @param string               $link   Where to open it.
	 * @return array{text: string, blocks: array<int, array<string, mixed>>}
	 */
	public static function comment( array $item, string $author, string $body, string $client, string $link ): array {
		/* translators: %s: person's name */
		$lead  = sprintf( __( '%s commented', 'blueworx-forge' ), '' === $author ? __( 'Somebody', 'blueworx-forge' ) : $author );
		$quote = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ) ?? '' );
		$quote = mb_strlen( $quote ) > 200 ? mb_substr( $quote, 0, 199 ) . '…' : $quote;

		$card = self::card( $lead, (string) $item['title'], $client, '', $link );

		if ( '' !== $quote ) {
			array_splice(
				$card['blocks'],
				1,
				0,
				array(
					array(
						'type' => 'section',
						'text' => array(
							'type' => 'mrkdwn',
							'text' => '> ' . $quote,
						),
					),
				)
			);
			$card['text'] .= ' — ' . $quote;
		}

		return $card;
	}

	/**
	 * The morning message: today's, then what is overdue.
	 *
	 * @param array<int, array<string, mixed>> $today   Due today, each with title, client, link.
	 * @param array<int, array<string, mixed>> $overdue Overdue, each with title, client, link, planned_due.
	 * @param string                           $date    Today, in words.
	 * @return array{text: string, blocks: array<int, array<string, mixed>>}
	 */
	public static function morning( array $today, array $overdue, string $date ): array {
		if ( array() === $today && array() === $overdue ) {
			$text = __( 'Nothing due today.', 'blueworx-forge' );

			return array(
				'text'   => $text,
				'blocks' => array( self::section( '*' . $date . '* — ' . $text ) ),
			);
		}

		$lines = array();

		foreach ( $today as $one ) {
			$lines[] = sprintf( '• <%s|%s> · %s', $one['link'], self::escape( (string) $one['title'] ), self::escape( (string) $one['client'] ) );
		}

		$blocks = array( self::section( '*' . $date . '*' ) );

		if ( array() !== $lines ) {
			/* translators: %d: number of tasks */
			$blocks[] = self::section( '*' . sprintf( _n( '%d due today', '%d due today', count( $lines ), 'blueworx-forge' ), count( $lines ) ) . "*\n" . implode( "\n", $lines ) );
		}

		$late = array();

		foreach ( $overdue as $one ) {
			$late[] = sprintf( '• <%s|%s> · %s · %s', $one['link'], self::escape( (string) $one['title'] ), self::escape( (string) $one['client'] ), (string) $one['planned_due'] );
		}

		if ( array() !== $late ) {
			/* translators: %d: number of tasks */
			$blocks[] = self::section( '*' . sprintf( _n( '%d overdue', '%d overdue', count( $late ), 'blueworx-forge' ), count( $late ) ) . "*\n" . implode( "\n", $late ) );
		}

		return array(
			'text'   => sprintf(
				/* translators: 1: number due today, 2: number overdue */
				__( '%1$d due today, %2$d overdue', 'blueworx-forge' ),
				count( $today ),
				count( $overdue )
			),
			'blocks' => $blocks,
		);
	}

	/**
	 * "Forge is connected".
	 *
	 * @return array{text: string, blocks: array<int, array<string, mixed>>}
	 */
	public static function test(): array {
		$text = __( 'Forge is connected. You will hear about work assigned to you here.', 'blueworx-forge' );

		return array(
			'text'   => $text,
			'blocks' => array( self::section( $text ) ),
		);
	}

	/**
	 * The shape every ping shares.
	 *
	 * @param string $lead   What happened.
	 * @param string $title  The task.
	 * @param string $client Whose work.
	 * @param string $due    When, or empty.
	 * @param string $link   Where to open it.
	 * @return array{text: string, blocks: array<int, array<string, mixed>>}
	 */
	private static function card( string $lead, string $title, string $client, string $due, string $link ): array {
		$meta = array_filter(
			array(
				$client,
				/* translators: %s: a date */
				'' === $due ? '' : sprintf( __( 'due %s', 'blueworx-forge' ), $due ),
			)
		);

		return array(
			'text'   => $lead . ': ' . $title . ( array() === $meta ? '' : ' (' . implode( ', ', $meta ) . ')' ),
			'blocks' => array(
				self::section( '*' . self::escape( $lead ) . "*\n<" . $link . '|' . self::escape( $title ) . '>' ),
				array(
					'type'     => 'context',
					'elements' => array(
						array(
							'type' => 'mrkdwn',
							'text' => self::escape( array() === $meta ? __( 'Forge', 'blueworx-forge' ) : implode( ' · ', $meta ) ),
						),
					),
				),
			),
		);
	}

	/**
	 * One section block.
	 *
	 * @param string $mrkdwn Slack markdown.
	 * @return array<string, mixed>
	 */
	private static function section( string $mrkdwn ): array {
		return array(
			'type' => 'section',
			'text' => array(
				'type' => 'mrkdwn',
				'text' => $mrkdwn,
			),
		);
	}

	/**
	 * The three characters Slack's markdown reserves.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function escape( string $text ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}
}
