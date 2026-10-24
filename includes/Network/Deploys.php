<?php
/**
 * Creating a client's WordPress site on the network, a step at a time.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Network;

use Blueworx\Forge\Sites\Registry;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Integrations;
use Blueworx\Forge\Tenancy\Memberships;
use Blueworx\Forge\Tenancy\Roles;
use Blueworx\Forge\Tenancy\Users;
use WP_Error;

/**
 * #621. "Create site" on a Forge site with no WordPress site connected yet,
 * so the record that work, hours and onboarding already hang off is filled in
 * rather than duplicated. Three steps, each a signed call to the network, run
 * one after another by the app so it can show each as it happens:
 *
 * 1. **create** — the site, at the address and title an administrator gave;
 * 2. **link** — a key issued (or, on a retry, rotated) through the same
 *    Registry and Integrations every other site's key goes through, and sent
 *    to the new site so nobody copies it by hand;
 * 3. **people** — the client's admins and viewers, by email.
 *
 * What each step did is kept, so a step that fails leaves what is done in
 * place and the app offers to try again from that step.
 */
final class Deploys {

	/**
	 * Where each Forge site's deploy is kept.
	 */
	public const OPTION = 'bwx_forge_deploys';

	/**
	 * The steps, in order.
	 */
	public const STEPS = array( 'create', 'link', 'people' );

	/**
	 * Who gets a login on the new site, and as what.
	 *
	 * Client admins get Editor and Forge: Manager ('editor'); viewers get
	 * Forge: Manager alone ('manager'). The studio's own people get nothing —
	 * its network administrators already have access — and nor does anybody
	 * limited to another of the client's sites, or with no email.
	 *
	 * @param array<int, array<string, mixed>>    $memberships The client's active memberships.
	 * @param array<string, array<string, mixed>> $users       Forge users by id.
	 * @param string                              $site_id     The Forge site.
	 * @return array<int, array{email: string, name: string, access: string}>
	 */
	public static function people_from( array $memberships, array $users, string $site_id ): array {
		$access = array(
			Roles::CLIENT_ADMIN  => 'editor',
			Roles::CLIENT_VIEWER => 'manager',
		);
		$people = array();

		foreach ( $memberships as $membership ) {
			$role  = (string) ( $membership['role'] ?? '' );
			$limit = (string) ( $membership['client_site_id'] ?? '' );
			$user  = $users[ (string) ( $membership['user_id'] ?? '' ) ] ?? null;

			if ( ! isset( $access[ $role ] ) || ( '' !== $limit && $site_id !== $limit ) || null === $user || '' === (string) ( $user['email'] ?? '' ) ) {
				continue;
			}

			$people[] = array(
				'email'  => (string) $user['email'],
				'name'   => (string) ( $user['display_name'] ?? '' ),
				'access' => $access[ $role ],
			);
		}

		return $people;
	}

	/**
	 * One Forge site's deploy, as the app reads it.
	 *
	 * @param string $site_id The Forge site.
	 * @return array<string, mixed>
	 */
	public static function get( string $site_id ): array {
		$all  = self::all();
		$kept = (array) ( $all[ $site_id ] ?? array() );

		return array_merge(
			array(
				'slug'      => '',
				'title'     => '',
				'blog_id'   => 0,
				'url'       => '',
				'admin_url' => '',
				'steps'     => array(
					'create' => '',
					'link'   => '',
					'people' => '',
				),
				'error'     => '',
				'people'    => array(),
				'at'        => 0,
			),
			$kept
		);
	}

	/**
	 * Whether "Create site" is offered on a Forge site: the network is
	 * connected, the site is active, and it has no WordPress site connected —
	 * or it has one Forge is part-way through making.
	 *
	 * @param array<string, mixed> $site The Forge site.
	 * @return bool
	 */
	public static function available( array $site ): bool {
		if ( ! Settings::connected() || 'active' !== (string) ( $site['status'] ?? '' ) ) {
			return false;
		}

		$deploy = self::get( (string) $site['id'] );

		if ( '' !== $deploy['steps']['create'] ) {
			return 'done' !== $deploy['steps']['people'];
		}

		$integration = Integrations::for_site( (string) $site['id'] );

		return null === $integration || Integrations::KEY_ACTIVE !== (string) $integration['key_state'];
	}

	/**
	 * Runs one step.
	 *
	 * @param array<string, mixed> $site   The Forge site.
	 * @param string               $step   create, link or people.
	 * @param array<string, mixed> $input  slug and title, for create.
	 * @param int                  $author Who asked.
	 * @return array<string, mixed>|WP_Error The deploy after the step.
	 */
	public static function run( array $site, string $step, array $input, int $author ) {
		$site_id = (string) $site['id'];
		$deploy  = self::get( $site_id );

		if ( 'create' === $step ) {
			return self::create( $site, $deploy, $input );
		}

		if ( 'done' !== $deploy['steps']['create'] || $deploy['blog_id'] < 1 ) {
			return new WP_Error( 'bwx_forge_deploy_out_of_order', __( 'Create the site first.', 'blueworx-forge' ), array( 'status' => 409 ) );
		}

		if ( 'link' === $step ) {
			return self::link( $site, $deploy, $author );
		}

		if ( 'done' !== $deploy['steps']['link'] ) {
			return new WP_Error( 'bwx_forge_deploy_out_of_order', __( 'Link the site first.', 'blueworx-forge' ), array( 'status' => 409 ) );
		}

		return self::people( $site, $deploy );
	}

	/**
	 * Step one.
	 *
	 * @param array<string, mixed> $site   The Forge site.
	 * @param array<string, mixed> $deploy Its deploy so far.
	 * @param array<string, mixed> $input  slug and title.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function create( array $site, array $deploy, array $input ) {
		$slug  = strtolower( trim( (string) ( $input['slug'] ?? $deploy['slug'] ) ) );
		$title = trim( sanitize_text_field( (string) ( $input['title'] ?? $deploy['title'] ) ) );

		if ( 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug ) ) {
			return new WP_Error(
				'bwx_forge_deploy_invalid',
				__( 'Use lowercase letters, numbers and hyphens for the address, starting and ending with a letter or number.', 'blueworx-forge' ),
				array(
					'status' => 400,
					'fields' => array( 'slug' => __( 'Use lowercase letters, numbers and hyphens.', 'blueworx-forge' ) ),
				)
			);
		}

		if ( '' === $title || strlen( $title ) > 200 ) {
			return new WP_Error(
				'bwx_forge_deploy_invalid',
				__( 'Give the site a title.', 'blueworx-forge' ),
				array(
					'status' => 400,
					'fields' => array( 'title' => __( 'Give the site a title.', 'blueworx-forge' ) ),
				)
			);
		}

		// Once a site is made, its address is the one it has.
		if ( 'done' === $deploy['steps']['create'] ) {
			$slug  = (string) $deploy['slug'];
			$title = (string) $deploy['title'];
		}

		$deploy['slug']  = $slug;
		$deploy['title'] = $title;

		$answer = Client::call(
			'POST',
			'/network/sites',
			array(
				'slug'    => $slug,
				'title'   => $title,
				'site_id' => (string) $site['id'],
			)
		);

		if ( is_wp_error( $answer ) ) {
			return self::failed( (string) $site['id'], $deploy, 'create', $answer );
		}

		$deploy['blog_id']         = (int) ( $answer['blog_id'] ?? 0 );
		$deploy['url']             = esc_url_raw( (string) ( $answer['url'] ?? '' ) );
		$deploy['admin_url']       = esc_url_raw( (string) ( $answer['admin_url'] ?? '' ) );
		$deploy['steps']['create'] = $deploy['blog_id'] > 0 ? 'done' : 'failed';
		$deploy['error']           = $deploy['blog_id'] > 0 ? '' : __( 'The network did not say which site it made.', 'blueworx-forge' );

		return self::keep( (string) $site['id'], $deploy );
	}

	/**
	 * Step two.
	 *
	 * @param array<string, mixed> $site   The Forge site.
	 * @param array<string, mixed> $deploy Its deploy so far.
	 * @param int                  $author Who asked.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function link( array $site, array $deploy, int $author ) {
		$site_id     = (string) $site['id'];
		$integration = Integrations::ensure( $site_id, (string) $site['client_id'], $author );

		if ( null === $integration ) {
			return self::failed( $site_id, $deploy, 'link', new WP_Error( 'bwx_forge_deploy_link', __( 'The site\'s connection record could not be written.', 'blueworx-forge' ) ) );
		}

		// A retry rotates rather than issuing a second registration, so one
		// Forge site never has two keys that work.
		if ( Integrations::KEY_ACTIVE === (string) $integration['key_state'] && '' !== (string) $integration['registry_site_id'] ) {
			$registry_id = (string) $integration['registry_site_id'];
			$key         = Registry::rotate( $registry_id );
			$noted       = null === $key ? null : Integrations::note_key_rotated( $site_id );
		} else {
			$issued      = Registry::register( (string) $site['name'], (string) $deploy['url'] );
			$registry_id = $issued['site_id'];
			$key         = $issued['key'];
			$noted       = Integrations::note_key_issued( $site_id, $registry_id );
		}

		if ( null === $key || null === $noted ) {
			return self::failed( $site_id, $deploy, 'link', new WP_Error( 'bwx_forge_deploy_link', __( 'A key for the site could not be issued.', 'blueworx-forge' ) ) );
		}

		$answer = Client::call(
			'POST',
			'/network/sites/' . (int) $deploy['blog_id'] . '/link',
			array(
				'site_id'          => $site_id,
				'studio_url'       => untrailingslashit( home_url() ),
				'registry_site_id' => $registry_id,
				'key'              => $key,
			)
		);

		if ( is_wp_error( $answer ) ) {
			return self::failed( $site_id, $deploy, 'link', $answer );
		}

		// The Forge site takes the new site's address if it had none.
		if ( '' === trim( (string) ( $site['url'] ?? '' ) ) && '' !== $deploy['url'] ) {
			ClientSites::update( $site_id, array( 'url' => $deploy['url'] ), (int) $site['record_version'] );
		}

		$deploy['steps']['link'] = 'done';
		$deploy['error']         = '';

		return self::keep( $site_id, $deploy );
	}

	/**
	 * Step three.
	 *
	 * @param array<string, mixed> $site   The Forge site.
	 * @param array<string, mixed> $deploy Its deploy so far.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function people( array $site, array $deploy ) {
		$site_id     = (string) $site['id'];
		$memberships = Memberships::for_client( (string) $site['client_id'] );
		$users       = array();

		foreach ( $memberships as $membership ) {
			$user = Users::get( (string) $membership['user_id'] );

			if ( null !== $user && 'active' === (string) $user['status'] ) {
				$users[ (string) $user['id'] ] = $user;
			}
		}

		$answer = Client::call(
			'POST',
			'/network/sites/' . (int) $deploy['blog_id'] . '/people',
			array(
				'site_id' => $site_id,
				'people'  => self::people_from( $memberships, $users, $site_id ),
			)
		);

		if ( is_wp_error( $answer ) ) {
			return self::failed( $site_id, $deploy, 'people', $answer );
		}

		$outcomes = array();

		foreach ( (array) ( $answer['people'] ?? array() ) as $one ) {
			$outcomes[] = array(
				'email'   => sanitize_text_field( (string) ( $one['email'] ?? '' ) ),
				'outcome' => sanitize_key( (string) ( $one['outcome'] ?? '' ) ),
				'reason'  => sanitize_text_field( (string) ( $one['reason'] ?? '' ) ),
			);
		}

		$missed = array_filter(
			$outcomes,
			static function ( array $one ): bool {
				return 'failed' === $one['outcome'];
			}
		);

		$deploy['people']          = $outcomes;
		$deploy['steps']['people'] = array() === $missed ? 'done' : 'failed';
		$deploy['error']           = array() === $missed
			? ''
			/* translators: %s: the email addresses that could not be given a login. */
			: sprintf( __( 'Some logins could not be made: %s', 'blueworx-forge' ), implode( ', ', array_column( $missed, 'email' ) ) );

		return self::keep( $site_id, $deploy );
	}

	/**
	 * Records a failed step and hands its error back.
	 *
	 * @param string               $site_id The Forge site.
	 * @param array<string, mixed> $deploy  Its deploy so far.
	 * @param string               $step    The step.
	 * @param WP_Error             $error   What went wrong.
	 * @return WP_Error
	 */
	private static function failed( string $site_id, array $deploy, string $step, WP_Error $error ): WP_Error {
		$deploy['steps'][ $step ] = 'failed';
		$deploy['error']          = $error->get_error_message();
		self::keep( $site_id, $deploy );

		return new WP_Error(
			'bwx_forge_deploy_failed',
			$error->get_error_message(),
			array(
				'status' => 502,
				'step'   => $step,
				'deploy' => $deploy,
			)
		);
	}

	/**
	 * Saves a deploy.
	 *
	 * @param string               $site_id The Forge site.
	 * @param array<string, mixed> $deploy  The deploy.
	 * @return array<string, mixed>
	 */
	private static function keep( string $site_id, array $deploy ): array {
		$all             = self::all();
		$deploy['at']    = bwx_forge_now();
		$all[ $site_id ] = $deploy;

		update_option( self::OPTION, $all, false );

		return $deploy;
	}

	/**
	 * Every deploy.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function all(): array {
		$all = get_option( self::OPTION, array() );

		return is_array( $all ) ? $all : array();
	}
}
