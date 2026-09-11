<?php
/**
 * What the client app sends.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Rest;

use Blueworx\Forge\Client\Admin\AskActions;
use Blueworx\Forge\Client\Admin\ChecklistActions;
use Blueworx\Forge\Client\ChecklistAnswer;
use Blueworx\Forge\Client\Connection;
use Blueworx\Forge\Client\Discussion;
use Blueworx\Forge\Client\Submission;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The write routes the client app is built on (#298): a comment or evidence
 * on an item, a new submission, and an answer or a file on a checklist step.
 *
 * Each route hands the same values to the same class the wp-admin form for
 * it already hands them to, and hands back that class's answer as it is —
 * `ok`, a `result` the screen can name, and whatever else the class returns.
 * The app never learns a way to do something the form cannot, and the two
 * cannot drift: there is one sender per thing, and this is only a second
 * door to it.
 *
 * There is no purchase route. Nothing on the client side buys anything today
 * — packages are set up by the studio — so there is nothing here to wrap.
 */
final class WriteController {

	/**
	 * Registers the routes.
	 */
	public static function register_routes(): void {
		$guard = array( WorkspaceController::class, 'can_manage' );

		register_rest_route(
			WorkspaceController::NAMESPACE,
			'/items/(?P<item>[A-Za-z0-9_-]+)/discussion',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'read_discussion' ),
					'permission_callback' => $guard,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'add_to_discussion' ),
					'permission_callback' => $guard,
					'args'                => array(
						'body'    => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'url'     => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'esc_url_raw',
						),
						'answers' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			WorkspaceController::NAMESPACE,
			'/submissions',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'submit' ),
				'permission_callback' => $guard,
				'args'                => array(
					'type'            => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'title'           => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'description'     => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'desired_outcome' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'evidence'        => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			WorkspaceController::NAMESPACE,
			'/checklist/(?P<step>[A-Za-z0-9_-]+)/answer',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'answer_step' ),
				'permission_callback' => $guard,
				'args'                => array(
					'response' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'intent'   => array(
						'type'     => 'string',
						'required' => false,
						'default'  => 'save',
						'enum'     => array( 'save', 'submit' ),
					),
				),
			)
		);

		register_rest_route(
			WorkspaceController::NAMESPACE,
			'/checklist/(?P<step>[A-Za-z0-9_-]+)/evidence',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'attach_to_step' ),
				'permission_callback' => $guard,
			)
		);
	}

	/**
	 * The discussion on one item.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public static function read_discussion( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( Discussion::view( (string) $request['item'], true ) );
	}

	/**
	 * A comment, or a piece of evidence when a URL comes with it — the same
	 * rule ItemActions applies.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public static function add_to_discussion( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Connection::is_configured() ) {
			return self::not_connected();
		}

		$url = (string) $request->get_param( 'url' );

		return rest_ensure_response(
			Discussion::add(
				(string) $request['item'],
				array(
					'kind'        => '' === $url ? 'comment' : 'evidence',
					'body'        => (string) $request->get_param( 'body' ),
					'url'         => $url,
					'answers'     => (string) $request->get_param( 'answers' ),
					'author_name' => self::who(),
				)
			)
		);
	}

	/**
	 * A bug, request, idea or suggestion, with a screenshot if one was sent.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public static function submit( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Connection::is_configured() ) {
			return self::not_connected();
		}

		$sent = array(
			'type'            => (string) $request->get_param( 'type' ),
			'title'           => (string) $request->get_param( 'title' ),
			'description'     => (string) $request->get_param( 'description' ),
			'desired_outcome' => (string) $request->get_param( 'desired_outcome' ),
			'evidence'        => (string) $request->get_param( 'evidence' ),
			'submitted_by'    => self::who(),
		);

		$screenshot = AskActions::screenshot_url();

		if ( '' !== $screenshot ) {
			$sent['evidence'] = trim(
				$sent['evidence'] . "\n\n" . sprintf(
					/* translators: %s: the uploaded screenshot's URL. */
					__( 'Screenshot: %s', 'blueworx-forge' ),
					$screenshot
				)
			);
		}

		return rest_ensure_response( Submission::send( $sent ) );
	}

	/**
	 * An answer on a checklist step, kept or handed over to the studio.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public static function answer_step( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Connection::is_configured() ) {
			return self::not_connected();
		}

		return rest_ensure_response(
			ChecklistAnswer::send(
				(string) $request['step'],
				array( 'response' => (string) $request->get_param( 'response' ) ),
				'submit' === $request->get_param( 'intent' ) ? ChecklistActions::HANDING_OVER : ''
			)
		);
	}

	/**
	 * A file on a checklist step, sent as multipart under `evidence`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public static function attach_to_step( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Connection::is_configured() ) {
			return self::not_connected();
		}

		$file = ChecklistActions::uploaded();

		if ( array() === $file ) {
			return rest_ensure_response(
				array(
					'ok'     => false,
					'result' => 'no_file',
				)
			);
		}

		return rest_ensure_response( ChecklistAnswer::attach( (string) $request['step'], $file ) );
	}

	/**
	 * The answer every write gives before a studio is connected.
	 */
	private static function not_connected(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'ok'     => false,
				'result' => 'not_connected',
			)
		);
	}

	/**
	 * Who is sending, as the studio will show it.
	 */
	private static function who(): string {
		$user = wp_get_current_user();

		return $user instanceof \WP_User ? (string) $user->display_name : '';
	}
}
