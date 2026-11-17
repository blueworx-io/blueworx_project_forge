<?php
/**
 * The rule for choosing the repo a piece of work belongs in.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

/**
 * #681. Pure: the caller reads the connection and the repo list.
 */
final class RepoChoice {

	/**
	 * The answer that means no repo is wanted.
	 */
	public const NONE = 'none';

	/**
	 * Checks a chosen repo.
	 *
	 * @param string                  $value     Normalised: '', 'none' or owner/name.
	 * @param bool                    $connected Whether GitHub is connected.
	 * @param array<int, string>|null $repos     The account's repos, or null when
	 *                                           they could not be read.
	 * @return string|null An error message, or null when it is fine.
	 */
	public static function check( string $value, bool $connected, ?array $repos ): ?string {
		if ( '' === $value || self::NONE === $value ) {
			return null;
		}

		if ( ! $connected ) {
			return 'Connect GitHub before choosing a repo, or choose No repo needed.';
		}

		// GitHub being down is not a reason to stop a save: only a list we
		// actually read can say a repo is not the account's.
		if ( null !== $repos && ! in_array( $value, $repos, true ) ) {
			return 'That repo isn\'t one of the connected GitHub account\'s repos.';
		}

		return null;
	}
}
