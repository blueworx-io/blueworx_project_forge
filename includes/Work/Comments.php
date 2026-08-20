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
	 * What a record on an item can be.
	 */
	public const KINDS = array( self::COMMENT, self::EVIDENCE, self::ATTACHMENT );

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
	 * Adds a comment, a piece of evidence or an attachment.
	 *
	 * @param array<string, mixed> $entry item_id, client_site_id, client_id,
	 *                                    kind, visibility, body, url, author,
	 *                                    author_name.
	 * @param string               $scope The author's reading scope.
	 * @return array<string, mixed>|null Null when it was refused.
	 */
	public static function add( array $entry, string $scope = self::SCOPE_STAFF ): ?array {
		global $wpdb;

		$author = (int) ( $entry['author'] ?? 0 );
		$body   = trim( (string) ( $entry['body'] ?? '' ) );
		$url    = trim( (string) ( $entry['url'] ?? '' ) );
		$kind   = (string) ( $entry['kind'] ?? self::COMMENT );

		$visibility = self::visibility_of( (string) ( $entry['visibility'] ?? self::INTERNAL ), $scope );

		if ( $author <= 0 || ! in_array( $kind, self::KINDS, true ) ) {
			return null;
		}

		// Evidence with nothing to look at is a comment claiming to be evidence.
		if ( self::COMMENT !== $kind && '' === $url ) {
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
	 * @param string $requested What the caller asked for.
	 * @param string $scope     The author's scope.
	 * @return string
	 */
	public static function visibility_of( string $requested, string $scope ): string {
		if ( self::SCOPE_CLIENT === $scope ) {
			// A client cannot write an internal note. There is nowhere for one
			// of theirs to be internal *to*.
			return self::CLIENT;
		}

		return in_array( $requested, self::VISIBILITIES, true ) ? $requested : self::INTERNAL;
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
			'created_at'  => (int) $row['created_at'],
		);
	}
}
