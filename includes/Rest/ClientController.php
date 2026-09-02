<?php
/**
 * Routes a registered client site may call.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Capacity\ClientAnswer;
use Blueworx\Forge\Notifications\Log as NotificationLog;
use Blueworx\Forge\Notifications\Outbox;
use Blueworx\Forge\Notifications\Register as Notifications;
use Blueworx\Forge\Onboarding\Answers;
use Blueworx\Forge\Onboarding\Evidence;
use Blueworx\Forge\Onboarding\Progress;
use Blueworx\Forge\Onboarding\Review;
use Blueworx\Forge\Onboarding\StepEvents;
use Blueworx\Forge\Onboarding\Steps;
use Blueworx\Forge\Sites\Registry;
use Blueworx\Forge\Sites\SecurityLog;
use Blueworx\Forge\Work\ClientView;
use Blueworx\Forge\Work\Comments;
use Blueworx\Forge\Work\Contributions;
use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\Stages;
use Blueworx\Forge\Work\Submissions;
use Blueworx\Forge\Work\Validate as WorkValidate;
use Blueworx\Forge\Tenancy\Capabilities;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Contacts;
use Blueworx\Forge\Tenancy\Integrations;
use Blueworx\Forge\Tenancy\Roles;
use Blueworx\Forge\Tenancy\Users;
use Blueworx\Forge\Tenancy\Validate;
use WP_REST_Request;
use WP_REST_Response;

/**
 * What a client site can ask the studio, once it has proved which client it is.
 *
 * Two routes: the handshake, which answers "yes, you are this site", and the
 * workspace, which is the first canonical record a client site renders without
 * holding a copy of it (ARCH-2).
 *
 * Every route here uses Permissions::client_site, never a capability check: the
 * caller is a machine, not a logged-in user.
 */
final class ClientController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/client/handshake',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handshake' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client/report',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'report' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);

		/*
		 * The client site's outbox (#173). It asks what it should send, sends
		 * it with its own `wp_mail`, and says what happened.
		 *
		 * Two routes rather than one that does both, because the two are
		 * separated by the send itself and by whatever the client's mail
		 * provider does in between. A single call would have to hold a
		 * connection open across all of that, and a timed-out request would
		 * leave the studio not knowing whether the emails went.
		 */
		Server::register_route(
			$route_namespace,
			'/client/notifications',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'notifications' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, and only that site\'s pending emails are answered (ARCH-6).',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client/notifications',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'notified' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, and an outcome is only accepted for an event raised for that site (ARCH-6).',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client/workspace',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'workspace' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client/board',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'board' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'availability' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client/submissions',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'submissions' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client/submissions',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'submit' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);

		/*
		 * The two contribution routes (#133). They name a work item, unlike
		 * every other route here, so each callback checks that item against the
		 * signing site before doing anything with it — an id from another
		 * client is answered as one that does not exist (D-1, D-2).
		 *
		 * There is deliberately no third route. A client site can read the
		 * discussion on a piece of its own work and add to it, and that is the
		 * whole surface: nothing here edits an entry, nothing deletes one, and
		 * — the point of the issue — nothing moves the work.
		 */
		Server::register_route(
			$route_namespace,
			'/client/work-items/(?P<item_id>[A-Za-z0-9_\-]+)/comments',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'discussion' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, and the callback refuses any item that is not on it (ARCH-6).',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client/work-items/(?P<item_id>[A-Za-z0-9_\-]+)/comments',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'contribute' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, and the callback refuses any item that is not on it (ARCH-6).',
				),
			)
		);

		/*
		 * The onboarding checklist (#162, #167, #168). Read it, answer a step,
		 * attach something to a step.
		 *
		 * **Three routes, and no fourth.** A client may not create a step,
		 * delete one, reorder them or approve one, and the way that is enforced
		 * is that no route exists to do any of it — the same shape as the
		 * contribution routes above. What a client sends to the two writes is
		 * narrowed again inside Onboarding\Answers, so a route that does exist
		 * still cannot be talked into changing a due date or a reviewer.
		 */
		Server::register_route(
			$route_namespace,
			'/client/onboarding',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'checklist' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);

		/*
		 * An answer is posted, not patched, and that is a statement rather than
		 * a style. No client route edits anything (see
		 * ClientContributionRouteTest, which enforces it) — a client adds, and
		 * what they add here is an answer, which lands in the append-only step
		 * history exactly as a contribution lands in the comments table. That
		 * the step row moves on as a consequence is our doing, not theirs.
		 */
		Server::register_route(
			$route_namespace,
			'/client/onboarding/steps/(?P<step_id>[A-Za-z0-9_\-]+)/answer',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'answer_step' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, and the callback refuses any step that is not on it (ARCH-6).',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client/onboarding/steps/(?P<step_id>[A-Za-z0-9_\-]+)/evidence',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'attach_to_step' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, and the callback refuses any step that is not on it (ARCH-6).',
				),
			)
		);
	}

	/**
	 * The signing site's own checklist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function checklist( WP_REST_Request $request ) {
		$integration = self::connection( (string) $request->get_header( Signature::HEADER_SITE ) );

		if ( ! is_array( $integration ) ) {
			return $integration;
		}

		$site_id = (string) $integration['client_site_id'];
		$today   = gmdate( 'Y-m-d', bwx_forge_now() );

		$steps = array();

		foreach ( Steps::for_site( $site_id ) as $step ) {
			$history = StepEvents::for_step( (string) $step['id'] );

			$step             = Steps::with_lateness( $step, $today );
			$step['evidence'] = Evidence::for_step( (string) $step['id'], $site_id );
			$step['history']  = $history;

			/*
			 * #163. What we asked to be changed, worked out from the history
			 * rather than stored on the step — see Onboarding\Review for why.
			 * Sent to the client rather than kept internal because a step sent
			 * back with no visible reason is an instruction with no content.
			 */
			$step['feedback'] = Review::feedback_from( (string) $step['status'], $history );

			$steps[] = $step;
		}

		return rest_ensure_response(
			array(
				'steps'    => $steps,

				/*
				 * Worked out from the steps just read, rather than asked for
				 * separately. #164 settles that completion is never stored, and
				 * a second read here could disagree with the list beside it.
				 */
				'progress' => Progress::of( $steps ),
			)
		);
	}

	/**
	 * Records the client's answer to one of their steps.
	 *
	 * The credential rule (#167, ONB-3) is not in this method, and that is the
	 * point of it being written down anywhere: it lives on the write path in
	 * Onboarding\Answers, so this route and the studio's own route cannot come
	 * to different conclusions about the same password.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function answer_step( WP_REST_Request $request ) {
		$step = self::their_step( $request );

		if ( ! is_array( $step ) ) {
			return $step;
		}

		$body   = (array) $request->get_json_params();
		$moving = (string) ( $body['status'] ?? '' );

		/*
		 * ONB-2: a client may never approve their own step, even one they own,
		 * because self-certification makes the launch gate meaningless. Refused
		 * here rather than left to fail quietly, so the screen can say why.
		 */
		if ( '' !== $moving && ! Answers::client_may_move_to( $moving ) ) {
			self::log_refusal(
				(string) $request->get_header( Signature::HEADER_SITE ),
				'client_may_not_set_onboarding_status',
				array(
					'step_id' => (string) $step['id'],
					'status'  => $moving,
				)
			);

			return Errors::rest(
				'not_permitted',
				__( 'Send a step back to us for review and we will approve it. A step cannot be approved on your own site.', 'blueworx-forge' ),
				403
			);
		}

		$refused = self::refuse_client_unless( Capabilities::ANSWER_INFORMATION, $request, (string) $step['id'] );

		if ( null !== $refused ) {
			return $refused;
		}

		$result = Answers::record(
			$step,
			$body,
			$moving,
			0,
			Capabilities::CLIENT,
			(string) $request->get_header( Signature::HEADER_SITE )
		);

		if ( ! isset( $result['step'] ) ) {
			return Errors::rest(
				'answer_refused',
				(string) ( $result['message'] ?? '' ),
				400,
				array( 'field' => (string) ( $result['field'] ?? '' ) )
			);
		}

		return rest_ensure_response( array( 'step' => $result['step'] ) );
	}

	/**
	 * Attaches a file the client sent to one of their steps.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function attach_to_step( WP_REST_Request $request ) {
		$step = self::their_step( $request );

		if ( ! is_array( $step ) ) {
			return $step;
		}

		$refused = self::refuse_client_unless( Capabilities::ATTACH_EVIDENCE, $request, (string) $step['id'] );

		if ( null !== $refused ) {
			return $refused;
		}

		return OnboardingController::keep(
			$step,
			(array) $request->get_json_params(),
			0,
			(string) $request->get_header( Signature::HEADER_SITE ),
			Capabilities::CLIENT
		);
	}

	/**
	 * Asks the matrix whether a client may do this at all.
	 *
	 * The connection has already proved which site is calling. This proves that
	 * what is being attempted is something a client may do, so the answer is
	 * the same whether it arrives from their screen or from a script somebody
	 * pointed at the route — the same reasoning, and the same shape, as
	 * {@see self::contribute()}.
	 *
	 * @param string          $capability What is being exercised.
	 * @param WP_REST_Request $request    Request.
	 * @param string          $step_id    The step, for the refusal log.
	 * @return \WP_Error|null Null when it is allowed.
	 */
	private static function refuse_client_unless( string $capability, WP_REST_Request $request, string $step_id ) {
		$decision = Capabilities::decide(
			$capability,
			array(
				'role'      => Roles::CLIENT_ADMIN,
				'interface' => Capabilities::CLIENT,
				'own_site'  => true,
			)
		);

		if ( $decision['allowed'] ) {
			return null;
		}

		self::log_refusal(
			(string) $request->get_header( Signature::HEADER_SITE ),
			(string) $decision['code'],
			array(
				'capability' => $capability,
				'step_id'    => $step_id,
			)
		);

		return Errors::rest( 'not_permitted', $decision['reason'], 403, array( 'denied_by' => $decision['code'] ) );
	}

	/**
	 * The onboarding step this request names, if it is on the signing site.
	 *
	 * The same shape, and the same two-reasons-one-answer logging, as
	 * {@see self::their_item()}. A step belonging to another client is answered
	 * exactly as a step id nobody has ever used (D-1, D-2).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function their_step( WP_REST_Request $request ) {
		$integration = self::connection( (string) $request->get_header( Signature::HEADER_SITE ) );

		if ( ! is_array( $integration ) ) {
			return $integration;
		}

		$id   = (string) $request->get_param( 'step_id' );
		$step = Steps::get( $id );

		if ( null === $step || (string) $step['client_site_id'] !== (string) $integration['client_site_id'] ) {
			self::log_refusal(
				(string) $request->get_header( Signature::HEADER_SITE ),
				null === $step ? 'unknown_onboarding_step' : 'not_your_onboarding_step',
				array( 'step_id' => $id )
			);

			return Boundary::absent( 'onboarding_step' );
		}

		return $step;
	}

	/**
	 * Confirms the calling site's identity.
	 *
	 * The site id is read from the verified header rather than from a parameter:
	 * the header is what the signature covers, so it is the only one that has
	 * been proven. A site_id in the body would be whatever the caller typed.
	 *
	 * `server_time` is here so a client whose clock has drifted can tell that is
	 * what is wrong, rather than seeing its requests refused for no visible
	 * reason once drift passes the signing window.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handshake( WP_REST_Request $request ): WP_REST_Response {
		$site_id = (string) $request->get_header( Signature::HEADER_SITE );
		$site    = Registry::get( $site_id );

		return rest_ensure_response(
			array(
				'ok'          => true,
				'site_id'     => $site_id,
				'name'        => $site['name'] ?? '',
				'status'      => $site['status'] ?? '',
				'server_time' => bwx_forge_now(),
				'version'     => BWX_FORGE_VERSION,
			)
		);
	}

	/**
	 * What this site should send now (#173).
	 *
	 * Finished envelopes: who, what it says, and which event each one settles.
	 * The site decides nothing about the content — two implementations of what
	 * an email says is two versions of what we told a client.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function notifications( WP_REST_Request $request ) {
		/*
		 * Resolved through the integration rather than used as it arrives. The
		 * signature names the *registry* site — the connection — and the events
		 * are recorded against the Client Site it belongs to. They are two
		 * different ids, and treating one as the other silently answers with an
		 * empty outbox, which looks exactly like having nothing to send.
		 */
		$integration = self::connection( (string) $request->get_header( Signature::HEADER_SITE ) );

		if ( ! is_array( $integration ) ) {
			return $integration;
		}

		return rest_ensure_response(
			array(
				'ok'    => true,
				'send'  => Outbox::for_site( (string) $integration['client_site_id'] ),
				'batch' => Outbox::BATCH,
			)
		);
	}

	/**
	 * What happened to them.
	 *
	 * **Every outcome is checked against the site that raised it**, not taken
	 * on the word of the caller. A signature proves which site is speaking; it
	 * does not make that site's claims about other people's events true, and an
	 * event id is the sort of thing that could be guessed at. So an id this
	 * site was never asked to send is ignored rather than recorded — quietly,
	 * because the honest cause is a race with a site that has just been moved
	 * to another client, not an attack.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function notified( WP_REST_Request $request ) {
		$integration = self::connection( (string) $request->get_header( Signature::HEADER_SITE ) );

		if ( ! is_array( $integration ) ) {
			return $integration;
		}

		$site_id  = (string) $integration['client_site_id'];
		$body     = (array) $request->get_json_params();
		$recorded = 0;

		foreach ( (array) ( $body['outcomes'] ?? array() ) as $one ) {
			$id    = (string) ( is_array( $one ) ? ( $one['event_id'] ?? '' ) : '' );
			$event = '' === $id ? null : Notifications::get( $id );

			if ( null === $event || (string) $event['client_site_id'] !== $site_id ) {
				continue;
			}

			/*
			 * #174. Recorded as an attempt rather than as a final answer: a
			 * failure is tried again at five, thirty and a hundred and twenty
			 * minutes, and only becomes somebody's problem when the ladder runs
			 * out. What the client site reports is what happened this time; how
			 * many times that makes, and what to do about it, is ours.
			 */
			$outcome = NotificationLog::attempt(
				$id,
				! empty( $one['sent'] ),
				(string) ( $one['detail'] ?? '' )
			);

			if ( '' !== $outcome ) {
				++$recorded;
			}
		}

		return rest_ensure_response(
			array(
				'ok'       => true,
				'recorded' => $recorded,
			)
		);
	}

	/**
	 * Records what the calling site says about itself (#89).
	 *
	 * The site it describes is the one that signed the request, never one named
	 * in the body — the same rule the workspace read follows, and for the same
	 * reason: a signed request proves which site is calling and that is the only
	 * site it may touch.
	 *
	 * A site with no integration record is one whose key was issued through M1's
	 * routes rather than against a Client Site. It is turned away rather than
	 * given a record: an integration exists to say which client site a
	 * connection belongs to, and inventing one here would have to guess.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function report( WP_REST_Request $request ) {
		$site_id = (string) $request->get_header( Signature::HEADER_SITE );
		$checked = Validate::report( (array) $request->get_json_params() );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_report',
				__( 'That report could not be recorded.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$updated = Integrations::note_report( $site_id, $checked['values'] );

		if ( null === $updated ) {
			return Errors::rest(
				'no_integration',
				__( 'This site is not connected to a client site.', 'blueworx-forge' ),
				409
			);
		}

		return rest_ensure_response(
			array(
				'ok'       => true,
				'recorded' => $updated['last_report_at'],
			)
		);
	}

	/**
	 * The calling site's workspace record.
	 *
	 * This is the canonical record the client site renders and does not store
	 * (ARCH-2). The site it describes is always the one that signed the request,
	 * never one named in a parameter — a signed request proves which site is
	 * calling, and that is the only site it may read.
	 *
	 * `generated` is the studio's own clock at the moment the record was read.
	 * The client site stamps its cache with the time it received the answer
	 * rather than with this, because the age it shows a human has to be measured
	 * on the clock that human's browser is on. It is here for support: a record
	 * that looks stale on one side and fresh on the other is clock drift, and
	 * this is what shows that.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function workspace( WP_REST_Request $request ) {
		$site_id = (string) $request->get_header( Signature::HEADER_SITE );

		/*
		 * Guarded, but by the narrower of the two checks (D-7).
		 *
		 * This is the read every client screen makes, so a workspace that went
		 * on answering after a site was closed would be the one place
		 * deactivation still leaked — and the only place somebody would look to
		 * find out whether anything was wrong.
		 *
		 * It does not require an integration, though, unlike the routes that
		 * serve a client's records. Everything in this answer comes from the
		 * registry: which site this is, what it is called, when it connected.
		 * A key issued through M1's routes rather than against a Client Site
		 * has no client whose *work* it could describe — which is why the board
		 * turns one away — but it is still a registered site, and refusing to
		 * tell it its own name would break the connection screen it is
		 * diagnosed from.
		 */
		$ended = self::refuse_if_ended( $site_id );

		if ( null !== $ended ) {
			return $ended;
		}

		$site = Registry::get( $site_id );

		return rest_ensure_response(
			array(
				'ok'           => true,
				'generated'    => bwx_forge_now(),
				'record'       => array(
					'site_id'         => $site_id,
					'name'            => $site['name'] ?? '',
					'url'             => $site['url'] ?? '',
					'status'          => $site['status'] ?? '',
					'connected_since' => (int) ( $site['created_at'] ?? 0 ),
				),
				'contact'      => self::contact_for( $site_id ),

				/*
				 * Carried here rather than fetched separately (#140). Every
				 * client screen already reads the workspace, so this costs no
				 * extra round trip and no extra wait — and the screen that
				 * wants it is the Ask form, whose whole job is to accept what
				 * somebody types. A form that pauses on a studio call before it
				 * will draw itself is a worse form, and it is worst exactly
				 * when the studio is unreachable.
				 *
				 * Same answer as /client/availability, which stays for a site
				 * that wants to ask the question on its own.
				 */
				'availability' => ClientAnswer::for_window( gmdate( 'Y-m-d' ), self::default_horizon() ),
			)
		);
	}

	/**
	 * How far ahead an availability answer looks by default.
	 *
	 * @return string YYYY-MM-DD.
	 */
	private static function default_horizon(): string {
		return gmdate( 'Y-m-d', (int) strtotime( gmdate( 'Y-m-d' ) . ' 00:00:00 UTC' ) + ( ClientAnswer::DEFAULT_DAYS * DAY_IN_SECONDS ) );
	}

	/**
	 * The calling site's board (#128).
	 *
	 * Everything the client site draws its three views from, in one read. The
	 * site is the one that signed the request, resolved to its client the same
	 * way the contact is — there is no site or client parameter here, so there
	 * is nothing to edit into somebody else's (D-2).
	 *
	 * A connection with no integration record is turned away rather than
	 * answered with an empty board. The two are not the same thing: an empty
	 * board says "you have no work", and a key issued outside a Client Site has
	 * no client whose work it could be describing.
	 *
	 * The stage list travels with the answer rather than being written down a
	 * second time on the client. A board whose columns are its own copy of the
	 * state machine is a board that goes wrong the day a stage is added, and
	 * goes wrong quietly.
	 *
	 * Archived work is left out by Items::for_site()'s own default, which is
	 * where that decision already lives.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function board( WP_REST_Request $request ) {
		$site_id     = (string) $request->get_header( Signature::HEADER_SITE );
		$integration = self::connection( $site_id );

		if ( ! is_array( $integration ) ) {
			return $integration;
		}

		$rows = Items::for_site( (string) $integration['client_site_id'] );

		return rest_ensure_response(
			array(
				'ok'        => true,
				'generated' => bwx_forge_now(),
				'stages'    => self::columns(),
				'items'     => ClientView::items( $rows, array( Users::class, 'get' ) ),
			)
		);
	}

	/**
	 * Whether there is room, in a form that says nothing about anybody else.
	 *
	 * The signature says which site is calling and the answer ignores it, which
	 * is exactly the point (#140): two clients asking on the same day get the
	 * same sentence, so there is nothing in it that is about either of them.
	 * There is therefore no site to look up here and nothing to scope, which
	 * makes this the one client route with no `connection()` call in it.
	 *
	 * The window is clamped rather than refused. A client site asking for
	 * something odd gets a sensible answer instead of an error it has no way to
	 * act on, and the window it was actually answered for comes back with it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function availability( WP_REST_Request $request ): WP_REST_Response {
		$from = (string) $request->get_param( 'from' );
		$to   = (string) $request->get_param( 'to' );

		if ( ! self::is_date( $from ) ) {
			$from = gmdate( 'Y-m-d' );
		}

		$furthest = gmdate(
			'Y-m-d',
			(int) strtotime( $from . ' 00:00:00 UTC' ) + ( ClientAnswer::DEFAULT_DAYS * DAY_IN_SECONDS )
		);

		if ( ! self::is_date( $to ) || $to < $from || $to > $furthest ) {
			$to = $furthest;
		}

		return rest_ensure_response( ClientAnswer::for_window( $from, $to ) );
	}

	/**
	 * Whether a string is a date this can work with.
	 *
	 * @param string $date Candidate.
	 * @return bool
	 */
	private static function is_date( string $date ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );
	}

	/**
	 * What the calling site has asked for, and what happened to it (#130).
	 *
	 * The mirror of the send, on the same route: a client who asks is owed a way
	 * to see what became of it, and this is that. The site is the one that
	 * signed the request, so a client reads their own submissions and there is
	 * no parameter here through which they could ask for anybody else's (D-2).
	 *
	 * The state names travel with the answer, as the board's column names do.
	 * A client artifact that turned 'in-review' into English itself would hold a
	 * second copy of the studio's intake vocabulary, and that copy would be
	 * wrong the day a state is added.
	 *
	 * The point of contact comes with it because this is the screen where
	 * somebody wants to chase an answer, and a status with nobody's name against
	 * it invites a reply into a support address nobody reads.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function submissions( WP_REST_Request $request ) {
		$site_id     = (string) $request->get_header( Signature::HEADER_SITE );
		$integration = self::connection( $site_id );

		if ( ! is_array( $integration ) ) {
			return $integration;
		}

		$rows = Submissions::for_site( (string) $integration['client_site_id'] );

		return rest_ensure_response(
			array(
				'ok'          => true,
				'generated'   => bwx_forge_now(),
				'states'      => self::intake_states(),
				'submissions' => ClientView::submissions( $rows, array( Items::class, 'get' ) ),
				'contact'     => self::contact_for( $site_id ),
			)
		);
	}

	/**
	 * Records something the calling site is asking for (#129).
	 *
	 * A client may ask whether or not they pay for support. That is the point
	 * of the record existing at all: work has a commercial life and a question
	 * does not, so nothing here consults a package, and nothing here can be
	 * refused for the want of one.
	 *
	 * The site is the one that signed the request, and the client is that
	 * site's client. Neither is taken from the body, so a submission cannot be
	 * filed against somebody else's client however it is addressed (D-2).
	 *
	 * What comes back is the whole stored record. The client site shows it as
	 * the receipt, which is the moment their words become fixed (REQ-1).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function submit( WP_REST_Request $request ) {
		$site_id     = (string) $request->get_header( Signature::HEADER_SITE );
		$integration = self::connection( $site_id );

		if ( ! is_array( $integration ) ) {
			return $integration;
		}

		$body    = (array) $request->get_json_params();
		$checked = WorkValidate::submission( $body );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_submission',
				__( 'That could not be sent.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		// Whoever the client site says was at the keyboard. Recorded as a name
		// and used as a name: NOTIF-1 resolves who to write to from verified
		// client records, never from something typed on the far side of a
		// connection.
		$by = trim( (string) ( $body['submitted_by'] ?? '' ) );

		$stored = Submissions::create(
			(string) $integration['client_site_id'],
			(string) $integration['client_id'],
			$checked['values'],
			mb_substr( $by, 0, 191 )
		);

		if ( null === $stored ) {
			return Errors::rest(
				'submission_not_recorded',
				__( 'That could not be recorded. Nothing has been sent.', 'blueworx-forge' ),
				500
			);
		}

		return rest_ensure_response(
			array(
				'ok'         => true,
				'submission' => $stored,
			)
		);
	}

	/**
	 * The discussion on one piece of this site's work (#133).
	 *
	 * Client-visible entries only, and that is the query rather than a filter
	 * this method applies — Work\Comments::for_item() in the client scope never
	 * selects an internal note, so there is no argument this could pass and no
	 * bug it could contain that would return one.
	 *
	 * The outstanding questions come with it. A client arriving at a piece of
	 * their work should be told what is being waited on rather than have to
	 * find it in a thread, and answering is the one contribution with a rule
	 * attached — so the screen needs to know which questions are real before it
	 * can offer to answer one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function discussion( WP_REST_Request $request ) {
		$item = self::their_item( $request );

		if ( ! is_array( $item ) ) {
			return $item;
		}

		return rest_ensure_response(
			array(
				'ok'          => true,
				'generated'   => bwx_forge_now(),
				'item'        => ClientView::item( $item, array( Users::class, 'get' ) ),
				'comments'    => Comments::for_item( (string) $item['id'], Comments::SCOPE_CLIENT ),
				'outstanding' => Comments::outstanding( (string) $item['id'] ),

				/*
				 * What this client may do here, decided by the studio and sent
				 * with the record rather than worked out on the far side. A
				 * client artifact that decided for itself what it was allowed
				 * to do would be a second copy of the permission matrix, and
				 * the copy that is wrong is always the one nobody enforces.
				 */
				'may'         => self::client_may(),
			)
		);
	}

	/**
	 * Records something the client has to say about their own work (#133).
	 *
	 * A comment, a piece of evidence, or an answer to something we asked. All
	 * three are permitted at any stage (AUTH-2) and none of them is a stage
	 * change — which is a fact about Work\Contributions rather than a check
	 * made here: what arrives is read down to four keys, none of which is a
	 * stage, and what leaves is a row in the comments table.
	 *
	 * **There is no transition anywhere in this path**, and that is what the
	 * whole issue is closed on. Nothing below calls Work\Transition, touches
	 * Work\Items, or writes a gate record. A client contribution is words, and
	 * words do not move work (§14).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function contribute( WP_REST_Request $request ) {
		$item = self::their_item( $request );

		if ( ! is_array( $item ) ) {
			return $item;
		}

		$body  = (array) $request->get_json_params();
		$asked = Contributions::read( $body );

		$refused = Contributions::refuse( $asked, Comments::outstanding( (string) $item['id'] ) );

		if ( Contributions::ALLOWED !== $refused ) {
			return Errors::rest( $refused, Contributions::reason( $refused ), 400 );
		}

		/*
		 * The client administrator's own row of the matrix, asked for the
		 * capability this particular contribution exercises. The connection has
		 * already proved which site is calling; this proves that what is being
		 * attempted is something a client may do at all, so the answer is the
		 * same whether it arrives from the client's screen or from a script
		 * somebody pointed at the route.
		 */
		$decision = Capabilities::decide(
			Contributions::capability( $asked ),
			array(
				'role'      => Roles::CLIENT_ADMIN,
				'interface' => Capabilities::CLIENT,
				'own_site'  => true,
			)
		);

		if ( ! $decision['allowed'] ) {
			self::log_refusal(
				(string) $request->get_header( Signature::HEADER_SITE ),
				(string) $decision['code'],
				array(
					'capability' => Contributions::capability( $asked ),
					'item_id'    => (string) $item['id'],
				)
			);

			return Errors::rest( 'not_permitted', $decision['reason'], 403, array( 'denied_by' => $decision['code'] ) );
		}

		$recorded = Comments::add(
			Contributions::entry(
				$asked,
				$item,
				(string) $request->get_header( Signature::HEADER_SITE ),
				(string) ( $body['author_name'] ?? '' )
			),
			Comments::SCOPE_CLIENT
		);

		if ( null === $recorded ) {
			return Errors::rest(
				'not_recorded',
				__( 'That could not be recorded. Nothing has been sent.', 'blueworx-forge' ),
				500
			);
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'comment' => $recorded,

				// The stage, echoed back deliberately. It is the same stage the
				// item was at before, and a client screen showing it after a
				// contribution is showing the guarantee rather than a value.
				'stage'   => (string) $item['stage'],
			)
		);
	}

	/**
	 * The work item this request names, if it belongs to the site that signed
	 * for it.
	 *
	 * An item on another site gets the same answer as an id nobody has used.
	 * This is the only place in this controller where a caller names a record
	 * at all — every other route here describes the signing site itself — so it
	 * is the only place D-1 and D-2 have anything to bite on, and the check is
	 * made before the item is used for anything.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function their_item( WP_REST_Request $request ) {
		$integration = self::connection( (string) $request->get_header( Signature::HEADER_SITE ) );

		if ( ! is_array( $integration ) ) {
			return $integration;
		}

		$id   = (string) $request->get_param( 'item_id' );
		$item = Items::get( $id );

		if ( null === $item || (string) $item['client_site_id'] !== (string) $integration['client_site_id'] ) {
			/*
			 * Logged as two different reasons, and answered as one (#134).
			 *
			 * The answer a client site gets has to be identical either way —
			 * that is the whole of D-1 and D-2, and it is why Boundary::absent
			 * is the same call for both. But on our side of the connection they
			 * are not remotely the same event: a site asking for an id that
			 * does not exist is a stale bookmark, and a site asking for an id
			 * that belongs to somebody else is worth a look. The permission
			 * matrix requires every refusal be recorded with the site, the item
			 * and the time, and a log that flattened these two together would
			 * be a log nobody can find the second one in.
			 */
			self::log_refusal(
				(string) $request->get_header( Signature::HEADER_SITE ),
				null === $item ? 'unknown_work_item' : 'not_your_work_item',
				array( 'item_id' => $id )
			);

			return Boundary::absent( 'work_item' );
		}

		return $item;
	}

	/**
	 * Records a refusal made to a client site.
	 *
	 * Studio-side refusals are logged by Rest\Access, which reads a logged-in
	 * person out of the request. Nobody is logged in here — the caller is a
	 * machine holding a key — so the entry names the site instead, and says it
	 * came from the client interface. Two writers rather than one because the
	 * two have genuinely different facts to record, and a shared one would have
	 * to invent a user id for half its callers.
	 *
	 * @param string               $site_id The registry site that signed the request.
	 * @param string               $reason  Machine-readable reason.
	 * @param array<string, mixed> $context Anything else worth keeping.
	 */
	private static function log_refusal( string $site_id, string $reason, array $context = array() ): void {
		SecurityLog::refused(
			$site_id,
			$reason,
			array_merge( $context, array( 'interface' => Capabilities::CLIENT ) )
		);
	}

	/**
	 * What a client administrator may do on their own site, as the matrix says.
	 *
	 * Sent with the discussion so the client artifact draws a control only
	 * where there is something behind it (#134). Read from
	 * Tenancy\Capabilities rather than written out here, because a hard-coded
	 * "clients may comment" would be a second copy of the matrix that stays
	 * true right up until the matrix changes.
	 *
	 * @return array<string, bool>
	 */
	private static function client_may(): array {
		$context = array(
			'role'      => Roles::CLIENT_ADMIN,
			'interface' => Capabilities::CLIENT,
			'own_site'  => true,
		);

		return array(
			'comment'  => Capabilities::allows( Capabilities::COMMENT, $context ),
			'evidence' => Capabilities::allows( Capabilities::ATTACH_EVIDENCE, $context ),
			'answer'   => Capabilities::allows( Capabilities::ANSWER_INFORMATION, $context ),

			/*
			 * Stated rather than left out, because a screen that draws no move
			 * controls and a screen that has not been told about them look the
			 * same, and only one of them is a promise. §14: no client role
			 * moves work, by any route, and the WF-5 override cannot open it.
			 */
			'move'     => Capabilities::allows( Capabilities::MOVE_FORWARD, $context ),
		);
	}

	/**
	 * The board's columns: every stage, named as the studio names it.
	 *
	 * The names travel with the answer so the client artifact never holds its
	 * own copy of the state machine. A client that translated 'up-next' into
	 * words itself would be a second list to update, in a second language, that
	 * nobody remembers on the day a stage changes.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function columns(): array {
		return array_map(
			static fn( string $stage ): array => array(
				'slug'  => $stage,
				'label' => Stages::label( $stage ),
			),
			Stages::ALL
		);
	}
	/**
	 * Every intake state, named as the studio names it.
	 *
	 * Sent whole rather than only the states in use, so a client screen can put
	 * words to a submission whatever it has since become without asking again.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function intake_states(): array {
		return array_map(
			static fn( string $state ): array => array(
				'slug'  => $state,
				'label' => Submissions::label( $state ),
			),
			Submissions::STATES
		);
	}

	/**
	 * The connection this request is entitled to act through.
	 *
	 * Four things have to be true before a signed request means anything, and
	 * only the first was ever checked here:
	 *
	 * 1. The signature is valid, which Permissions::client_site has already
	 *    settled by the time any of this runs.
	 * 2. The signing site is joined to a Client Site. A key issued through M1's
	 *    routes rather than against one has no client whose records it could be
	 *    describing, and is turned away rather than given an empty answer.
	 * 3. **That Client Site is still active.**
	 * 4. **So is the client above it.**
	 *
	 * The last two are D-7 — act after deactivation — and they were the gap the
	 * denial suite found (#135). A signature outlives the relationship it was
	 * issued for: revoking the key stops a site (D-8), but closing a site or a
	 * client did not, because nothing on this path had ever read their status.
	 * A relationship that has ended and a credential that still works are
	 * exactly the pair that lets somebody go on reading for months.
	 *
	 * Refused with 403 rather than 404. The caller has proved which site it is,
	 * so there is nothing left to hide from it — and a client site told "you do
	 * not exist" would report a broken connection, when what has happened is
	 * that somebody at the studio ended the arrangement (#134).
	 *
	 * **Deactivation stops acting, not attribution.** Nothing here deletes or
	 * rewrites what the site did while it was active, and the audit trail keeps
	 * naming it — which is the other half of D-7 and the half that gets
	 * dropped.
	 *
	 * @param string $site_id The registry site that signed the request.
	 * @return array<string, mixed>|\WP_Error The integration, or the refusal.
	 */
	private static function connection( string $site_id ) {
		$integration = Integrations::by_site_id( $site_id );

		if ( null === $integration ) {
			return Errors::rest(
				'no_integration',
				__( 'This site is not connected to a client site.', 'blueworx-forge' ),
				409
			);
		}

		$ended = self::refuse_if_ended( $site_id );

		return null === $ended ? $integration : $ended;
	}

	/**
	 * The refusal for a site whose arrangement with the studio has ended, if it
	 * has.
	 *
	 * Split from {@see self::connection()} because the two questions are not
	 * the same and only one of them is D-7. "Is there a client behind this key"
	 * decides whether there are records to serve; "is that client still with
	 * us" decides whether anything may be served at all. A site with no client
	 * has not been deactivated — it was never activated — and answering it with
	 * "no longer active" would be telling somebody diagnosing a half-finished
	 * setup that something had been taken away.
	 *
	 * @param string $site_id The registry site that signed the request.
	 * @return \WP_Error|null Null when there is nothing to refuse.
	 */
	private static function refuse_if_ended( string $site_id ) {
		$integration = Integrations::by_site_id( $site_id );

		if ( null === $integration ) {
			return null;
		}

		$client_site = ClientSites::get( (string) $integration['client_site_id'] );

		if ( null === $client_site || 'active' !== (string) $client_site['status'] ) {
			return self::ended();
		}

		$client = Clients::get( (string) $client_site['client_id'] );

		if ( null === $client || 'active' !== (string) $client['status'] ) {
			return self::ended();
		}

		return null;
	}

	/**
	 * The answer for a site whose arrangement with the studio has ended.
	 *
	 * One sentence for a closed site and a closed client, because from the far
	 * side they are the same fact and the difference is ours. It says the
	 * connection itself is fine, so nobody spends a week looking at their
	 * network for something that was a decision (#134).
	 *
	 * @return \WP_Error
	 */
	private static function ended() {
		return Errors::rest(
			'connection_ended',
			__( 'This site is no longer active with the studio.', 'blueworx-forge' ),
			403
		);
	}

	/**
	 * Who the client's contact is here, as they may see it (#95).
	 *
	 * A name, and nothing else. The address, the WordPress account and the
	 * grants somebody holds are ours; the last of those says what a member of
	 * staff is allowed to do, and no client has any business reading it.
	 *
	 * While there is no usable contact the client is told nothing rather than
	 * told a name that no longer answers. The flag that somebody needs to fix
	 * that is on our screen, not theirs.
	 *
	 * @param string $site_id The registry site making the request.
	 * @return array<string, mixed>
	 */
	private static function contact_for( string $site_id ): array {
		$integration = Integrations::by_site_id( $site_id );

		if ( null === $integration ) {
			return array();
		}

		$assignment = Contacts::current( (string) $integration['client_id'] );
		$person     = null === $assignment || '' === (string) $assignment['user_id']
			? null
			: Users::get( (string) $assignment['user_id'] );

		$state = Contacts::resolve( $assignment, $person );

		return $state['needs_reassignment'] ? array() : Contacts::for_client( $state['contact'] );
	}
}
