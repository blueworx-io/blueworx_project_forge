<?php
/**
 * What has been said about one piece of this client's work.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The discussion on one work item, read and added to (#133).
 *
 * The read half takes the ordinary read-through rule (ARCH-2), and the write
 * half deliberately does not — the same split, for the same reason, as the two
 * halves of asking for something (#129). A thread shown a minute old is fine; a
 * comment that will be sent later is a comment somebody believes they have
 * already sent, and there is no honest way to queue one.
 *
 * **Nothing here can move work.** That is not a rule this class follows, it is
 * the whole of what it can do: it reads a thread and it posts words to it, and
 * the studio route it posts to accepts a comment, a link and the id of a
 * question being answered. There is no method here that names a stage, and
 * there is no route on this artifact that could reach one — which is what §14
 * asks for and what the artifact check enforces.
 *
 * What the client may do arrives from the studio with the thread rather than
 * being decided here. A client plugin that held its own opinion about the
 * permission matrix would be a second copy of it, and a screen drawing controls
 * from that copy would offer things the server then refuses (#134).
 */
final class Discussion {

	/**
	 * The studio route one item's discussion lives at.
	 *
	 * @param string $item_id The work item.
	 * @return string
	 */
	public static function route( string $item_id ): string {
		return '/client/work-items/' . rawurlencode( $item_id ) . '/comments';
	}

	/**
	 * The discussion on one item, as this site can currently see it.
	 *
	 * `ok` means the studio answered, not that anybody has said anything. The
	 * distinction is the same one the submissions list keeps: an empty thread
	 * and an unreachable studio look identical as a list and mean opposite
	 * things.
	 *
	 * @param string $item_id The work item.
	 * @param bool   $force   True to ignore a still-fresh copy and ask the studio.
	 * @return array<string, mixed>
	 */
	public static function view( string $item_id, bool $force = false ): array {
		$read    = ReadThrough::view( self::route( $item_id ), $force );
		$payload = $read['payload'];

		return array(
			'ok'          => null !== $payload,
			'item'        => is_array( $payload['item'] ?? null ) ? $payload['item'] : array(),
			'comments'    => is_array( $payload['comments'] ?? null ) ? array_values( $payload['comments'] ) : array(),
			'outstanding' => is_array( $payload['outstanding'] ?? null ) ? array_values( $payload['outstanding'] ) : array(),

			/*
			 * What the studio says this client may do here. Absent means the
			 * studio could not be asked, which is not the same as "nothing" —
			 * the screen says so rather than drawing a form that would fail.
			 */
			'may'         => is_array( $payload['may'] ?? null ) ? $payload['may'] : array(),
			'sync'        => $read['sync'],
		);
	}

	/**
	 * Adds a comment, a piece of evidence, or an answer to something the studio
	 * asked.
	 *
	 * The item id goes in the path rather than the body, which is where the
	 * studio checks it against the site that signed the request. There is
	 * nothing in `$values` that names a client, a site or a stage, and nothing
	 * this method adds that could.
	 *
	 * @param string               $item_id The work item.
	 * @param array<string, mixed> $values  kind, body, url, answers, author_name.
	 * @return array{ok: bool, result: string, message: string}
	 */
	public static function add( string $item_id, array $values ): array {
		if ( ! Connection::is_configured() ) {
			return self::failed( 'not_connected', '' );
		}

		$answer = Connection::post( self::route( $item_id ), $values );

		if ( is_wp_error( $answer ) ) {
			$data   = (array) $answer->get_error_data();
			$studio = is_array( $data['studio_answer'] ?? null ) ? $data['studio_answer'] : array();

			/*
			 * The studio's own sentence, where it gave one. A refusal that
			 * reads "evidence needs a link to what you are showing us" is worth
			 * a great deal more to the person who wrote it than "that could not
			 * be sent", and it is the only part of the answer worth reading
			 * (#134). Where there is no sentence — the studio was unreachable
			 * rather than unwilling — the screen says that instead.
			 */
			$message = trim( (string) ( $studio['message'] ?? '' ) );

			return '' === $message
				? self::failed( 'unreachable', '' )
				: self::failed( 'refused', $message );
		}

		// What this site last saw of the thread is now a comment short, and the
		// person who just pressed send is about to look straight at it.
		Cache::forget( self::route( $item_id ) );

		return array(
			'ok'      => true,
			'result'  => 'added',
			'message' => '',
		);
	}

	/**
	 * A failure, in the shape a caller can read without checking three things.
	 *
	 * @param string $result  One of the result codes the screen knows.
	 * @param string $message The studio's own words, where it gave any.
	 * @return array{ok: bool, result: string, message: string}
	 */
	private static function failed( string $result, string $message ): array {
		return array(
			'ok'      => false,
			'result'  => $result,
			'message' => $message,
		);
	}
}
