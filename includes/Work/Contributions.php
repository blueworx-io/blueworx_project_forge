<?php
/**
 * What a client may add to their work without moving it.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * Permitted non-transition client actions (#133): the rules, with no database
 * behind them.
 *
 * AUTH-2 grants a client three things at any stage — comment, attach evidence,
 * answer an information request — and §14 locks every stage change away from
 * them entirely. Those two sentences are usually enforced in different places,
 * and that is exactly how a product ends up with a client contribution that
 * moves work: the grant is written in one file and the lock in another, and
 * something in between quietly does both.
 *
 * **So the lock is a property of this class rather than a rule beside it.**
 * There is no stage in here. No method returns one, none takes one, and
 * {@see self::read()} drops every key it does not recognise — so a client
 * contribution carrying `stage`, `to`, `transition` or anything else arrives at
 * the writer as a comment with a body, because that is all this can produce.
 * The refusal is structural: the class cannot express a move.
 *
 * The one contribution that is not simply words is the answer, and it is the
 * one with a rule of its own. An answer names the question it answers, and the
 * question has to be one actually outstanding on this item — not on another
 * item, not one already answered, and not an id the caller invented. Otherwise
 * "the studio is waiting on the client" would be a flag a client could clear by
 * sending the right string.
 */
final class Contributions {

	/**
	 * Nothing to refuse.
	 */
	public const ALLOWED = '';

	/**
	 * A kind of entry a client does not write.
	 */
	public const NOT_THEIRS = 'not_a_client_contribution';

	/**
	 * Nothing was said and nothing was linked.
	 */
	public const EMPTY_ENTRY = 'nothing_to_record';

	/**
	 * Evidence with nothing to look at.
	 */
	public const EVIDENCE_UNSUPPORTED = 'evidence_without_a_link';

	/**
	 * An answer to a question that is not outstanding on this item.
	 */
	public const NO_SUCH_QUESTION = 'no_such_question';

	/**
	 * Longest a link may be, matching the column it lands in.
	 */
	public const MAX_URL = 255;

	/**
	 * Reads a contribution, keeping only what a client may say.
	 *
	 * The whole surface, and deliberately a short one. Everything a caller
	 * sends that is not one of these four keys is gone by the time anything
	 * else sees it — which is what makes "a client contribution never changes
	 * stage" a fact about the shape of the data rather than a check somebody
	 * has to remember to run.
	 *
	 * @param array<string, mixed> $input Whatever arrived.
	 * @return array<string, mixed>
	 */
	public static function read( array $input ): array {
		return array(
			'kind'    => trim( (string) ( $input['kind'] ?? Comments::COMMENT ) ),
			'body'    => trim( (string) ( $input['body'] ?? '' ) ),
			'url'     => mb_substr( trim( (string) ( $input['url'] ?? '' ) ), 0, self::MAX_URL ),
			'answers' => trim( (string) ( $input['answers'] ?? '' ) ),
		);
	}

	/**
	 * Whether a contribution is refused, and why.
	 *
	 * The outstanding questions are passed in already read, for the same reason
	 * Work\Conversion is handed its records: the rule is testable without a
	 * site, and the caller has already scoped the read to this item and this
	 * client.
	 *
	 * @param array<string, mixed>             $asked       Already through read().
	 * @param array<int, array<string, mixed>> $outstanding The questions waiting
	 *                                                      on this item.
	 * @return string One of the codes above; ALLOWED when there is nothing to
	 *                refuse.
	 */
	public static function refuse( array $asked, array $outstanding = array() ): string {
		$kind = (string) $asked['kind'];

		if ( ! in_array( $kind, Comments::CLIENT_KINDS, true ) ) {
			return self::NOT_THEIRS;
		}

		if ( Comments::COMMENT !== $kind && '' === (string) $asked['url'] ) {
			return self::EVIDENCE_UNSUPPORTED;
		}

		if ( '' === (string) $asked['body'] && '' === (string) $asked['url'] ) {
			return self::EMPTY_ENTRY;
		}

		$answers = (string) $asked['answers'];

		if ( '' === $answers ) {
			return self::ALLOWED;
		}

		if ( Comments::COMMENT !== $kind ) {
			// Evidence answers nothing on its own. Somebody attaching a
			// screenshot in reply writes a sentence with it, and that sentence
			// is what the answer is.
			return self::NO_SUCH_QUESTION;
		}

		foreach ( $outstanding as $question ) {
			if ( (string) ( $question['id'] ?? '' ) === $answers ) {
				return self::ALLOWED;
			}
		}

		return self::NO_SUCH_QUESTION;
	}

	/**
	 * The entry a contribution becomes.
	 *
	 * The item, its site and its client come from the item that was looked up,
	 * never from the body — the same rule everything else on the client
	 * connection follows (D-1, D-2). What the caller supplied is the words, the
	 * link, and which question is being answered.
	 *
	 * Written as an explicit list rather than a merge, so that adding a key to
	 * {@see self::read()} does not silently add a column a client can write.
	 *
	 * @param array<string, mixed> $asked  Already through read().
	 * @param array<string, mixed> $item   The work being contributed to.
	 * @param string               $site   The registry site that signed for it.
	 * @param string               $author Who the client site says was typing.
	 * @return array<string, mixed>
	 */
	public static function entry( array $asked, array $item, string $site, string $author ): array {
		return array(
			'item_id'        => (string) ( $item['id'] ?? '' ),
			'client_site_id' => (string) ( $item['client_site_id'] ?? '' ),
			'client_id'      => (string) ( $item['client_id'] ?? '' ),
			'kind'           => (string) $asked['kind'],
			'visibility'     => Comments::CLIENT,
			'body'           => (string) $asked['body'],
			'url'            => (string) $asked['url'],
			'answers'        => (string) $asked['answers'],
			'author'         => 0,
			'author_site'    => $site,
			'author_name'    => mb_substr( trim( $author ), 0, 191 ),
		);
	}

	/**
	 * Which capability a contribution exercises.
	 *
	 * Three kinds, three rows of the matrix, because a client viewer holds one
	 * of them and not the other two. Asking one question for all three would
	 * give whoever may comment the ability to speak for the organisation.
	 *
	 * @param array<string, mixed> $asked Already through read().
	 * @return string A Tenancy\Capabilities constant.
	 */
	public static function capability( array $asked ): string {
		if ( '' !== (string) $asked['answers'] ) {
			return \Blueworx\Forge\Tenancy\Capabilities::ANSWER_INFORMATION;
		}

		return Comments::COMMENT === (string) $asked['kind']
			? \Blueworx\Forge\Tenancy\Capabilities::COMMENT
			: \Blueworx\Forge\Tenancy\Capabilities::ATTACH_EVIDENCE;
	}

	/**
	 * The words a refusal is shown in.
	 *
	 * @param string $code One of the codes above.
	 * @return string
	 */
	public static function reason( string $code ): string {
		switch ( $code ) {
			case self::NOT_THEIRS:
				return __( 'That is not something you can add here.', 'blueworx-forge' );
			case self::EMPTY_ENTRY:
				return __( 'There is nothing to send. Write something, or add a link.', 'blueworx-forge' );
			case self::EVIDENCE_UNSUPPORTED:
				return __( 'Evidence needs a link to what you are showing us.', 'blueworx-forge' );
			case self::NO_SUCH_QUESTION:
				return __( 'That question is not waiting on an answer.', 'blueworx-forge' );
			default:
				return __( 'That could not be sent.', 'blueworx-forge' );
		}
	}
}
