<?php
/**
 * Discussion and evidence attached to work.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * #100. Comments, evidence and attachments, inheriting the record's tenant
 * scoping, with internal notes in a different permission scope from anything a
 * client can see.
 *
 * **The filter is in the query, not in the caller.** `for_item()` will not
 * return an internal note to a client reader because it never selects one —
 * rather than selecting everything and trusting each screen to hide the right
 * rows. A visibility rule enforced by the thing doing the rendering is one bug
 * in one template away from showing a client what we said about them.
 *
 * Append-only, like the changelog. A comment is corrected by another comment.
 */
final class Comments {

	/**
	 * Id prefix for a comment.
	 */
	public const PREFIX = 'cmt';

	/**
	 * Ours. Never leaves the studio.
	 */
	public const INTERNAL = 'internal';

	/**
	 * Written to be read by the client.
	 */
	public const CLIENT = 'client';

	/**
	 * The two visibilities. There is no third, and no "everyone": a comment is
	 * either safe for the client to read or it is not.
	 */
	public const VISIBILITIES = array( self::INTERNAL, self::CLIENT );

	/**
	 * Ordinary discussion.
	 */
	public const COMMENT = 'comment';

	/**
	 * Something offered as proof: a screenshot, a link, a test run.
	 */
	public const EVIDENCE = 'evidence';

	/**
	 * A file attached to the item.
	 */
	public const ATTACHMENT = 'attachment';

	/**
	 * The studio asking the client for something (#133).
	 *
	 * An information request, in AUTH-2's words. It is a kind of comment rather
	 * than a record of its own because that is what it is: somebody writing a
	 * sentence on an item, asking a question. What makes it worth naming is the
	 * other end — a client answering it is a permitted contribution in the
	 * matrix, and "is anybody waiting on us" has to be answerable without
	 * reading every thread.
	 *
	 * A question is always client-visible. A question the client cannot see is
	 * an internal note, and {@see self::visibility_of()} makes that true rather
	 * than leaving it to whoever writes the form.
	 */
	public const QUESTION = 'question';

	/**
	 * What a record on an item can be.
	 */
	public const KINDS = array( self::COMMENT, self::EVIDENCE, self::ATTACHMENT, self::QUESTION );

	/**
	 * The kinds a client may add from their own site (#133).
	 *
	 * Three of the four, and the missing one is the point. A client comments,
	 * attaches evidence and answers what we asked — every one of which leaves
	 * the stage exactly where it was (AUTH-2, §14). They do not ask information
	 * requests of themselves.
	 *
	 * **Nothing in this list moves work**, and that is a property of the list
	 * rather than of the routes that read it: there is no kind here whose
	 * handling touches a stage, so a client contribution cannot become a
	 * transition however it is addressed.
	 *
	 * @var array<int, string>
	 */
	public const CLIENT_KINDS = array( self::COMMENT, self::EVIDENCE, self::ATTACHMENT );

	/**
	 * Longest a body may be.
	 */
	public const MAX_BODY = 5000;

	/**
	 * The reader who sees everything.
	 */
	public const SCOPE_STAFF = 'staff';

	/**
	 * The reader who sees only what is marked for them.
	 */
	public const SCOPE_CLIENT = 'client';

	/**
	 * Adds a comment, a piece of evidence, an attachment or a question.
	 *
	 * **An entry has to say who wrote it, and there are two ways to.** A person
	 * signed in here is a WordPress user id; a person typing on their client's
	 * own site is that site, proved by the signature on the request that
	 * carried the words (ARCH-6). Both count and neither is optional — an entry
	 * attributed to nobody is one nothing can be asked about afterwards, which
	 * is the whole reason this refuses rather than defaulting.
	 *
	 * @param array<string, mixed> $entry item_id, client_site_id, client_id,
	 *                                    kind, visibility, body, url, author,
	 *                                    author_name, author_site, answers.
	 * @param string               $scope The author's reading scope.
	 * @return array<string, mixed>|null Null when it was refused.
	 */
	public static function add( array $entry, string $scope = self::SCOPE_STAFF ): ?array {
		global $wpdb;

		$author = (int) ( $entry['author'] ?? 0 );
		$site   = trim( (string) ( $entry['author_site'] ?? '' ) );
		$body   = trim( (string) ( $entry['body'] ?? '' ) );
		$url    = trim( (string) ( $entry['url'] ?? '' ) );
		$kind   = (string) ( $entry['kind'] ?? self::COMMENT );

		$visibility = self::visibility_of( (string) ( $entry['visibility'] ?? self::INTERNAL ), $scope, $kind );

		if ( ( $author <= 0 && '' === $site ) || ! in_array( $kind, self::KINDS, true ) ) {
			return null;
		}

		// A client site never writes as a person here, and a person here never
		// writes as a site. Both set at once would be a row two different
		// stories could be told about.
		if ( $author > 0 && '' !== $site ) {
			return null;
		}

		// A question is the studio's to ask. A client answering one writes an
		// ordinary comment that names what it answers.
		if ( self::QUESTION === $kind && self::SCOPE_CLIENT === $scope ) {
			return null;
		}

		// Evidence with nothing to look at is a comment claiming to be evidence.
		if ( ! in_array( $kind, array( self::COMMENT, self::QUESTION ), true ) && '' === $url ) {
			return null;
		}

		if ( '' === $body && '' === $url ) {
			return null;
		}

		$row = array(
			'id'             => Ids::create( self::PREFIX ),
			'item_id'        => (string) ( $entry['item_id'] ?? '' ),
			'client_site_id' => (string) ( $entry['client_site_id'] ?? '' ),
			'client_id'      => (string) ( $entry['client_id'] ?? '' ),
			'kind'           => $kind,
			'visibility'     => $visibility,
			'body'           => mb_substr( $body, 0, self::MAX_BODY ),
			'url'            => mb_substr( $url, 0, 255 ),
			'author'         => $author,
			'author_name'    => mb_substr( trim( (string) ( $entry['author_name'] ?? '' ) ), 0, 191 ),
			'author_site'    => mb_substr( $site, 0, 32 ),
			'answers'        => self::answers( $entry ),
			'created_at'     => bwx_forge_now(),
		);

		if ( '' === $row['item_id'] ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$written = $wpdb->insert( Schema::comments_table(), $row, Formats::for_row( $row ) );

		return $written ? self::hydrate( $row ) : null;
	}

	/**
	 * What a reader in this scope may see on an item, oldest first.
	 *
	 * @param string $item_id Item id.
	 * @param string $scope   The reader's scope.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_item( string $item_id, string $scope ): array {
		global $wpdb;

		$table = Schema::comments_table();

		if ( self::SCOPE_STAFF === $scope ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE item_id = %s ORDER BY created_at ASC, id ASC", $item_id ), ARRAY_A );

			return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
		}

		/*
		 * The client read. Note that it is a different query rather than the
		 * same query with a flag: there is no argument anybody can pass, and no
		 * bug anybody can introduce in a caller, that turns this into the read
		 * above. #100's acceptance is exactly that a client cannot reach an
		 * internal note by any route.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder; the values are placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE item_id = %s AND visibility = %s ORDER BY created_at ASC, id ASC",
				$item_id,
				self::CLIENT
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Whether a reader in this scope may see a comment.
	 *
	 * The same rule as the query above, expressed once more so a unit test can
	 * ask it directly without a database.
	 *
	 * @param array<string, mixed> $comment The comment.
	 * @param string               $scope   The reader's scope.
	 * @return bool
	 */
	public static function visible_to( array $comment, string $scope ): bool {
		if ( self::SCOPE_STAFF === $scope ) {
			return true;
		}

		return self::CLIENT === (string) ( $comment['visibility'] ?? self::INTERNAL );
	}

	/**
	 * Which visibility a comment actually gets.
	 *
	 * Anything unrecognised becomes internal. That default is the safe one in
	 * the only direction that matters: a note wrongly kept private is an
	 * inconvenience, and a note wrongly published is a phone call.
	 *
	 * A question is the one kind whose visibility is not a choice. Asking the
	 * client something and hiding it from them is not an information request,
	 * it is a note to ourselves — so the kind decides, and no form can get it
	 * wrong.
	 *
	 * @param string $requested What the caller asked for.
	 * @param string $scope     The author's scope.
	 * @param string $kind      What is being written.
	 * @return string
	 */
	public static function visibility_of( string $requested, string $scope, string $kind = self::COMMENT ): string {
		if ( self::SCOPE_CLIENT === $scope || self::QUESTION === $kind ) {
			// A client cannot write an internal note. There is nowhere for one
			// of theirs to be internal *to*.
			return self::CLIENT;
		}

		return in_array( $requested, self::VISIBILITIES, true ) ? $requested : self::INTERNAL;
	}

	/**
	 * The questions on an item nobody has come back on (#133).
	 *
	 * Read for the client's own screen, so a person arriving at a piece of
	 * their work is told what is being waited on rather than having to spot it
	 * in a thread. Answered questions drop out: a screen that keeps asking
	 * something already answered is a screen people stop reading.
	 *
	 * Worked out from the client-visible comments rather than by a second
	 * query, because "which questions are outstanding" and "what may this
	 * client see" have to be answered by the same set of rows. Two queries is
	 * how a question the client cannot see ends up on their screen.
	 *
	 * @param string $item_id Item id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function outstanding( string $item_id ): array {
		$comments = self::for_item( $item_id, self::SCOPE_CLIENT );
		$answered = array();

		foreach ( $comments as $comment ) {
			$answers = (string) ( $comment['answers'] ?? '' );

			if ( '' !== $answers ) {
				$answered[] = $answers;
			}
		}

		return array_values(
			array_filter(
				$comments,
				static fn( array $comment ): bool => self::QUESTION === (string) $comment['kind']
					&& ! in_array( (string) $comment['id'], $answered, true )
			)
		);
	}

	/**
	 * Which comment an entry answers, where it answers one.
	 *
	 * Not checked against the item here, deliberately: this class writes rows
	 * and the route knows which item it is on. What it does do is refuse to
	 * record an answer on anything but an ordinary comment — a piece of
	 * evidence that claimed to answer a question would make an outstanding
	 * question disappear from a client's screen without a sentence anybody can
	 * read.
	 *
	 * @param array<string, mixed> $entry The entry being written.
	 * @return string
	 */
	private static function answers( array $entry ): string {
		$kind = (string) ( $entry['kind'] ?? self::COMMENT );

		return self::COMMENT === $kind
			? mb_substr( trim( (string) ( $entry['answers'] ?? '' ) ), 0, 32 )
			: '';
	}

	/**
	 * How many of each visibility an item carries. Read by the item panel so it
	 * can say "3 internal notes" without fetching them.
	 *
	 * @param string $item_id Item id.
	 * @return array{internal: int, client: int}
	 */
	public static function counts( string $item_id ): array {
		$counts = array(
			'internal' => 0,
			'client'   => 0,
		);

		foreach ( self::for_item( $item_id, self::SCOPE_STAFF ) as $comment ) {
			++$counts[ $comment['visibility'] ];
		}

		return $counts;
	}

	/**
	 * Turns a row into the record the rest of the plugin uses.
	 *
	 * @param array<string, mixed> $row Row as stored.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'          => (string) $row['id'],
			'item_id'     => (string) $row['item_id'],
			'kind'        => (string) $row['kind'],
			'visibility'  => (string) $row['visibility'],
			'body'        => (string) $row['body'],
			'url'         => (string) $row['url'],
			'author'      => (int) $row['author'],
			'author_name' => (string) $row['author_name'],

			/*
			 * Which side of the connection this came from. Published rather
			 * than inferred from the author being zero, because a screen
			 * showing "us" and "them" differently should read the fact rather
			 * than a side effect of one.
			 */
			'author_site' => (string) ( $row['author_site'] ?? '' ),
			'from_client' => '' !== (string) ( $row['author_site'] ?? '' ),
			'answers'     => (string) ( $row['answers'] ?? '' ),
			'created_at'  => (int) $row['created_at'],
		);
	}
}
