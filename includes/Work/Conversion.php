<?php
/**
 * Turning a request a client made into work the studio does.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * Controlled pipeline conversion (#132): the rules, with no database behind
 * them.
 *
 * Conversion is the one moment where a record the client authored becomes a
 * record we own, and the two are on different sides of REQ-1. So there are
 * exactly two things this has to get right, and everything here is one of them:
 *
 * - **The work lands in the same client's pipeline as the request.** Not
 *   whichever site the body names — there is no such parameter — but the site
 *   the submission itself came from, which was fixed by the signature that
 *   carried it (#129). A parent, or an item being linked, that sits anywhere
 *   else is refused. That is D-40, and it is refused here rather than trusted
 *   to a caller passing the right ids.
 * - **The submission's text survives untouched.** Conversion writes the link
 *   and the intake state and nothing else. What the client asked for is not
 *   copied over the top of itself, not normalised, not "tidied" — the work item
 *   gets its own copy of the words to work from, and the submission keeps the
 *   original.
 *
 * **The site is never an input.** Everywhere else in this class an id is
 * something a caller sends and this checks; the client site is not, and that
 * asymmetry is deliberate. A conversion route that took a site and validated it
 * would be one validation bug away from D-40; a route that has no site
 * parameter at all cannot have that bug. So the site is read off the submission
 * and the caller's ids are checked *against* it.
 *
 * Like Tenancy\Capabilities, this reads nothing. It is handed the submission,
 * the proposed parent and the item being linked — already fetched, by a caller
 * that has already been through the tenant boundary — and answers from them.
 * That is what lets every rule below be tested without a WordPress install, and
 * it is why the refusal codes are values rather than sentences: the route turns
 * them into an answer, and one place decides what each of them means.
 */
final class Conversion {

	/**
	 * Where converted work may enter the pipeline.
	 *
	 * Two, not twelve. Future Idea is where everything starts, and Triage is
	 * the one step forward that conversion has genuinely already done — the
	 * studio read the request, decided it was real and decided which client it
	 * belongs to, which is what the Future Idea gate asks for. Anything beyond
	 * that is work nobody has done yet, and a route that could drop an item
	 * into Documentation Period would be a route that skips a gate by naming a
	 * stage.
	 *
	 * @var array<int, string>
	 */
	public const ENTRY_STAGES = array( Stages::FIRST, 'triage' );

	/**
	 * The intake states a request may be converted from.
	 *
	 * Accepted, because that is the decision conversion acts on. Received and
	 * in-review too, because triage is not always two separate sittings — a
	 * studio that reads a request and immediately makes the work should not
	 * have to save "accepted" first in order to be allowed to. Declined is not
	 * here: converting something we told the client we were not doing is either
	 * a mistake or a reply we owe them first.
	 *
	 * @var array<int, string>
	 */
	public const FROM_STATES = array( 'received', 'in-review', 'accepted' );

	/**
	 * Nothing to refuse.
	 */
	public const ALLOWED = '';

	/**
	 * This request has already become work.
	 */
	public const ALREADY_CONVERTED = 'already_converted';

	/**
	 * The request was declined; there is nothing to convert.
	 */
	public const NOT_CONVERTIBLE = 'not_convertible';

	/**
	 * The entry stage named is not one of the two.
	 */
	public const BAD_ENTRY_STAGE = 'bad_entry_stage';

	/**
	 * Both linking to existing work and creating new work were asked for.
	 */
	public const AMBIGUOUS_TARGET = 'ambiguous_target';

	/**
	 * Both an existing parent and a new one were asked for.
	 */
	public const AMBIGUOUS_PARENT = 'ambiguous_parent';

	/**
	 * A new parent was asked for with no title to give it.
	 */
	public const PARENT_UNNAMED = 'parent_unnamed';

	/**
	 * The level asked for cannot sit above the work beneath it.
	 */
	public const BAD_PARENT_LEVEL = 'bad_parent_level';

	/**
	 * The parent is on another client's site, or is not there at all. One code,
	 * on purpose: see {@see self::refuse_parent()}.
	 */
	public const UNKNOWN_PARENT = 'unknown_parent';

	/**
	 * The work being linked is on another client's site, or is not there at
	 * all.
	 */
	public const UNKNOWN_TARGET = 'unknown_target';

	/**
	 * The level converted work is created at.
	 *
	 * A Sub-feature, always. The programme calls it "the Sub-item" and means
	 * it: a request is a thing somebody wants done, and the rung work actually
	 * gets done on is this one. Somebody who needs it to be a Feature promotes
	 * it afterwards, which is an edit with a changelog entry rather than a
	 * choice buried in a conversion.
	 */
	public const LEVEL = Levels::SUB_FEATURE;

	/**
	 * Whether a stage is one converted work may enter at.
	 *
	 * @param string $stage Candidate.
	 * @return bool
	 */
	public static function is_entry_stage( string $stage ): bool {
		return in_array( $stage, self::ENTRY_STAGES, true );
	}

	/**
	 * Whether a submission is in a state that may be converted at all.
	 *
	 * @param array<string, mixed> $submission The submission row.
	 * @return bool
	 */
	public static function is_convertible( array $submission ): bool {
		return '' === (string) ( $submission['converted_item_id'] ?? '' )
			&& in_array( (string) ( $submission['intake_state'] ?? '' ), self::FROM_STATES, true );
	}

	/**
	 * Reads a conversion request, keeping only what this understands.
	 *
	 * Everything absent from here is dropped rather than carried through, which
	 * is what stops a body naming a client, a site or a stage from reaching the
	 * write. The one field that decides which pipeline the work lands in is not
	 * on this list and never arrives from a caller at all.
	 *
	 * @param array<string, mixed> $input Whatever arrived.
	 * @return array<string, mixed>
	 */
	public static function read( array $input ): array {
		return array(
			'entry_stage'  => trim( (string) ( $input['entry_stage'] ?? Stages::FIRST ) ),
			'item_id'      => trim( (string) ( $input['item_id'] ?? '' ) ),
			'parent_id'    => trim( (string) ( $input['parent_id'] ?? '' ) ),
			'parent_title' => trim( (string) ( $input['parent_title'] ?? '' ) ),
			'parent_level' => trim( (string) ( $input['parent_level'] ?? '' ) ),
			'title'        => trim( (string) ( $input['title'] ?? '' ) ),
			'work_type'    => trim( (string) ( $input['work_type'] ?? '' ) ),
		);
	}

	/**
	 * Whether this conversion links existing work rather than creating some.
	 *
	 * @param array<string, mixed> $asked Already through read().
	 * @return bool
	 */
	public static function links( array $asked ): bool {
		return '' !== (string) ( $asked['item_id'] ?? '' );
	}

	/**
	 * Whether this conversion creates a parent along the way.
	 *
	 * @param array<string, mixed> $asked Already through read().
	 * @return bool
	 */
	public static function creates_parent( array $asked ): bool {
		return '' !== (string) ( $asked['parent_title'] ?? '' ) || '' !== (string) ( $asked['parent_level'] ?? '' );
	}

	/**
	 * Whether this conversion is refused, and why.
	 *
	 * The records are passed in already fetched, and null means the caller
	 * could not find one. That is not the same question as "is it allowed", and
	 * keeping them apart is what lets a missing record and somebody else's
	 * record share one answer without this class needing to know how either was
	 * looked up.
	 *
	 * @param array<string, mixed>      $submission The request being converted.
	 * @param array<string, mixed>      $asked      Already through read().
	 * @param array<string, mixed>|null $parent     The proposed parent, if one
	 *                                              was named and found.
	 * @param array<string, mixed>|null $target     The work being linked, if one
	 *                                              was named and found.
	 * @return string One of the codes above; ALLOWED when there is nothing to
	 *                refuse.
	 */
	public static function refuse( array $submission, array $asked, ?array $parent = null, ?array $target = null ): string {
		if ( '' !== (string) ( $submission['converted_item_id'] ?? '' ) ) {
			return self::ALREADY_CONVERTED;
		}

		if ( ! self::is_convertible( $submission ) ) {
			return self::NOT_CONVERTIBLE;
		}

		if ( ! self::is_entry_stage( (string) $asked['entry_stage'] ) ) {
			return self::BAD_ENTRY_STAGE;
		}

		$site = (string) ( $submission['client_site_id'] ?? '' );

		if ( self::links( $asked ) ) {
			/*
			 * Linking takes the work as it is, wherever it already sits. A
			 * parent sent alongside would be an instruction to re-parent
			 * somebody else's item as a side effect of answering a request,
			 * which is an edit with its own permission and its own changelog
			 * entry — not something conversion does quietly.
			 */
			if ( '' !== (string) $asked['parent_id'] || self::creates_parent( $asked ) ) {
				return self::AMBIGUOUS_TARGET;
			}

			return self::refuse_record( $target, $site, self::UNKNOWN_TARGET );
		}

		return self::refuse_parent( $asked, $parent, $site );
	}

	/**
	 * The values a converted item is created with.
	 *
	 * The client's words are copied into the item's own fields rather than left
	 * to be read through the link, because the two records answer different
	 * questions from here on. The submission is what was asked, fixed forever;
	 * the item is what we are doing about it, and it is edited, refined and
	 * argued with all the way to Released. A work item that rendered the
	 * client's paragraph as its problem statement would make every edit to that
	 * statement an edit to the client's own words.
	 *
	 * `problem` is filled because the Future Idea gate asks for it, and because
	 * a request with no problem statement is a request nobody wrote down the
	 * reason for. The client's description is the honest first draft of it.
	 *
	 * @param array<string, mixed> $submission The request being converted.
	 * @param array<string, mixed> $asked      Already through read().
	 * @param string               $parent_id  The parent, once it is known.
	 * @return array<string, mixed>
	 */
	public static function values( array $submission, array $asked, string $parent_id = '' ): array {
		$title = (string) $asked['title'];

		return array(
			'parent_id' => $parent_id,
			'level'     => self::LEVEL,
			'work_type' => self::work_type( (string) $asked['work_type'] ),
			'title'     => '' === $title ? (string) ( $submission['title'] ?? '' ) : $title,
			'problem'   => (string) ( $submission['description'] ?? '' ),
		);
	}

	/**
	 * The values a parent created during conversion is created with.
	 *
	 * A title and a level, and nothing borrowed from the request. A parent is
	 * the thing this piece of work belongs *under*, so filling its problem
	 * statement in with one client's paragraph would put that paragraph at the
	 * head of everything else that ever hangs beneath it.
	 *
	 * @param array<string, mixed> $asked Already through read().
	 * @return array<string, mixed>
	 */
	public static function parent_values( array $asked ): array {
		return array(
			'parent_id' => '',
			'level'     => (string) $asked['parent_level'],
			'work_type' => Types::TASK,
			'title'     => (string) $asked['parent_title'],
		);
	}

	/**
	 * The words a refusal is shown in.
	 *
	 * Held here rather than in the route so that the reason a conversion was
	 * refused reads the same wherever it is attempted from, and so the two
	 * "that is not there" cases cannot drift into two different sentences —
	 * which is the whole point of them sharing a code.
	 *
	 * @param string $code One of the codes above.
	 * @return string
	 */
	public static function reason( string $code ): string {
		switch ( $code ) {
			case self::ALREADY_CONVERTED:
				return __( 'That request has already become work.', 'blueworx-forge' );
			case self::NOT_CONVERTIBLE:
				return __( 'A request that has been turned down cannot be converted. Reopen it first by setting where it has got to.', 'blueworx-forge' );
			case self::BAD_ENTRY_STAGE:
				return __( 'Converted work starts at Future Idea or Triage.', 'blueworx-forge' );
			case self::AMBIGUOUS_TARGET:
				return __( 'Link this to work that already exists, or make new work under a parent — not both.', 'blueworx-forge' );
			case self::AMBIGUOUS_PARENT:
				return __( 'Choose a parent or create one, not both.', 'blueworx-forge' );
			case self::PARENT_UNNAMED:
				return __( 'A new parent needs a title and a level.', 'blueworx-forge' );
			case self::BAD_PARENT_LEVEL:
				return __( 'A parent has to be a higher level than the work beneath it.', 'blueworx-forge' );
			case self::UNKNOWN_PARENT:
				return __( 'There is no such parent item.', 'blueworx-forge' );
			case self::UNKNOWN_TARGET:
				return __( 'There is no such work item.', 'blueworx-forge' );
			default:
				return __( 'That conversion could not be made.', 'blueworx-forge' );
		}
	}

	/**
	 * Which status code a refusal answers with.
	 *
	 * The two "not there" codes answer 404 and everything else answers 409,
	 * because they are different kinds of no. A named record that is somebody
	 * else's has to be indistinguishable from one that never existed (D-1,
	 * D-2), and 404 is the answer an unused id already gets. The rest are
	 * refusals of a request that named nothing it should not have — the caller
	 * is entitled to be told what was wrong with it.
	 *
	 * @param string $code One of the codes above.
	 * @return int
	 */
	public static function status( string $code ): int {
		return in_array( $code, array( self::UNKNOWN_PARENT, self::UNKNOWN_TARGET ), true ) ? 404 : 409;
	}

	/**
	 * Whether the parent half of a conversion is refused, and why.
	 *
	 * A parent is optional. Work that belongs under nothing is ordinary — a
	 * one-off task on a site with no project around it — so an absent parent is
	 * not a missing field.
	 *
	 * @param array<string, mixed>      $asked  Already through read().
	 * @param array<string, mixed>|null $parent The proposed parent, if found.
	 * @param string                    $site   The submission's own site.
	 * @return string
	 */
	private static function refuse_parent( array $asked, ?array $parent, string $site ): string {
		$named   = (string) $asked['parent_id'];
		$creates = self::creates_parent( $asked );

		if ( '' !== $named && $creates ) {
			return self::AMBIGUOUS_PARENT;
		}

		if ( $creates ) {
			if ( '' === (string) $asked['parent_title'] || '' === (string) $asked['parent_level'] ) {
				return self::PARENT_UNNAMED;
			}

			return Levels::may_parent( (string) $asked['parent_level'], self::LEVEL )
				? self::ALLOWED
				: self::BAD_PARENT_LEVEL;
		}

		if ( '' === $named ) {
			return self::ALLOWED;
		}

		$refusal = self::refuse_record( $parent, $site, self::UNKNOWN_PARENT );

		if ( self::ALLOWED !== $refusal ) {
			return $refusal;
		}

		return Levels::may_parent( (string) ( $parent['level'] ?? '' ), self::LEVEL )
			? self::ALLOWED
			: self::BAD_PARENT_LEVEL;
	}

	/**
	 * Whether a named record is usable: it exists, and it is on this client's
	 * own site.
	 *
	 * **Both failures share one answer**, and that is the D-40 guarantee rather
	 * than a convenience. "That item is real but belongs to another client"
	 * tells a caller which ids exist on a tenant they have no business knowing
	 * about, and it does it from the one screen that spans clients. So an item
	 * on the wrong site is answered exactly as an id nobody has ever used.
	 *
	 * @param array<string, mixed>|null $record The record, if the caller found it.
	 * @param string                    $site   The submission's own site.
	 * @param string                    $code   Which "not there" this is.
	 * @return string
	 */
	private static function refuse_record( ?array $record, string $site, string $code ): string {
		if ( null === $record ) {
			return $code;
		}

		return (string) ( $record['client_site_id'] ?? '' ) === $site && '' !== $site
			? self::ALLOWED
			: $code;
	}

	/**
	 * What kind of work this is, defaulting to a task.
	 *
	 * The client chose between a request, an idea and a suggestion, and none of
	 * those is a work type — they describe how firmly somebody is asking, not
	 * what the job is. Mapping one onto the other would be the studio guessing,
	 * so the converting person says, and a task is the answer that claims the
	 * least when they do not.
	 *
	 * @param string $asked What the body named, if anything.
	 * @return string
	 */
	private static function work_type( string $asked ): string {
		return Types::exists( $asked ) ? $asked : Types::TASK;
	}
}
