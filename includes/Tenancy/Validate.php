<?php
/**
 * Input rules for clients and client sites.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * Plain PHP, no WordPress. Two doors reach these records — the REST routes and
 * the admin screen — and both have to refuse the same input for the same
 * reason, so the rules live in one place that can be tested without a site
 * around it.
 *
 * Every method answers the same shape: the cleaned values, and a map of field
 * name to message for anything refused. An empty error map means the input is
 * usable as it stands.
 */
final class Validate {

	/**
	 * The only statuses a record may hold. There is no deleted state: records
	 * are deactivated and kept, so history and reporting still resolve (NOTIF-5).
	 */
	public const STATUSES = array( 'active', 'inactive' );

	/**
	 * Longest a name may be, matching the varchar(191) it lands in.
	 */
	public const MAX_NAME = 191;

	/**
	 * The only answers a site may give about its ability to send mail. Screens
	 * switch on this, so anything else must not reach the column.
	 */
	public const MAIL_STATES = array( 'unknown', 'yes', 'no' );

	/**
	 * Checks a client.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param bool                 $partial True for an edit, which may mention
	 *                                      only the fields it changes.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function client( array $input, bool $partial ): array {
		$values = array();
		$errors = array();

		if ( ! $partial || array_key_exists( 'display_name', $input ) ) {
			$name = trim( (string) ( $input['display_name'] ?? '' ) );

			if ( '' === $name ) {
				$errors['display_name'] = 'A client needs a name.';
			} elseif ( mb_strlen( $name ) > self::MAX_NAME ) {
				$errors['display_name'] = 'That name is too long.';
			} else {
				$values['display_name'] = $name;
			}
		}

		if ( array_key_exists( 'legal_name', $input ) ) {
			$values['legal_name'] = trim( (string) $input['legal_name'] );
		}

		if ( array_key_exists( 'timezone', $input ) ) {
			$timezone = trim( (string) $input['timezone'] );

			if ( ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
				$errors['timezone'] = 'That is not a timezone.';
			} else {
				$values['timezone'] = $timezone;
			}
		} elseif ( ! $partial ) {
			$values['timezone'] = 'UTC';
		}

		if ( array_key_exists( 'email_domains', $input ) ) {
			$domains = self::domains( $input['email_domains'] );

			if ( null === $domains ) {
				$errors['email_domains'] = 'Each permitted domain is a domain on its own, such as acme.co.uk.';
			} else {
				$values['email_domains'] = $domains;
			}
		} elseif ( ! $partial ) {
			$values['email_domains'] = array();
		}

		$status = self::status( $input, $partial );

		if ( null === $status['error'] ) {
			if ( null !== $status['value'] ) {
				$values['status'] = $status['value'];
			}
		} else {
			$errors['status'] = $status['error'];
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Checks a client site.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param bool                 $partial True for an edit.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function site( array $input, bool $partial ): array {
		$values = array();
		$errors = array();

		if ( ! $partial || array_key_exists( 'name', $input ) ) {
			$name = trim( (string) ( $input['name'] ?? '' ) );

			if ( '' === $name ) {
				$errors['name'] = 'A site needs a name.';
			} elseif ( mb_strlen( $name ) > self::MAX_NAME ) {
				$errors['name'] = 'That name is too long.';
			} else {
				$values['name'] = $name;
			}
		}

		if ( array_key_exists( 'url', $input ) ) {
			$url = trim( (string) $input['url'] );

			if ( '' !== $url && ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				$errors['url'] = 'That is not a web address.';
			} else {
				$values['url'] = $url;
			}
		} elseif ( ! $partial ) {
			$values['url'] = '';
		}

		$status = self::status( $input, $partial );

		if ( null === $status['error'] ) {
			if ( null !== $status['value'] ) {
				$values['status'] = $status['value'];
			}
		} else {
			$errors['status'] = $status['error'];
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Checks a user (#90).
	 *
	 * The email is lower-cased rather than merely accepted. AUTH-6 makes one
	 * person one account, and the unique index behind this column only sees two
	 * spellings of one address as one person if they are stored the same way.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param bool                 $partial True for an edit.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function user( array $input, bool $partial ): array {
		$values = array();
		$errors = array();

		if ( ! $partial || array_key_exists( 'email', $input ) ) {
			$email = strtolower( trim( (string) ( $input['email'] ?? '' ) ) );

			if ( '' === $email ) {
				$errors['email'] = 'A person needs an email address.';
			} elseif ( ! is_email( $email ) || mb_strlen( $email ) > self::MAX_NAME ) {
				$errors['email'] = 'That is not an email address we can use.';
			} else {
				$values['email'] = $email;
			}
		}

		if ( ! $partial || array_key_exists( 'display_name', $input ) ) {
			$name = trim( (string) ( $input['display_name'] ?? '' ) );

			if ( '' === $name ) {
				$errors['display_name'] = 'A person needs a name.';
			} elseif ( mb_strlen( $name ) > self::MAX_NAME ) {
				$errors['display_name'] = 'That name is too long.';
			} else {
				$values['display_name'] = $name;
			}
		}

		if ( array_key_exists( 'wp_user_id', $input ) ) {
			$values['wp_user_id'] = max( 0, (int) $input['wp_user_id'] );
		} elseif ( ! $partial ) {
			$values['wp_user_id'] = 0;
		}

		$status = self::status( $input, $partial );

		if ( null === $status['error'] ) {
			if ( null !== $status['value'] ) {
				$values['status'] = $status['value'];
			}
		} else {
			$errors['status'] = $status['error'];
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Checks a membership (#90).
	 *
	 * Whether the named site actually belongs to the named client is not decided
	 * here — that needs the database. It is the caller's next check, and the one
	 * this milestone exists for.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param bool                 $partial True for an edit.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function membership( array $input, bool $partial ): array {
		$values = array();
		$errors = array();

		if ( ! $partial || array_key_exists( 'role', $input ) ) {
			$role = trim( (string) ( $input['role'] ?? '' ) );

			if ( ! Roles::exists( $role ) ) {
				$errors['role'] = 'That is not one of the access roles.';
			} else {
				$values['role'] = $role;
			}
		}

		if ( array_key_exists( 'client_site_id', $input ) ) {
			// An empty site is a real answer — every site under the client — so
			// it is stored rather than treated as a missing field.
			$values['client_site_id'] = trim( (string) $input['client_site_id'] );
		} elseif ( ! $partial ) {
			$values['client_site_id'] = '';
		}

		$status = self::status( $input, $partial );

		if ( null === $status['error'] ) {
			if ( null !== $status['value'] ) {
				$values['status'] = $status['value'];
			}
		} else {
			$errors['status'] = $status['error'];
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Whether a person's address is allowed to hold this role on this client.
	 *
	 * #88 stored each client's permitted domains for exactly this. The rule
	 * applies to the client's own people only: a staff membership on that client
	 * is one of ours, on our domain, and the client's list is not about us.
	 *
	 * The match is exact. A subdomain of a permitted domain is not the permitted
	 * domain — a rule that lets anybody who can create a hostname under a
	 * lookalike through is not worth having.
	 *
	 * @param string             $email   The person's address, already lower-cased.
	 * @param string             $role    The role they would hold.
	 * @param array<int, string> $domains The client's permitted domains.
	 * @return string|null The refusal, or null when there is nothing to refuse.
	 */
	public static function domain_error( string $email, string $role, array $domains ): ?string {
		if ( array() === $domains || ! Roles::is_client_side( $role ) ) {
			return null;
		}

		$at = strrpos( $email, '@' );

		if ( false === $at ) {
			return 'That is not an email address we can use.';
		}

		$domain = strtolower( substr( $email, $at + 1 ) );

		foreach ( $domains as $permitted ) {
			if ( strtolower( (string) $permitted ) === $domain ) {
				return null;
			}
		}

		return 'That client only accepts people at: ' . implode( ', ', $domains ) . '.';
	}

	/**
	 * Checks a client site's report about itself (#89).
	 *
	 * Unlike the two above, this input comes from a machine rather than from
	 * somebody typing, and it writes to a record the studio owns. So the rules
	 * are different in two ways. The fields are a closed list — a signed request
	 * proves which site is calling, not that it may choose which columns to
	 * touch — and an over-long value is trimmed rather than refused, because a
	 * site running some unusual build should still get its mail capability
	 * recorded rather than having the whole report bounced over a version
	 * string.
	 *
	 * @param array<string, mixed> $input Raw report.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function report( array $input ): array {
		$lengths = array(
			'home_url'       => 255,
			'wp_version'     => 32,
			'php_version'    => 32,
			'plugin_version' => 32,
			'mail_detail'    => 191,
		);

		$values = array();
		$errors = array();

		foreach ( $lengths as $field => $limit ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$values[ $field ] = mb_substr( trim( (string) $input[ $field ] ), 0, $limit );
		}

		if ( array_key_exists( 'mail_capable', $input ) ) {
			$capable = trim( (string) $input['mail_capable'] );

			if ( ! in_array( $capable, self::MAIL_STATES, true ) ) {
				$errors['mail_capable'] = 'A site can send mail, cannot, or has not said.';
			} else {
				$values['mail_capable'] = $capable;
			}
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * The status rule, which is the same for both records.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param bool                 $partial True for an edit.
	 * @return array{value: string|null, error: string|null}
	 */
	private static function status( array $input, bool $partial ): array {
		if ( ! array_key_exists( 'status', $input ) ) {
			return array(
				'value' => $partial ? null : 'active',
				'error' => null,
			);
		}

		$status = trim( (string) $input['status'] );

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return array(
				'value' => null,
				'error' => 'A record is either active or inactive.',
			);
		}

		return array(
			'value' => $status,
			'error' => null,
		);
	}

	/**
	 * Cleans a list of permitted email domains.
	 *
	 * Lower-cased and de-duplicated, because two spellings of one domain are one
	 * rule and storing both would let a later membership check pass on the
	 * spelling nobody remembered to remove.
	 *
	 * @param mixed $raw Whatever arrived in the field.
	 * @return array<int, string>|null Null when any entry is not a domain.
	 */
	private static function domains( $raw ): ?array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		}

		if ( ! is_array( $raw ) ) {
			return null;
		}

		$clean = array();

		foreach ( $raw as $entry ) {
			$domain = strtolower( trim( (string) $entry ) );

			if ( '' === $domain ) {
				continue;
			}

			if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain ) ) {
				return null;
			}

			$clean[] = $domain;
		}

		return array_values( array_unique( $clean ) );
	}
}
