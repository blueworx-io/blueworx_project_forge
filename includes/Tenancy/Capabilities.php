<?php
/**
 * Who may do what.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * #91. docs/architecture/permission-matrix.md as code — every capability,
 * every role, both interfaces, and the denials.
 *
 * **A denial is a result, not a missing grant.** Every question asked of this
 * class is answered with a decision that says yes or no *and why not*, and the
 * why is a code a caller can act on: this is a client role and clients never
 * transition; this is not your site; this gate needs an approver you are not.
 * An absent grant answers "no" and nothing else, and a route that has to
 * explain itself then invents its own reason — which is how two places end up
 * disagreeing about what the rule was.
 *
 * **The grid is data.** Adding a capability means adding a row, and a row with
 * no cell for a role fails its own test rather than defaulting to anything. The
 * matrix document is the source; this file is the same table with the same
 * conditions, and Tenancy\Denials is the list of things that must be refused.
 *
 * Three things this class deliberately does not do:
 *
 * - It does not read the database. It is handed a context and answers from it,
 *   so every rule is testable without a site, and so the same decision can be
 *   made about a request that has not happened yet.
 * - It does not log. A refusal is worth recording, but what to record and where
 *   belongs to the route that was refused (Sites\SecurityLog).
 * - It does not know about WordPress capabilities. `manage_options` says who
 *   administers this *site*; this says who may act on a *client's* records.
 */
final class Capabilities {

	/**
	 * The studio: our own command centre.
	 */
	public const STUDIO = 'studio';

	/**
	 * The workspace running on the client's own WordPress.
	 */
	public const CLIENT = 'client';

	/**
	 * Allowed outright.
	 */
	private const YES = 'yes';

	/**
	 * Refused outright.
	 */
	private const NO = 'no';

	/*
	 * The actor keys. The matrix has five columns, and they are not the five
	 * roles: Principal is a grant a staff user holds (AUTH-3) rather than an
	 * account type, and the two viewer roles differ in exactly one capability.
	 * Resolving a role and its grants to one of these happens in actor().
	 */

	/**
	 * Primary administrator.
	 */
	public const PRIMARY_ADMIN = 'primary_admin';

	/**
	 * Ordinary staff.
	 */
	public const STAFF = 'staff';

	/**
	 * Staff holding the AUTH-3 Principal grant.
	 */
	public const PRINCIPAL = 'principal';

	/**
	 * The client's administrator.
	 */
	public const CLIENT_ADMIN = 'client_admin';

	/**
	 * A viewer, on either side.
	 */
	public const VIEWER = 'viewer';

	/**
	 * Every actor, in the matrix's column order.
	 */
	public const ACTORS = array(
		self::PRIMARY_ADMIN,
		self::STAFF,
		self::PRINCIPAL,
		self::CLIENT_ADMIN,
		self::VIEWER,
	);

	/*
	 * Why a decision went the way it did. These are the codes a route answers
	 * with, so a refusal reads the same wherever it was attempted from.
	 */

	/**
	 * Allowed.
	 */
	public const GRANTED = 'granted';

	/**
	 * The role simply does not hold it.
	 */
	public const NOT_HELD = 'not_held';

	/**
	 * A client role attempted a workflow move. The lock in brief §14, which the
	 * WF-5 override cannot open (#115).
	 */
	public const CLIENT_LOCK = 'client_transition_lock';

	/**
	 * Staff code does not exist on the client artifact (ARCH-1), so the answer
	 * is no by construction rather than by permission.
	 */
	public const WRONG_INTERFACE = 'wrong_interface';

	/**
	 * The membership does not reach this site (ARCH-3, AUTH-6).
	 */
	public const NOT_YOUR_SITE = 'not_your_site';

	/**
	 * Held, but only when a condition holds, and it does not.
	 */
	public const CONDITION_FAILED = 'condition_failed';

	/**
	 * Nobody holds it — a derived value or an append-only record.
	 */
	public const NOBODY = 'held_by_nobody';

	/**
	 * There is no such capability. Answered rather than assumed, so a typo in a
	 * route is a refusal instead of a grant.
	 */
	public const UNKNOWN = 'unknown_capability';

	/*
	 * The capabilities, one per row of the matrix.
	 */

	// Read.
	public const VIEW_WORK                = 'view_work';
	public const VIEW_OTHER_CLIENT_WORK   = 'view_other_client_work';
	public const VIEW_INTERNAL_NOTES      = 'view_internal_notes';
	public const VIEW_APPROVER_IDENTITY   = 'view_approver_identity';
	public const VIEW_STAFF_CAPACITY      = 'view_staff_capacity';
	public const VIEW_AVAILABILITY_RESULT = 'view_availability_result';
	public const VIEW_OWN_LEDGER          = 'view_own_ledger';
	public const VIEW_CHANGELOG           = 'view_changelog';
	public const VIEW_REPORTS             = 'view_reports';

	// Create.
	public const CREATE_PARENT_ITEM = 'create_parent_item';
	public const CREATE_WORK_ITEM   = 'create_work_item';
	public const SUBMIT_BUG         = 'submit_bug';
	public const SUBMIT_REQUEST     = 'submit_request';

	// Edit.
	public const EDIT_DEFINITION     = 'edit_definition_fields';
	public const EDIT_ACCOUNTABILITY = 'edit_accountability_fields';
	public const EDIT_PLANNING       = 'edit_planning_fields';
	public const EDIT_WORKFLOW       = 'edit_workflow_fields';
	public const EDIT_COMMERCIAL     = 'edit_commercial_fields';
	public const EDIT_DERIVED_STATE  = 'edit_derived_state';
	public const EDIT_APPEND_ONLY    = 'edit_append_only_record';
	public const REVIEW_SUBMISSION   = 'review_submission';

	// Comment and evidence.
	public const COMMENT             = 'comment';
	public const WRITE_INTERNAL_NOTE = 'write_internal_note';
	public const ATTACH_EVIDENCE     = 'attach_evidence';
	public const ANSWER_INFORMATION  = 'answer_information_request';

	// Transition.
	public const MOVE_FORWARD    = 'move_forward';
	public const RETURN_ITEM     = 'return_item';
	public const BLOCK_ITEM      = 'block_item';
	public const RECORD_OUTCOME  = 'record_outcome';
	public const APPROVE_REVIEW  = 'approve_review';
	public const CONFIRM_RELEASE = 'confirm_release';
	public const REOPEN          = 'reopen';
	public const OVERRIDE        = 'override';

	// Approve.
	public const APPROVE_GATE     = 'approve_gate';
	public const COMPLETE_GATE    = 'complete_gate_requirement';
	public const APPROVE_OWN_ITEM = 'approve_own_item_gate';

	// Administer.
	public const ADMINISTER_TENANCY = 'administer_tenancy';
	public const INVITE_USER        = 'invite_user';
	public const GRANT_CAPABILITY   = 'grant_capability';

	// Commercial.
	public const ADMINISTER_COMMERCIAL = 'administer_commercial';
	public const ADJUST_REVIEW_HOURS   = 'adjust_review_hours';
	public const REQUEST_UPGRADE       = 'request_upgrade';

	/**
	 * The grid. Capability, then interface, then actor.
	 *
	 * A cell is `yes`, `no`, or the name of the condition that decides it. No
	 * cell is blank — that is the rule the matrix document opens with, and
	 * CapabilitiesTest holds this file to it.
	 *
	 * @return array<string, array<string, array<string, string>>>
	 */
	public static function grid(): array {
		static $grid = null;

		if ( null !== $grid ) {
			return $grid;
		}

		$grid = array(

			// ---- Read ----------------------------------------------------

			self::VIEW_WORK                => self::row(
				array( self::YES, 'own_site', 'own_site', self::NO, 'own_site' ),
				array( self::NO, self::NO, self::NO, 'own_site', 'own_site' )
			),
			self::VIEW_OTHER_CLIENT_WORK   => self::row(
				array( self::YES, self::NO, self::NO, self::NO, self::NO ),
				self::all_no()
			),
			self::VIEW_INTERNAL_NOTES      => self::row(
				array( self::YES, self::YES, self::YES, self::NO, 'internal_viewer' ),
				self::all_no()
			),
			self::VIEW_APPROVER_IDENTITY   => self::row(
				array( self::YES, self::YES, self::YES, self::NO, self::YES ),
				self::all_no()
			),
			self::VIEW_STAFF_CAPACITY      => self::row(
				array( self::YES, self::YES, self::YES, self::NO, self::YES ),
				self::all_no()
			),
			self::VIEW_AVAILABILITY_RESULT => self::row(
				array( self::YES, self::YES, self::YES, self::YES, self::YES ),
				array( self::NO, self::NO, self::NO, self::YES, self::YES )
			),
			self::VIEW_OWN_LEDGER          => self::row(
				array( self::YES, self::YES, self::YES, self::YES, self::YES ),
				array( self::NO, self::NO, self::NO, self::YES, self::YES )
			),
			self::VIEW_CHANGELOG           => self::row(
				array( self::YES, self::YES, self::YES, 'own_site', 'own_site' ),
				array( self::NO, self::NO, self::NO, 'own_site', 'own_site' )
			),
			self::VIEW_REPORTS             => self::row(
				array( self::YES, 'own_site', 'own_site', self::NO, 'own_site' ),
				self::all_no()
			),

			// ---- Create --------------------------------------------------

			self::CREATE_PARENT_ITEM       => self::row(
				array( self::YES, self::YES, self::YES, self::NO, self::NO ),
				array( self::NO, self::NO, self::NO, 'active_package', self::NO )
			),
			self::CREATE_WORK_ITEM         => self::row(
				array( self::YES, self::YES, self::YES, self::NO, self::NO ),
				array( self::NO, self::NO, self::NO, 'active_package', self::NO )
			),

			/*
			 * A bug and a request are always available, package or no package
			 * (COMM-5, §8.2). A client who cannot report that something is
			 * broken is a client who tells us by email, and then it is not in
			 * the system at all.
			 */
			self::SUBMIT_BUG               => self::row(
				array( self::YES, self::YES, self::YES, self::YES, self::NO ),
				array( self::NO, self::NO, self::NO, self::YES, self::NO )
			),
			self::SUBMIT_REQUEST           => self::row(
				array( self::YES, self::YES, self::YES, self::YES, self::NO ),
				array( self::NO, self::NO, self::NO, self::YES, self::NO )
			),

			// ---- Edit ----------------------------------------------------

			self::EDIT_DEFINITION          => self::row(
				array( self::YES, self::YES, self::YES, self::NO, self::NO ),
				array( self::NO, self::NO, self::NO, 'before_documentation_ends', self::NO )
			),
			self::EDIT_ACCOUNTABILITY      => self::row(
				array( self::YES, self::YES, self::YES, self::NO, self::NO ),
				self::all_no()
			),
			self::EDIT_PLANNING            => self::row(
				array( self::YES, self::YES, self::YES, self::NO, self::NO ),
				self::all_no()
			),
			self::EDIT_WORKFLOW            => self::row(
				array( self::YES, self::YES, self::YES, self::NO, self::NO ),
				self::all_no()
			),
			self::EDIT_COMMERCIAL          => self::row(
				array( self::YES, self::NO, self::NO, self::NO, self::NO ),
				self::all_no()
			),

			/*
			 * Two rows nobody holds, the Primary administrator included. A
			 * derived status somebody can type over is not derived, and an
			 * append-only record somebody can edit is not a record (WORK-2,
			 * COMM-3, NOTIF-5).
			 */
			self::EDIT_DERIVED_STATE       => self::row( self::all_no(), self::all_no() ),
			self::EDIT_APPEND_ONLY         => self::row( self::all_no(), self::all_no() ),

			/*
			 * Triage (#131): setting where a request has got to, and writing
			 * the reply the client reads on their own site.
			 *
			 * Studio only, and studio staff only. A client administrator may
			 * send a request and read the answer; writing the answer is
			 * answering oneself. The client column is `no` for the usual ARCH-1
			 * reason — the client plugin contains no studio code — but it is
			 * stated rather than left implied, because this capability writes
			 * to the one record a client is otherwise the author of.
			 */
			self::REVIEW_SUBMISSION        => self::row(
				array( self::YES, self::YES, self::YES, self::NO, self::NO ),
				self::all_no()
			),

			// ---- Comment and evidence ------------------------------------

			self::COMMENT                  => self::row(
				array( self::YES, self::YES, self::YES, self::YES, self::YES ),
				array( self::NO, self::NO, self::NO, self::YES, self::YES )
			),
			self::WRITE_INTERNAL_NOTE      => self::row(
				array( self::YES, self::YES, self::YES, self::NO, 'internal_viewer' ),
				self::all_no()
			),
			self::ATTACH_EVIDENCE          => self::row(
				array( self::YES, self::YES, self::YES, self::YES, self::NO ),
				array( self::NO, self::NO, self::NO, self::YES, self::NO )
			),

			/*
			 * Answering something we asked (#133). The matrix's own row, and it
			 * is a row of its own rather than folded into `comment` because the
			 * two are not the same permission: a viewer may comment and may not
			 * answer, since an answer is a client speaking for their
			 * organisation and a comment is somebody speaking for themselves.
			 *
			 * Like the two above it, this changes no stage. AUTH-2 puts all
			 * three in the same sentence for that reason — they are what a
			 * client may do at any stage precisely because none of them is a
			 * move (§14).
			 */
			self::ANSWER_INFORMATION       => self::row(
				array( self::YES, self::YES, self::YES, self::YES, self::NO ),
				array( self::NO, self::NO, self::NO, self::YES, self::NO )
			),

			// ---- Transition ----------------------------------------------

			/*
			 * Every client cell in this block is no, on every route, and that
			 * is the client transition lock rather than an absence of grants.
			 * decide() answers these with CLIENT_LOCK so a refusal says which
			 * rule it broke.
			 */
			self::MOVE_FORWARD             => self::row(
				array( self::YES, 'own_site', 'own_site', self::NO, self::NO ),
				self::all_no()
			),
			self::RETURN_ITEM              => self::row(
				array( self::YES, self::YES, self::YES, self::NO, self::NO ),
				self::all_no()
			),
			self::BLOCK_ITEM               => self::row(
				array( self::YES, self::YES, self::YES, self::NO, self::NO ),
				self::all_no()
			),
			self::RECORD_OUTCOME           => self::row(
				array( self::YES, self::YES, self::YES, self::NO, self::NO ),
				self::all_no()
			),

			/*
			 * The two that are nobody's by rank. A Primary administrator holds
			 * them only when assigned, or through the override — which is
			 * marked on the item for ever (#112, #114).
			 */
			self::APPROVE_REVIEW           => self::row(
				array( 'assigned_reviewer', 'assigned_reviewer', 'assigned_reviewer', self::NO, self::NO ),
				self::all_no()
			),
			self::CONFIRM_RELEASE          => self::row(
				array( 'assigned_deliverer', 'assigned_deliverer', 'assigned_deliverer', self::NO, self::NO ),
				self::all_no()
			),
			self::REOPEN                   => self::row(
				array( self::YES, self::YES, self::YES, self::NO, self::NO ),
				self::all_no()
			),
			self::OVERRIDE                 => self::row(
				array( self::YES, self::NO, self::NO, self::NO, self::NO ),
				self::all_no()
			),

			// ---- Approve -------------------------------------------------

			self::APPROVE_GATE             => self::row(
				array( 'holds_approver', 'holds_approver', 'holds_approver', self::NO, self::NO ),
				self::all_no()
			),
			self::COMPLETE_GATE            => self::row(
				array( self::YES, self::YES, self::YES, self::NO, self::NO ),
				self::all_no()
			),

			/*
			 * Self-approval is refused unless the user holds Principal, and
			 * where it is used the item is marked self-reviewed for ever
			 * (AUTH-3).
			 */
			self::APPROVE_OWN_ITEM         => self::row(
				array( self::NO, self::NO, 'holds_approver', self::NO, self::NO ),
				self::all_no()
			),

			// ---- Administer ----------------------------------------------

			self::ADMINISTER_TENANCY       => self::row(
				array( self::YES, self::NO, self::NO, self::NO, self::NO ),
				self::all_no()
			),
			self::INVITE_USER              => self::row(
				array( self::YES, self::NO, self::NO, 'own_site', self::NO ),
				self::all_no()
			),
			self::GRANT_CAPABILITY         => self::row(
				array( self::YES, self::NO, self::NO, self::NO, self::NO ),
				self::all_no()
			),

			// ---- Commercial ----------------------------------------------

			self::ADMINISTER_COMMERCIAL    => self::row(
				array( self::YES, self::NO, self::NO, self::NO, self::NO ),
				self::all_no()
			),
			self::ADJUST_REVIEW_HOURS      => self::row(
				array( self::YES, 'reviewer_or_primary_user', 'reviewer_or_primary_user', self::NO, self::NO ),
				self::all_no()
			),
			self::REQUEST_UPGRADE          => self::row(
				array( self::YES, self::YES, self::YES, self::YES, self::NO ),
				array( self::NO, self::NO, self::NO, self::YES, self::NO )
			),
		);

		return $grid;
	}

	/**
	 * Every capability this map answers for.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array_keys( self::grid() );
	}

	/**
	 * Whether a string is a capability at all.
	 *
	 * @param string $capability Candidate.
	 * @return bool
	 */
	public static function exists( string $capability ): bool {
		return array_key_exists( $capability, self::grid() );
	}

	/**
	 * The one question this class answers.
	 *
	 * The context carries the actor and whatever the conditions need. Every key
	 * is optional and every missing one means "not established", which fails
	 * the condition that wanted it — a decision made on absent facts is a
	 * decision made on a guess.
	 *
	 * @param string               $capability One of the constants above.
	 * @param array<string, mixed> $context    role, principal, interface, and
	 *                                         the condition inputs.
	 * @return array{allowed: bool, code: string, reason: string, condition: string}
	 */
	public static function decide( string $capability, array $context ): array {
		if ( ! self::exists( $capability ) ) {
			return self::refuse( self::UNKNOWN, __( 'There is no such capability.', 'blueworx-forge' ) );
		}

		$interface = self::CLIENT === ( $context['interface'] ?? self::STUDIO ) ? self::CLIENT : self::STUDIO;
		$actor     = self::actor( $context );

		if ( '' === $actor ) {
			return self::refuse( self::NOT_HELD, __( 'You do not have access to this.', 'blueworx-forge' ) );
		}

		/*
		 * The client lock is answered before the grid, and for both client
		 * roles on both interfaces. A client administrator sitting in the
		 * studio is still a client, and the lock is a security boundary rather
		 * than a workflow rule — which is why the WF-5 override cannot open it.
		 */
		if ( self::is_workflow( $capability ) && self::is_client_actor( $actor ) ) {
			return self::refuse(
				self::CLIENT_LOCK,
				__( 'Client accounts never move work, by any route.', 'blueworx-forge' )
			);
		}

		// Staff code is not on the client artifact at all (ARCH-1).
		if ( self::CLIENT === $interface && ! self::is_client_actor( $actor ) ) {
			return self::refuse(
				self::WRONG_INTERFACE,
				__( 'That is a studio action; it does not exist on a client site.', 'blueworx-forge' )
			);
		}

		$cell = self::grid()[ $capability ][ $interface ][ $actor ];

		if ( self::NO === $cell ) {
			return self::refuse(
				self::is_nobodys( $capability ) ? self::NOBODY : self::NOT_HELD,
				self::is_nobodys( $capability )
					? __( 'That is not something anybody edits.', 'blueworx-forge' )
					: __( 'Your role does not include that.', 'blueworx-forge' )
			);
		}

		if ( self::YES === $cell ) {
			return self::allow();
		}

		return self::condition( $cell, $context );
	}

	/**
	 * Whether the decision said yes. For callers that want the plain answer.
	 *
	 * @param string               $capability Capability.
	 * @param array<string, mixed> $context    Context.
	 * @return bool
	 */
	public static function allows( string $capability, array $context ): bool {
		$decision = self::decide( $capability, $context );

		return $decision['allowed'];
	}

	/**
	 * Which matrix column a role and its grants land in.
	 *
	 * @param array<string, mixed> $context role, principal.
	 * @return string '' when the role is not one we know.
	 */
	public static function actor( array $context ): string {
		$role = (string) ( $context['role'] ?? '' );

		if ( ! Roles::exists( $role ) ) {
			return '';
		}

		switch ( $role ) {
			case Roles::PRIMARY_ADMIN:
				return self::PRIMARY_ADMIN;

			case Roles::STAFF:
				// Principal is a grant on a staff account, not an account of
				// its own (AUTH-3) — identical in every other respect.
				return empty( $context['principal'] ) ? self::STAFF : self::PRINCIPAL;

			case Roles::CLIENT_ADMIN:
				return self::CLIENT_ADMIN;

			default:
				// Both viewer roles share a column. The one capability they
				// differ on asks the internal_viewer condition.
				return self::VIEWER;
		}
	}

	/**
	 * The capabilities the client transition lock covers. Every workflow
	 * mutation, which is the list #115 is tested against.
	 *
	 * @return array<int, string>
	 */
	public static function workflow(): array {
		return array(
			self::MOVE_FORWARD,
			self::RETURN_ITEM,
			self::BLOCK_ITEM,
			self::RECORD_OUTCOME,
			self::APPROVE_REVIEW,
			self::CONFIRM_RELEASE,
			self::REOPEN,
			self::OVERRIDE,
			self::COMPLETE_GATE,
			self::APPROVE_GATE,
			self::EDIT_WORKFLOW,
		);
	}

	/**
	 * Whether this capability is one the lock covers.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	public static function is_workflow( string $capability ): bool {
		return in_array( $capability, self::workflow(), true );
	}

	/**
	 * Whether an actor is one of the client's own people.
	 *
	 * @param string $actor Actor key.
	 * @return bool
	 */
	private static function is_client_actor( string $actor ): bool {
		return self::CLIENT_ADMIN === $actor || self::VIEWER === $actor;
	}

	/**
	 * Whether a capability is one nobody holds.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	private static function is_nobodys( string $capability ): bool {
		return in_array( $capability, array( self::EDIT_DERIVED_STATE, self::EDIT_APPEND_ONLY ), true );
	}

	/**
	 * Answers a conditional cell.
	 *
	 * Each condition is one fact from the context, named after the condition
	 * paragraph beneath its block in the matrix document, and each refusal says
	 * which condition failed rather than only that one did.
	 *
	 * @param string               $condition Condition name.
	 * @param array<string, mixed> $context   Context.
	 * @return array{allowed: bool, code: string, reason: string, condition: string}
	 */
	private static function condition( string $condition, array $context ): array {
		switch ( $condition ) {
			case 'own_site':
				return self::when(
					! empty( $context['own_site'] ),
					$condition,
					self::NOT_YOUR_SITE,
					__( 'That belongs to a site your membership does not reach.', 'blueworx-forge' )
				);

			case 'internal_viewer':
				return self::when(
					Roles::INTERNAL_VIEWER === (string) ( $context['role'] ?? '' ),
					$condition,
					self::NOT_HELD,
					__( 'Internal notes are not shown to client accounts.', 'blueworx-forge' )
				);

			case 'active_package':
				return self::when(
					! empty( $context['active_package'] ),
					$condition,
					self::CONDITION_FAILED,
					__( 'That site has no active package, so work cannot be raised on it.', 'blueworx-forge' )
				);

			case 'before_documentation_ends':
				return self::when(
					! empty( $context['before_documentation_ends'] ),
					$condition,
					self::CONDITION_FAILED,
					__( 'This has left Documentation Period, so it is comment-and-request-change only.', 'blueworx-forge' )
				);

			case 'assigned_reviewer':
				return self::when(
					! empty( $context['assigned_reviewer'] ),
					$condition,
					self::CONDITION_FAILED,
					__( 'Only the assigned Reviewer or an authorised substitute approves a review.', 'blueworx-forge' )
				);

			case 'assigned_deliverer':
				return self::when(
					! empty( $context['assigned_deliverer'] ),
					$condition,
					self::CONDITION_FAILED,
					__( 'Only the assigned Deliverer or an authorised substitute confirms a release.', 'blueworx-forge' )
				);

			case 'holds_approver':
				return self::when(
					! empty( $context['holds_approver'] ),
					$condition,
					self::CONDITION_FAILED,
					__( 'That gate needs its Approver capability, which you do not hold.', 'blueworx-forge' )
				);

			case 'reviewer_or_primary_user':
				return self::when(
					! empty( $context['assigned_reviewer'] ) || ! empty( $context['assigned_primary_user'] ),
					$condition,
					self::CONDITION_FAILED,
					__( 'Only the item\'s Reviewer or its Primary User adjusts those hours.', 'blueworx-forge' )
				);

			default:
				// An unrecognised condition is a refusal, never a grant. A
				// typo in the grid must not become an open door.
				return self::refuse( self::CONDITION_FAILED, __( 'That cannot be allowed here.', 'blueworx-forge' ) );
		}
	}

	/**
	 * A decision from a condition's answer.
	 *
	 * @param bool   $met       Whether the condition held.
	 * @param string $condition Its name.
	 * @param string $code      The code to refuse with.
	 * @param string $reason    What to say.
	 * @return array{allowed: bool, code: string, reason: string, condition: string}
	 */
	private static function when( bool $met, string $condition, string $code, string $reason ): array {
		if ( $met ) {
			return array(
				'allowed'   => true,
				'code'      => self::GRANTED,
				'reason'    => '',
				'condition' => $condition,
			);
		}

		return array(
			'allowed'   => false,
			'code'      => $code,
			'reason'    => $reason,
			'condition' => $condition,
		);
	}

	/**
	 * An unconditional yes.
	 *
	 * @return array{allowed: bool, code: string, reason: string, condition: string}
	 */
	private static function allow(): array {
		return array(
			'allowed'   => true,
			'code'      => self::GRANTED,
			'reason'    => '',
			'condition' => '',
		);
	}

	/**
	 * A no, with its reason.
	 *
	 * @param string $code   Why.
	 * @param string $reason What to say.
	 * @return array{allowed: bool, code: string, reason: string, condition: string}
	 */
	private static function refuse( string $code, string $reason ): array {
		return array(
			'allowed'   => false,
			'code'      => $code,
			'reason'    => $reason,
			'condition' => '',
		);
	}

	/**
	 * One row of the grid, from its studio and client cells in column order.
	 *
	 * @param array<int, string> $studio Five cells.
	 * @param array<int, string> $client Five cells.
	 * @return array<string, array<string, string>>
	 */
	private static function row( array $studio, array $client ): array {
		return array(
			self::STUDIO => array_combine( self::ACTORS, $studio ),
			self::CLIENT => array_combine( self::ACTORS, $client ),
		);
	}

	/**
	 * Five noes.
	 *
	 * @return array<int, string>
	 */
	private static function all_no(): array {
		return array_fill( 0, count( self::ACTORS ), self::NO );
	}
}
