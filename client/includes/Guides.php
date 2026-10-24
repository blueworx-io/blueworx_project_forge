<?php
/**
 * The guides Forge gives a client.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * How Forge works, written for the client, in one place (#580).
 *
 * Shown twice from this one list: on the BlueWorx Guides page, as a "Forge"
 * product, and inside the client workspace as its Guides tab. The page needs
 * `edit_posts`, which a Forge: Manager does not hold, so the workspace is the
 * place most clients will actually read them.
 *
 * Text in *asterisks* is a button or field label. The guides page shows it as
 * emphasis through its own helper; the workspace does the same.
 */
final class Guides {

	/**
	 * The product row on the BlueWorx Guides page.
	 */
	public const PRODUCT = 'forge';

	/**
	 * Hooks the guides into the BlueWorx Guides page, when that plugin is
	 * there. The filters do nothing on a site without it.
	 */
	public static function boot(): void {
		add_filter( 'blueworx_guides', array( self::class, 'add_guides' ) );
		add_filter( 'blueworx_guide_tabs', array( self::class, 'add_tabs' ) );
		add_filter( 'blueworx_guide_products', array( self::class, 'add_products' ) );
		add_filter( 'blueworx_guide_tab_products', array( self::class, 'add_tab_products' ) );
	}

	/**
	 * The tabs, in order.
	 *
	 * @return array<string, string> Tab id => label.
	 */
	public static function tabs(): array {
		return array(
			'forge-start'   => __( 'Getting started', 'blueworx-forge' ),
			'forge-work'    => __( 'Following the work', 'blueworx-forge' ),
			'forge-account' => __( 'Your account', 'blueworx-forge' ),
		);
	}

	/**
	 * The guides, as plain parts.
	 *
	 * @return array<int, array{id: string, title: string, tab: string, where: string, steps: array<int, string>, then: string}>
	 */
	public static function definitions(): array {
		return array(
			array(
				'id'    => 'forge-getting-around',
				'title' => __( 'Getting around Forge', 'blueworx-forge' ),
				'tab'   => 'forge-start',
				'where' => __( 'Forge, in the menu on the left of your WordPress admin', 'blueworx-forge' ),
				'steps' => array(
					__( 'Open *Forge*. Under it you will find Overview, New Request, Requests, Work Board, Timeline, Calendar and Onboarding.', 'blueworx-forge' ),
					__( 'Choose *Workspace (opens in a new tab)* to open the Forge workspace on your own site, at /forge/.', 'blueworx-forge' ),
					__( 'In the workspace, use the tabs along the top: Dashboard, Your work, Projects, Bugs & requests, Support & hours, Launch readiness and Guides.', 'blueworx-forge' ),
				),
				'then'  => __( 'Use whichever you find easier. Some things, like reviewing finished work, are in the workspace only.', 'blueworx-forge' ),
			),
			array(
				'id'    => 'forge-ask-for-something',
				'title' => __( 'Asking for something', 'blueworx-forge' ),
				'tab'   => 'forge-start',
				'where' => __( 'Forge > New Request, or the workspace under Bugs & requests', 'blueworx-forge' ),
				'steps' => array(
					__( 'Under *What is this?* pick one: Something is broken, A request, An idea or A suggestion.', 'blueworx-forge' ),
					__( 'Fill in *Title* and *What you are asking for*.', 'blueworx-forge' ),
					__( 'If it helps, add *What good would look like*, *Anything that helps* and *A screenshot*. All three are optional.', 'blueworx-forge' ),
					__( 'Press *Send to the studio*. In the workspace, open Bugs & requests, press *New submission* and fill in the same boxes.', 'blueworx-forge' ),
				),
				'then'  => __( 'The studio gets it straight away and will reply. It cannot be edited after you send it, so if you change your mind, send another one.', 'blueworx-forge' ),
			),
			array(
				'id'    => 'forge-follow-up-a-request',
				'title' => __( 'Following up a request', 'blueworx-forge' ),
				'tab'   => 'forge-start',
				'where' => __( 'Forge > Requests', 'blueworx-forge' ),
				'steps' => array(
					__( 'Find your request in the list. Its status moves from Received to Being looked at, and ends as Accepted, Not going ahead or Became work.', 'blueworx-forge' ),
					__( 'Read *Reply from the studio* for what the studio said about it.', 'blueworx-forge' ),
					__( 'If it became work, *This became* links to that work.', 'blueworx-forge' ),
				),
				'then'  => __( 'You get an email called "We have your request" when the studio first receives it. After that, this list is where to look.', 'blueworx-forge' ),
			),
			array(
				'id'    => 'forge-review-finished-work',
				'title' => __( 'Reviewing finished work', 'blueworx-forge' ),
				'tab'   => 'forge-work',
				'where' => __( 'The Forge workspace > Your work', 'blueworx-forge' ),
				'steps' => array(
					__( 'Open *Needs your review*. It lists what the studio has finished and asked you to check.', 'blueworx-forge' ),
					__( 'Happy with it? Press *Approve*. The studio is told.', 'blueworx-forge' ),
					__( 'Not right yet? Press *Send back*, write what needs to change in *What needs to change*, and press *Send back to the studio*.', 'blueworx-forge' ),
				),
				'then'  => __( 'You get an email called "Ready for your review" when something is waiting for you.', 'blueworx-forge' ),
			),
			array(
				'id'    => 'forge-answer-and-comment',
				'title' => __( 'Answering the studio and commenting', 'blueworx-forge' ),
				'tab'   => 'forge-work',
				'where' => __( 'Forge > Work Board, or the workspace under Your work', 'blueworx-forge' ),
				'steps' => array(
					__( 'Open a card.', 'blueworx-forge' ),
					__( 'If you see *The studio has asked you something*, type your reply in *Your answer* and press *Send this answer*.', 'blueworx-forge' ),
					__( 'To add something of your own, use *Say something about this*. Write *Your comment*, add a link if you have one, and press *Send to the studio*.', 'blueworx-forge' ),
				),
				'then'  => __( 'The studio sees it straight away. Commenting does not move the work to a different stage.', 'blueworx-forge' ),
			),
			array(
				'id'    => 'forge-see-the-work',
				'title' => __( 'Seeing what is being worked on', 'blueworx-forge' ),
				'tab'   => 'forge-work',
				'where' => __( 'Forge > Work Board, Timeline and Calendar', 'blueworx-forge' ),
				'steps' => array(
					__( 'Work Board shows every piece of work in its stage: Future idea, Triage, Bug tracking, Documentation period, Design process, Blocked, Up next, In development, In review, Completed and Released.', 'blueworx-forge' ),
					__( 'Timeline shows when work is planned. Calendar marks the dates that matter: Starts, Due, Review and Release.', 'blueworx-forge' ),
					__( 'In the workspace, Your work has three views: Kanban, Schedule and Key dates.', 'blueworx-forge' ),
				),
				'then'  => __( 'You can read and open anything, but you cannot move cards. Moving work between stages is the studio\'s job.', 'blueworx-forge' ),
			),
			array(
				'id'    => 'forge-onboarding',
				'title' => __( 'Onboarding and launch readiness', 'blueworx-forge' ),
				'tab'   => 'forge-account',
				'where' => __( 'Forge > Onboarding, or the workspace under Launch readiness', 'blueworx-forge' ),
				'steps' => array(
					__( 'Open a step that is yours to do.', 'blueworx-forge' ),
					__( 'Say what you have done in *What have you done?*.', 'blueworx-forge' ),
					__( 'If it is asked for, use *Attach something (optional)*. Files can be up to 10MB. Never send passwords.', 'blueworx-forge' ),
					__( 'Press *Save* to keep a draft, or *Send to us* when it is ready.', 'blueworx-forge' ),
				),
				'then'  => __( 'A step goes from Not started to In progress, then With us to check, then Done, or Needs another look if we need more. "This one is ours to do" means the studio is handling that step.', 'blueworx-forge' ),
			),
			array(
				'id'    => 'forge-support-hours',
				'title' => __( 'Support hours and buying more', 'blueworx-forge' ),
				'tab'   => 'forge-account',
				'where' => __( 'Forge > Overview, or the workspace under Support & hours', 'blueworx-forge' ),
				'steps' => array(
					__( 'On Overview, *Where you stand* shows your hours and *What you have bought* shows what you have bought.', 'blueworx-forge' ),
					__( 'In the workspace, *Your plan* shows Package hours, Top-ups, Used and Remaining.', 'blueworx-forge' ),
					__( 'To get more, see *More hours* on Overview and press *Ask about hours*.', 'blueworx-forge' ),
				),
				'then'  => __( 'Your point of contact at the studio will get back to you about packages and top-ups.', 'blueworx-forge' ),
			),
			array(
				'id'    => 'forge-emails',
				'title' => __( 'Emails you will get', 'blueworx-forge' ),
				'tab'   => 'forge-account',
				'where' => __( 'Your inbox', 'blueworx-forge' ),
				'steps' => array(
					__( '"We have your request": the studio received something you sent. Nothing to do.', 'blueworx-forge' ),
					__( '"Ready for your review": open Forge and approve it or send it back.', 'blueworx-forge' ),
					__( '"Ready to go live": the work is done and approved but not live yet. Nothing to do, unless something is wrong, in which case reply.', 'blueworx-forge' ),
					__( '"Now live": it has gone out. Have a look and reply if anything is not as you expected.', 'blueworx-forge' ),
				),
				'then'  => __( 'Each email has the name of the work in its subject.', 'blueworx-forge' ),
			),
		);
	}

	/**
	 * Adds the guides to the BlueWorx Guides page.
	 *
	 * @param mixed $guides What is registered so far.
	 * @return array<int, array<string, mixed>>
	 */
	public static function add_guides( $guides ): array {
		$guides = is_array( $guides ) ? $guides : array();

		foreach ( self::definitions() as $guide ) {
			$guides[] = array(
				'id'         => $guide['id'],
				'title'      => $guide['title'],
				'tab'        => $guide['tab'],
				'body'       => self::body( $guide ),
				'capability' => Access::USE,
			);
		}

		return $guides;
	}

	/**
	 * Adds the tabs.
	 *
	 * @param mixed $tabs What is registered so far.
	 * @return array<string, string>
	 */
	public static function add_tabs( $tabs ): array {
		return array_merge( is_array( $tabs ) ? $tabs : array(), self::tabs() );
	}

	/**
	 * Adds the Forge product.
	 *
	 * @param mixed $products What is registered so far.
	 * @return array<string, string>
	 */
	public static function add_products( $products ): array {
		$products                  = is_array( $products ) ? $products : array();
		$products[ self::PRODUCT ] = __( 'Forge', 'blueworx-forge' );

		return $products;
	}

	/**
	 * Says which product each of the tabs belongs to.
	 *
	 * @param mixed $map What is mapped so far.
	 * @return array<string, string>
	 */
	public static function add_tab_products( $map ): array {
		$map = is_array( $map ) ? $map : array();

		foreach ( array_keys( self::tabs() ) as $tab ) {
			$map[ $tab ] = self::PRODUCT;
		}

		return $map;
	}

	/**
	 * What the client workspace is given: the same tabs and guides, as parts.
	 *
	 * @return array{tabs: array<int, array{id: string, label: string}>, guides: array<int, array<string, mixed>>}
	 */
	public static function for_workspace(): array {
		$tabs = array();

		foreach ( self::tabs() as $id => $label ) {
			$tabs[] = array(
				'id'    => $id,
				'label' => $label,
			);
		}

		return array(
			'tabs'   => $tabs,
			'guides' => self::definitions(),
		);
	}

	/**
	 * One guide as HTML for the guides page.
	 *
	 * Uses the page's own helper when BlueWorx is there, so the guide reads
	 * like the rest; otherwise the same shape by hand.
	 *
	 * @param array{where: string, steps: array<int, string>, then: string} $guide One of {@see self::definitions()}.
	 * @return string
	 */
	private static function body( array $guide ): string {
		if ( function_exists( 'blueworx_guide_body' ) ) {
			return (string) blueworx_guide_body(
				array(
					'where' => $guide['where'],
					'steps' => $guide['steps'],
					'then'  => $guide['then'],
				)
			);
		}

		$steps = '';

		foreach ( $guide['steps'] as $step ) {
			$steps .= '<li>' . self::emphasise( $step ) . '</li>';
		}

		return '<p><strong>' . esc_html( __( 'Where:', 'blueworx-forge' ) ) . '</strong> ' . self::emphasise( $guide['where'] ) . '</p>'
			. '<ol>' . $steps . '</ol>'
			. '<p>' . self::emphasise( $guide['then'] ) . '</p>';
	}

	/**
	 * Escapes text and turns *label* into emphasis.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function emphasise( string $text ): string {
		return (string) preg_replace( '/\*([^*]+)\*/', '<em>$1</em>', esc_html( $text ) );
	}
}
