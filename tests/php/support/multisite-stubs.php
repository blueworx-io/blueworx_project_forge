<?php
/**
 * Stubs of WordPress's multisite functions, for the network route's units
 * (#621). An in-memory network: sites, users, who is on which site with which
 * roles, and the emails sent. Loaded by the tests that need it, not by every
 * test, because no other unit runs on a network.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

/**
 * Puts the stub network back to empty: one main site, one super admin.
 *
 * @param bool $multisite  Whether this is a network at all.
 * @param bool $subdomains Whether sites are subdomains.
 */
function bwx_forge_test_network( bool $multisite = true, bool $subdomains = false ): void {
	$GLOBALS['bwx_forge_test_net'] = array(
		'multisite'  => $multisite,
		'subdomains' => $subdomains,
		'domain'     => 'network.test',
		'path'       => '/',
		'sites'      => array(
			1 => array(
				'domain'  => 'network.test',
				'path'    => '/',
				'title'   => 'Network',
				'options' => array(),
			),
		),
		'users'      => array(
			1 => array(
				'login' => 'netadmin',
				'email' => 'netadmin@network.test',
			),
		),
		'roles'      => array(),
		'mail'       => array(),
		'blog'       => 1,
		'role_made'  => array(),
	);
}

bwx_forge_test_network();

/**
 * Stub.
 *
 * @return bool
 */
function is_multisite(): bool {
	return (bool) $GLOBALS['bwx_forge_test_net']['multisite'];
}

/**
 * Stub.
 *
 * @return bool
 */
function is_subdomain_install(): bool {
	return (bool) $GLOBALS['bwx_forge_test_net']['subdomains'];
}

/**
 * Stub: the network, with only the parts the route reads.
 *
 * @return object
 */
function get_network() {
	return (object) array(
		'domain' => $GLOBALS['bwx_forge_test_net']['domain'],
		'path'   => $GLOBALS['bwx_forge_test_net']['path'],
	);
}

/**
 * Stub.
 *
 * @return array<int, string>
 */
function get_super_admins(): array {
	return array( 'netadmin' );
}

/**
 * Stub.
 *
 * @param string $domain Domain.
 * @param string $path   Path.
 * @return int 0 when there is none.
 */
function get_blog_id_from_url( string $domain, string $path = '/' ): int {
	foreach ( $GLOBALS['bwx_forge_test_net']['sites'] as $id => $site ) {
		if ( $site['domain'] === $domain && $site['path'] === $path ) {
			return (int) $id;
		}
	}

	return 0;
}

/**
 * Stub.
 *
 * @param array<string, mixed> $data Site data.
 * @return int|WP_Error
 */
function wp_insert_site( array $data ) {
	$sites = &$GLOBALS['bwx_forge_test_net']['sites'];
	$id    = max( array_keys( $sites ) ) + 1;

	$sites[ $id ] = array(
		'domain'  => (string) $data['domain'],
		'path'    => (string) $data['path'],
		'title'   => (string) ( $data['title'] ?? '' ),
		'options' => (array) ( $data['options'] ?? array() ),
	);

	// The owner becomes the site's administrator, as wp_initialize_site does.
	$GLOBALS['bwx_forge_test_net']['roles'][ $id ][ (int) $data['user_id'] ] = array( 'administrator' );

	return $id;
}

/**
 * Stub.
 *
 * @param int    $blog_id Site.
 * @param string $option  Option.
 * @param mixed  $fallback Default.
 * @return mixed
 */
function get_blog_option( int $blog_id, string $option, $fallback = false ) {
	return $GLOBALS['bwx_forge_test_net']['sites'][ $blog_id ]['options'][ $option ] ?? $fallback;
}

/**
 * Stub.
 *
 * @param int    $blog_id Site.
 * @param string $option  Option.
 * @param mixed  $value   Value.
 * @return bool
 */
function update_blog_option( int $blog_id, string $option, $value ): bool {
	$GLOBALS['bwx_forge_test_net']['sites'][ $blog_id ]['options'][ $option ] = $value;

	return true;
}

/**
 * Stub.
 *
 * @param int $blog_id Site.
 * @return string
 */
function get_home_url( int $blog_id ): string {
	$site = $GLOBALS['bwx_forge_test_net']['sites'][ $blog_id ];

	return 'https://' . $site['domain'] . rtrim( $site['path'], '/' );
}

/**
 * Stub.
 *
 * @param int    $blog_id Site.
 * @param string $path    Path.
 * @return string
 */
function get_admin_url( int $blog_id, string $path = '' ): string {
	return get_home_url( $blog_id ) . '/wp-admin/' . $path;
}

/**
 * Stub.
 *
 * @param int $blog_id Site.
 * @return bool
 */
function switch_to_blog( int $blog_id ): bool {
	$GLOBALS['bwx_forge_test_net']['previous'] = $GLOBALS['bwx_forge_test_net']['blog'];
	$GLOBALS['bwx_forge_test_net']['blog']     = $blog_id;

	return true;
}

/**
 * Stub.
 *
 * @return bool
 */
function restore_current_blog(): bool {
	$GLOBALS['bwx_forge_test_net']['blog'] = $GLOBALS['bwx_forge_test_net']['previous'] ?? 1;

	return true;
}

/**
 * Stub.
 *
 * @param string $field Only 'email'.
 * @param string $value The email.
 * @return object|false
 */
function get_user_by( string $field, string $value ) {
	foreach ( $GLOBALS['bwx_forge_test_net']['users'] as $id => $user ) {
		if ( 'email' === $field && strtolower( $user['email'] ) === strtolower( $value ) ) {
			return (object) array(
				'ID'         => (int) $id,
				'user_login' => $user['login'],
			);
		}

		if ( 'login' === $field && $user['login'] === $value ) {
			return (object) array(
				'ID'         => (int) $id,
				'user_login' => $user['login'],
			);
		}
	}

	return false;
}

/**
 * Stub.
 *
 * @param string $login    Login.
 * @param string $password Password.
 * @param string $email    Email.
 * @return int|false
 */
function wpmu_create_user( string $login, string $password, string $email ) {
	unset( $password );
	$users = &$GLOBALS['bwx_forge_test_net']['users'];
	$id    = max( array_keys( $users ) ) + 1;

	$users[ $id ] = array(
		'login' => $login,
		'email' => $email,
	);

	return $id;
}

/**
 * Stub.
 *
 * @param string $username Username.
 * @return string
 */
function sanitize_user( string $username ): string {
	return strtolower( (string) preg_replace( '/[^A-Za-z0-9_.\-@]/', '', $username ) );
}

/**
 * Stub.
 *
 * @param int    $blog_id Site.
 * @param int    $user_id User.
 * @param string $role    Role.
 * @return bool
 */
function add_user_to_blog( int $blog_id, int $user_id, string $role ): bool {
	$GLOBALS['bwx_forge_test_net']['roles'][ $blog_id ][ $user_id ] = array( $role );

	return true;
}

/**
 * Stub.
 *
 * @param int    $user_id User.
 * @param string $role    Role to add on the current site.
 */
function bwx_forge_test_add_role( int $user_id, string $role ): void {
	$blog = $GLOBALS['bwx_forge_test_net']['blog'];

	$GLOBALS['bwx_forge_test_net']['roles'][ $blog ][ $user_id ][] = $role;
}

/**
 * Stub of WP_User, with only add_role.
 */
if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * Stub.
	 */
	class WP_User {

		/**
		 * The user's id.
		 *
		 * @var int
		 */
		public $ID;

		/**
		 * Stub.
		 *
		 * @param int $id User id.
		 */
		public function __construct( int $id ) {
			$this->ID = $id;
		}

		/**
		 * Stub.
		 *
		 * @param string $role Role.
		 */
		public function add_role( string $role ): void {
			bwx_forge_test_add_role( $this->ID, $role );
		}
	}
}

/**
 * Stub: records the set-password email.
 *
 * @param int         $user_id User.
 * @param string|null $deprecated Unused.
 * @param string      $notify  Who is told.
 */
function wp_new_user_notification( int $user_id, $deprecated = null, string $notify = '' ): void {
	unset( $deprecated );
	$GLOBALS['bwx_forge_test_net']['mail'][] = array(
		'user'   => $user_id,
		'notify' => $notify,
	);
}

/**
 * Stub.
 *
 * @param int  $length        Length.
 * @param bool $special_chars Special characters.
 * @param bool $extra_special Extra special characters.
 * @return string
 */
function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special = false ): string {
	unset( $special_chars, $extra_special );

	return bin2hex( random_bytes( (int) ceil( $length / 2 ) ) );
}

/**
 * Stub: no role exists until add_role makes it.
 *
 * @param string $role Role.
 * @return null
 */
function get_role( string $role ) {
	unset( $role );

	return null;
}

/**
 * Stub: records which site a role was made on.
 *
 * @param string               $role         Role.
 * @param string               $display_name Name.
 * @param array<string, mixed> $capabilities Capabilities.
 * @return null
 */
function add_role( string $role, string $display_name, array $capabilities = array() ) {
	unset( $display_name, $capabilities );
	$GLOBALS['bwx_forge_test_net']['role_made'][ $GLOBALS['bwx_forge_test_net']['blog'] ][] = $role;

	return null;
}

$GLOBALS['bwx_forge_test_site_options']    = array();
$GLOBALS['bwx_forge_test_site_transients'] = array();

/**
 * Stub.
 *
 * @param string $option   Option.
 * @param mixed  $fallback Default.
 * @return mixed
 */
function get_site_option( string $option, $fallback = false ) {
	return $GLOBALS['bwx_forge_test_site_options'][ $option ] ?? $fallback;
}

/**
 * Stub.
 *
 * @param string $option Option.
 * @param mixed  $value  Value.
 * @return bool
 */
function update_site_option( string $option, $value ): bool {
	$GLOBALS['bwx_forge_test_site_options'][ $option ] = $value;

	return true;
}

/**
 * Stub.
 *
 * @param string $transient Transient.
 * @return mixed
 */
function get_site_transient( string $transient ) {
	return $GLOBALS['bwx_forge_test_site_transients'][ $transient ] ?? false;
}

/**
 * Stub.
 *
 * @param string $transient  Transient.
 * @param mixed  $value      Value.
 * @param int    $expiration Lifetime.
 * @return bool
 */
function set_site_transient( string $transient, $value, int $expiration = 0 ): bool {
	unset( $expiration );
	$GLOBALS['bwx_forge_test_site_transients'][ $transient ] = $value;

	return true;
}
