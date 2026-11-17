<?php
/**
 * A stand-in for the GitHub API, for the test suite only (#680).
 *
 * Copied into the test WordPress's mu-plugins directory by
 * tests/e2e/helpers/github.js before a spec that needs it, and removed
 * afterwards. Never shipped: tests/ is outside every build allowlist.
 *
 * Any token starting `good-` is accepted; any other is refused. The state is
 * in one option: `login` (who the token belongs to), `repos` (full names the
 * account owns), `hooks` (repo to the webhooks on it), `down` (GitHub cannot
 * be reached), `denied` (every token is refused), `hooks_denied` (the token
 * may not manage webhooks), `issues_refused` (GitHub will not make an issue),
 * `lose_create_answer` (GitHub makes the issue but its answer never arrives,
 * like a reply that times out), `issues` (each repo's issues as made),
 * `next_issue` (each repo's next issue number; each issue keeps its `labels`,
 * starting with `bug`, and its `state` and `state_reason`) and `calls`, a log of method,
 * path and body. `reset` clears the log and starts issue numbers at 1 again.
 *
 * It answers inside the same request rather than over HTTP: the suite blocks
 * outbound requests (tests/global-setup.js).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

const BWX_FORGE_GITHUB_STUB_OPTION = 'bwx_forge_github_stub';

/**
 * The stub's state.
 *
 * @return array<string, mixed>
 */
function bwx_forge_github_stub_state(): array {
	$state = get_option( BWX_FORGE_GITHUB_STUB_OPTION, array() );

	return array_merge(
		array(
			'login'              => 'blueworx-io',
			'repos'              => array(),
			'hooks'              => array(),
			'next_hook'          => 1000,
			'down'               => false,
			'denied'             => false,
			'hooks_denied'       => false,
			'issues_refused'     => false,
			'lose_create_answer' => false,
			'issues'             => array(),
			'comments'           => array(),
			'next_issue'         => array(),
			// Far above the ids the specs make up for their own links.
			'next_issue_id'      => 50000000,
			'calls'              => array(),
		),
		is_array( $state ) ? $state : array()
	);
}

/**
 * A response shaped the way wp_remote_* returns it.
 *
 * @param array<mixed>|null $body   JSON body, or null for none.
 * @param int               $status HTTP status.
 * @return array<string, mixed>
 */
function bwx_forge_github_stub_response( ?array $body, int $status = 200 ): array {
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => null === $body ? '' : (string) wp_json_encode( $body ),
		'response' => array(
			'code'    => $status,
			'message' => $status < 300 ? 'OK' : 'Error',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * A hook as GitHub shows it: the secret is never given back.
 *
 * @param array<string, mixed> $hook The hook as kept.
 * @return array<string, mixed>
 */
function bwx_forge_github_stub_shown( array $hook ): array {
	if ( isset( $hook['config']['secret'] ) ) {
		$hook['config']['secret'] = '********';
	}

	return $hook;
}

/**
 * Answers a call to a repo's webhooks.
 *
 * @param array<string, mixed> $state  The stub's state, changed in place.
 * @param string               $method The HTTP method.
 * @param string               $repo   owner/name.
 * @param int                  $id     The hook named in the path, or 0.
 * @param array<string, mixed> $sent   The decoded body.
 * @return array<string, mixed>
 */
function bwx_forge_github_stub_hooks( array &$state, string $method, string $repo, int $id, array $sent ): array {
	if ( $state['hooks_denied'] ) {
		return bwx_forge_github_stub_response( array( 'message' => 'Resource not accessible by personal access token' ), 403 );
	}

	if ( ! in_array( $repo, (array) $state['repos'], true ) ) {
		return bwx_forge_github_stub_response( array( 'message' => 'Not Found' ), 404 );
	}

	$hooks = array_values( (array) ( $state['hooks'][ $repo ] ?? array() ) );
	$at    = null;

	foreach ( $hooks as $index => $hook ) {
		if ( (int) $hook['id'] === $id ) {
			$at = $index;
		}
	}

	if ( 'GET' === $method && 0 === $id ) {
		return bwx_forge_github_stub_response( array_map( 'bwx_forge_github_stub_shown', $hooks ) );
	}

	if ( 'POST' === $method && 0 === $id ) {
		$hook = array(
			'id'     => (int) $state['next_hook'],
			'name'   => (string) ( $sent['name'] ?? '' ),
			'active' => (bool) ( $sent['active'] ?? false ),
			'events' => (array) ( $sent['events'] ?? array() ),
			'config' => (array) ( $sent['config'] ?? array() ),
		);

		++$state['next_hook'];
		$hooks[]                  = $hook;
		$state['hooks'][ $repo ] = $hooks;

		return bwx_forge_github_stub_response( bwx_forge_github_stub_shown( $hook ), 201 );
	}

	if ( null === $at ) {
		return bwx_forge_github_stub_response( array( 'message' => 'Not Found' ), 404 );
	}

	if ( 'PATCH' === $method ) {
		$hooks[ $at ]            = array_merge( $hooks[ $at ], array_intersect_key( $sent, array_flip( array( 'active', 'events', 'config' ) ) ) );
		$state['hooks'][ $repo ] = $hooks;

		return bwx_forge_github_stub_response( bwx_forge_github_stub_shown( $hooks[ $at ] ) );
	}

	if ( 'DELETE' === $method ) {
		unset( $hooks[ $at ] );
		$state['hooks'][ $repo ] = array_values( $hooks );

		return bwx_forge_github_stub_response( null, 204 );
	}

	return bwx_forge_github_stub_response( array( 'message' => 'Not Found' ), 404 );
}

/**
 * Makes an issue (#681): numbered from 1 in each repo, with an id of its own.
 *
 * @param array<string, mixed> $state The stub's state, changed in place.
 * @param string               $repo  owner/name.
 * @param array<string, mixed> $sent  The decoded body: title and body.
 * @return array<string, mixed>
 */
function bwx_forge_github_stub_issue( array &$state, string $repo, array $sent ): array {
	if ( $state['issues_refused'] ) {
		return bwx_forge_github_stub_response( array( 'message' => 'Issues are disabled for this repo' ), 410 );
	}

	if ( ! in_array( $repo, (array) $state['repos'], true ) ) {
		return bwx_forge_github_stub_response( array( 'message' => 'Not Found' ), 404 );
	}

	$number                       = (int) ( $state['next_issue'][ $repo ] ?? 1 );
	$state['next_issue'][ $repo ] = $number + 1;
	$id                           = (int) $state['next_issue_id'];
	$state['next_issue_id']       = $id + 1;
	$issue                        = array(
		'number'       => $number,
		'id'           => $id,
		'title'        => (string) ( $sent['title'] ?? '' ),
		'body'         => (string) ( $sent['body'] ?? '' ),
		'user'         => array( 'login' => (string) $state['login'] ),
		'html_url'     => 'https://github.com/' . $repo . '/issues/' . $number,
		// Someone in GitHub labelled it already (#682): Forge must leave this one alone.
		'labels'       => array( array( 'name' => 'bug' ) ),
		'state'        => 'open',
		'state_reason' => null,
	);
	$state['issues'][ $repo ][]   = $issue;

	return bwx_forge_github_stub_response( $issue, 201 );
}

/**
 * Where an issue is in a repo's list, or null (#682).
 *
 * @param array<string, mixed> $state  The stub's state.
 * @param string               $repo   owner/name.
 * @param int                  $number The issue's number.
 * @return int|null
 */
function bwx_forge_github_stub_find( array $state, string $repo, int $number ): ?int {
	foreach ( (array) ( $state['issues'][ $repo ] ?? array() ) as $index => $issue ) {
		if ( (int) $issue['number'] === $number ) {
			return (int) $index;
		}
	}

	return null;
}

/**
 * Answers a call about one issue (#682): read it, change its state, title or
 * body, add a label or take one off. `$label` is the label named in the path, still encoded.
 *
 * @param array<string, mixed> $state  The stub's state, changed in place.
 * @param string               $method The HTTP method.
 * @param string               $repo   owner/name.
 * @param int                  $number The issue's number.
 * @param string               $what   '' for the issue, 'labels' for its labels.
 * @param string               $label  The label named in the path, or ''.
 * @param array<string, mixed> $sent   The decoded body.
 * @return array<string, mixed>
 */
function bwx_forge_github_stub_one_issue( array &$state, string $method, string $repo, int $number, string $what, string $label, array $sent ): array {
	$at = bwx_forge_github_stub_find( $state, $repo, $number );

	if ( null === $at ) {
		return bwx_forge_github_stub_response( array( 'message' => 'Not Found' ), 404 );
	}

	$issue = $state['issues'][ $repo ][ $at ];

	if ( '' === $what && 'GET' === $method ) {
		return bwx_forge_github_stub_response( $issue );
	}

	if ( '' === $what && 'PATCH' === $method ) {
		if ( isset( $sent['state'] ) ) {
			$issue['state']        = (string) $sent['state'];
			$issue['state_reason'] = 'closed' === $issue['state'] ? ( $sent['state_reason'] ?? 'completed' ) : null;
		}

		foreach ( array( 'title', 'body' ) as $field ) {
			if ( isset( $sent[ $field ] ) ) {
				$issue[ $field ] = (string) $sent[ $field ];
			}
		}

		$state['issues'][ $repo ][ $at ] = $issue;

		return bwx_forge_github_stub_response( $issue );
	}

	$names = array_map( static fn( $each ): string => (string) $each['name'], (array) ( $issue['labels'] ?? array() ) );

	if ( 'labels' === $what && 'POST' === $method && '' === $label ) {
		foreach ( (array) ( $sent['labels'] ?? array() ) as $name ) {
			if ( ! in_array( (string) $name, $names, true ) ) {
				$names[] = (string) $name;
			}
		}
	} elseif ( 'labels' === $what && 'DELETE' === $method && '' !== $label ) {
		$name = rawurldecode( $label );

		if ( ! in_array( $name, $names, true ) ) {
			return bwx_forge_github_stub_response( array( 'message' => 'Label does not exist' ), 404 );
		}

		$names = array_values( array_diff( $names, array( $name ) ) );
	} else {
		return bwx_forge_github_stub_response( array( 'message' => 'Not Found' ), 404 );
	}

	$issue['labels']                 = array_map( static fn( string $name ): array => array( 'name' => $name ), $names );
	$state['issues'][ $repo ][ $at ] = $issue;

	return bwx_forge_github_stub_response( $issue['labels'] );
}

add_filter(
	'pre_http_request',
	/**
	 * Answers Forge's calls to GitHub before WordPress tries the network.
	 *
	 * @param false|array|WP_Error $answer What an earlier filter decided.
	 * @param array<string, mixed> $args   The request.
	 * @param string               $url    Where it was going.
	 * @return false|array|WP_Error
	 */
	static function ( $answer, array $args, string $url ) {
		if ( 0 !== strpos( $url, 'https://api.github.com/' ) ) {
			return $answer;
		}

		$state = bwx_forge_github_stub_state();

		if ( $state['down'] ) {
			return new WP_Error( 'http_request_failed', 'GitHub stand-in is down.' );
		}

		$method           = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$path             = (string) substr( $url, strlen( 'https://api.github.com' ) );
		$state['calls'][] = array(
			'method' => $method,
			'path'   => $path,
			'body'   => (string) ( $args['body'] ?? '' ),
		);

		if ( $state['denied'] || 0 !== strpos( (string) ( $args['headers']['Authorization'] ?? '' ), 'Bearer good-' ) ) {
			$answer = bwx_forge_github_stub_response( array( 'message' => 'Bad credentials' ), 401 );
		} elseif ( '/user' === $path ) {
			$answer = bwx_forge_github_stub_response( array( 'login' => (string) $state['login'] ) );
		} elseif ( 1 === preg_match( '#^/user/repos\?#', $path ) ) {
			$page = 1;

			if ( 1 === preg_match( '#[?&]page=(\d+)#', $path, $found ) ) {
				$page = (int) $found[1];
			}

			$answer = bwx_forge_github_stub_response(
				array_map(
					static fn( $name ): array => array( 'full_name' => (string) $name ),
					array_slice( (array) $state['repos'], ( $page - 1 ) * 100, 100 )
				)
			);
		} elseif ( 'POST' === $method && 1 === preg_match( '#^/repos/([^/]+/[^/?]+)/issues$#', $path, $found ) ) {
			$sent   = json_decode( (string) ( $args['body'] ?? '' ), true );
			$answer = bwx_forge_github_stub_issue( $state, strtolower( $found[1] ), is_array( $sent ) ? $sent : array() );

			// Made, but the answer never comes back: a reply that timed out.
			if ( $state['lose_create_answer'] && 201 === $answer['response']['code'] ) {
				update_option( BWX_FORGE_GITHUB_STUB_OPTION, $state, false );

				return new WP_Error( 'http_request_failed', 'Operation timed out.' );
			}
		} elseif ( 'GET' === $method && 1 === preg_match( '#^/repos/([^/]+/[^/?]+)/issues\?#', $path, $found ) ) {
			// Newest first, as GitHub lists them; the filters are not modelled.
			$answer = bwx_forge_github_stub_response( array_reverse( (array) ( $state['issues'][ strtolower( $found[1] ) ] ?? array() ) ) );
		} elseif ( 'POST' === $method && 1 === preg_match( '#^/repos/([^/]+/[^/?]+)/issues/(\d+)/comments$#', $path, $found ) ) {
			$sent    = json_decode( (string) ( $args['body'] ?? '' ), true );
			$comment = array(
				'id'   => (int) $state['next_issue_id']++,
				'body' => is_array( $sent ) ? (string) ( $sent['body'] ?? '' ) : '',
			);

			if ( in_array( strtolower( $found[1] ), (array) $state['repos'], true ) ) {
				$state['comments'][ strtolower( $found[1] ) . '#' . $found[2] ][] = $comment;
				$answer = bwx_forge_github_stub_response( $comment, 201 );
			} else {
				$answer = bwx_forge_github_stub_response( array( 'message' => 'Not Found' ), 404 );
			}
		} elseif ( 'GET' === $method && 1 === preg_match( '#^/repos/([^/]+/[^/?]+)/issues/(\d+)/comments\?#', $path, $found ) ) {
			$answer = bwx_forge_github_stub_response( array_values( (array) ( $state['comments'][ strtolower( $found[1] ) . '#' . $found[2] ] ?? array() ) ) );
		} elseif ( 1 === preg_match( '#^/repos/([^/]+/[^/?]+)/issues/(\d+)(?:/(labels)(?:/([^/?]+))?)?$#', $path, $found ) ) {
			$sent   = json_decode( (string) ( $args['body'] ?? '' ), true );
			$answer = bwx_forge_github_stub_one_issue( $state, $method, strtolower( $found[1] ), (int) $found[2], (string) ( $found[3] ?? '' ), (string) ( $found[4] ?? '' ), is_array( $sent ) ? $sent : array() );
		} elseif ( 1 === preg_match( '#^/repos/([^/]+/[^/?]+)/hooks(?:/(\d+))?(?:\?.*)?$#', $path, $found ) ) {
			$sent   = json_decode( (string) ( $args['body'] ?? '' ), true );
			$answer = bwx_forge_github_stub_hooks( $state, $method, strtolower( $found[1] ), (int) ( $found[2] ?? 0 ), is_array( $sent ) ? $sent : array() );
		} else {
			$answer = bwx_forge_github_stub_response( array( 'message' => 'Not Found' ), 404 );
		}

		update_option( BWX_FORGE_GITHUB_STUB_OPTION, $state, false );

		return $answer;
	},
	PHP_INT_MAX,
	3
);

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'bwx-forge-test/v1',
			'/github',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$body  = (array) $request->get_json_params();
					$state = bwx_forge_github_stub_state();

					foreach ( array( 'login', 'repos', 'hooks', 'down', 'denied', 'hooks_denied', 'issues_refused', 'lose_create_answer' ) as $field ) {
						if ( array_key_exists( $field, $body ) ) {
							$state[ $field ] = $body[ $field ];
						}
					}

					if ( ! empty( $body['reset'] ) ) {
						$state['calls']      = array();
						$state['next_issue'] = array();
						$state['issues']     = array();
						$state['comments']   = array();
					}

					update_option( BWX_FORGE_GITHUB_STUB_OPTION, $state, false );

					// GitHub has changed, so the pause Forge keeps after a failed
					// repo list is over, rather than each spec waiting it out.
					delete_transient( 'bwx_forge_github_repos_down' );

					return rest_ensure_response( $state );
				},
			)
		);

		register_rest_route(
			'bwx-forge-test/v1',
			'/github',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					return rest_ensure_response( bwx_forge_github_stub_state() );
				},
			)
		);

		// The secret Forge made when it connected. No real route ever gives
		// it out; the webhook specs need it to sign as GitHub would.
		register_rest_route(
			'bwx-forge-test/v1',
			'/github/secret',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					return rest_ensure_response( array( 'secret' => (string) Blueworx\Forge\GitHub\Settings::secret() ) );
				},
			)
		);

		// Runs the GitHub outbox, standing in for its five-minute cron (#681).
		// Every job still waiting is made due first, as if its wait had passed.
		register_rest_route(
			'bwx-forge-test/v1',
			'/github/run',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					global $wpdb;

					$table = Blueworx\Forge\Data\Schema::github_jobs_table();

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test-only stand-in for time passing.
					$wpdb->update( $table, array( 'run_after' => 0 ), array( 'status' => 'pending' ), array( '%d' ), array( '%s' ) );

					return rest_ensure_response( array( 'sent' => Blueworx\Forge\GitHub\Jobs::run() ) );
				},
			)
		);

		// An item's create_issue job as stored, so a spec can see its tries.
		register_rest_route(
			'bwx-forge-test/v1',
			'/github/job',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					global $wpdb;

					$table = Blueworx\Forge\Data\Schema::github_jobs_table();
					$id    = Blueworx\Forge\GitHub\Jobs::id_for( 'create_issue', (string) $request->get_param( 'item_id' ) );

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only read.
					return rest_ensure_response( array( 'job' => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A ) ) );
				},
			)
		);

		// Links an item to an issue without the flow that makes one, so a spec
		// can show a task that already has its issue (#681).
		register_rest_route(
			'bwx-forge-test/v1',
			'/github/link',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$body = (array) $request->get_json_params();

					return rest_ensure_response(
						array(
							'added' => Blueworx\Forge\GitHub\Links::add(
								(string) ( $body['item_id'] ?? '' ),
								(string) ( $body['repo'] ?? '' ),
								(int) ( $body['number'] ?? 0 ),
								(int) ( $body['issue_id'] ?? 0 ),
								'forge',
								'Stubbed',
								''
							),
						)
					);
				},
			)
		);
	}
);
