<?php
/**
 * Record ids.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * One id shape for every canonical record: a short prefix saying what the
 * record is, then 64 bits of randomness.
 *
 * Random rather than sequential, for the reason Sites\Registry gives about site
 * ids: an id appears in URLs and logs, and a sequential one advertises how many
 * clients exist and lets a caller guess the next. The prefix is there so an id
 * in a log line or a support message says what it belongs to without a lookup.
 */
final class Ids {

	/**
	 * A new id under a prefix.
	 *
	 * @param string $prefix Short record-type prefix, e.g. 'cli'.
	 * @return string
	 */
	public static function create( string $prefix ): string {
		return $prefix . '_' . bin2hex( random_bytes( 8 ) );
	}
}
