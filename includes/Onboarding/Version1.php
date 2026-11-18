<?php
/**
 * The checklist a fresh install starts with.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

/**
 * ONB-1 (#159). Version 1 of the launch checklist, seeded on activation and on
 * the first request after an update.
 *
 * Seeded rather than kept in code, so that correcting it afterwards is an edit
 * rather than a release — a checklist that needs a deployment to fix is a
 * checklist that stays wrong. This class holds the starting content and nothing
 * else; the moment it is published it is data like any other version, and every
 * version after it is made in the product.
 *
 * It builds the version through Templates and TemplateSteps rather than writing
 * rows itself, so the seed cannot produce something the editing screen could
 * not have produced.
 *
 * ONB-1 settles that version 1 covers twelve categories. Five are named in the
 * decision itself, because they are the launch-critical ones. The other seven,
 * and every step's wording, were agreed by Luke on 18 November 2026, from the
 * page-editing, Figma and SEO standards set the same day.
 */
final class Version1 {

	/**
	 * Whether the definition below is the agreed one, and so may be seeded.
	 */
	public const READY = true;

	/**
	 * Remembers that a site has had its one chance at the seed after an update.
	 */
	public const CHECKED_OPTION = 'bwx_forge_onboarding_v1_checked';

	/**
	 * What the first version is called.
	 */
	public const NAME = 'Launch checklist';

	/**
	 * The five launch-critical categories, named in ONB-1. Every step in one of
	 * these gates the launch; steps in the other seven may too.
	 *
	 * @var array<int, string>
	 */
	public const LAUNCH_CRITICAL = array(
		'Domain and DNS',
		'Hosting',
		'Email and SMTP',
		'Legal and compliance',
		'Review and launch',
	);

	/**
	 * The step definitions, in the order they are worked through.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function steps(): array {
		return array(
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Domain and DNS',
				'title'                 => 'Delegate the domain and DNS',
				'description'           => 'Invite our named account to the registrar and DNS provider, with permission to change records. Access is by invitation only — we never ask you for a login of your own (ONB-3).',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 10,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Hosting',
				'title'                 => 'Give us access to the hosting',
				'description'           => 'Invite our named account to the hosting control panel with the access level the build needs.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 20,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Hosting',
				'title'                 => 'Set up the site, its code and a test site',
				'description'           => 'WordPress, the BlueWorx theme or plugin, the site\'s own plugin, and a private test site.',
				'owner_side'            => TemplateSteps::INTERNAL,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 30,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Email and SMTP',
				'title'                 => 'Settle how the site sends email',
				'description'           => 'Confirm which service the site sends through and give us the access to configure it. Forge stores no SMTP credentials (NOTIF-3).',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 40,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'People and sign-off',
				'title'                 => 'Name who signs off the site',
				'description'           => 'One person who approves the designs and the launch.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 50,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'People and sign-off',
				'title'                 => 'List who needs a login',
				'description'           => 'The names and email addresses of the people who will edit the site after launch. We send each of them an invitation.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 0,
				'allows_not_applicable' => 0,
				'optional'              => 1,
				'position'              => 60,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Brand',
				'title'                 => 'Send your logo files',
				'description'           => 'Vector (SVG) or large PNG files, in light and dark versions.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 70,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Brand',
				'title'                 => 'Confirm your brand colours and fonts',
				'description'           => 'Including a web licence for any paid font.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 80,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Brand',
				'title'                 => 'Share brand guidelines or past designs',
				'description'           => 'Anything that shows how your brand should look.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 0,
				'allows_not_applicable' => 1,
				'optional'              => 1,
				'position'              => 90,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Brand',
				'title'                 => 'Create the client\'s Figma file',
				'description'           => 'Copied from the BlueWorx starter file, with the brand\'s colours, fonts and sizes set under the theme\'s names.',
				'owner_side'            => TemplateSteps::INTERNAL,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 100,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Content',
				'title'                 => 'Agree the list of pages',
				'description'           => 'Which pages the site has, and what each one is for.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 110,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Content',
				'title'                 => 'Words for each page',
				'description'           => 'Send your own, or approve the words we draft.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 120,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Content',
				'title'                 => 'Photos and videos',
				'description'           => 'With permission to use every one of them.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 130,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Content',
				'title'                 => 'Business details',
				'description'           => 'Name, address, phone number, opening hours and social links, used on the site and by search engines.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 140,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Accounts and tools',
				'title'                 => 'Google Analytics and Search Console',
				'description'           => 'Invite our named account, or we create them in your name.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 0,
				'allows_not_applicable' => 1,
				'optional'              => 0,
				'position'              => 150,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Accounts and tools',
				'title'                 => 'Payments',
				'description'           => 'Connect your payment account if the site takes money.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 0,
				'allows_not_applicable' => 1,
				'optional'              => 0,
				'position'              => 160,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Accounts and tools',
				'title'                 => 'Other tools',
				'description'           => 'Email marketing, bookings or a CRM the site should connect to.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 0,
				'allows_not_applicable' => 1,
				'optional'              => 0,
				'position'              => 170,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Search and your old site',
				'title'                 => 'Your current website',
				'description'           => 'Its address, and any pages that must keep working after the move.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 0,
				'allows_not_applicable' => 1,
				'optional'              => 0,
				'position'              => 180,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Search and your old site',
				'title'                 => 'What people search for to find you',
				'description'           => 'A few phrases for each main page.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 0,
				'allows_not_applicable' => 0,
				'optional'              => 1,
				'position'              => 190,
			),
			array(
				'section'               => Sections::BUILD_REVIEWS,
				'category'              => 'Design review',
				'title'                 => 'Approve the page designs',
				'description'           => 'In Figma, at desktop, tablet and mobile sizes. Approved pages are marked Ready for dev.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 200,
			),
			array(
				'section'               => Sections::BUILD_REVIEWS,
				'category'              => 'Build review',
				'title'                 => 'Review the test site',
				'description'           => 'Check the words, photos and links on every page.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 210,
			),
			array(
				'section'               => Sections::BUILD_REVIEWS,
				'category'              => 'Build review',
				'title'                 => 'Test the forms',
				'description'           => 'Send each form and confirm the email arrives where it should.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 220,
			),
			array(
				'section'               => Sections::BUILD_REVIEWS,
				'category'              => 'Build review',
				'title'                 => 'Try editing a page with your login',
				'description'           => 'Change some words on a page, so you know you are comfortable doing it.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 0,
				'allows_not_applicable' => 0,
				'optional'              => 1,
				'position'              => 230,
			),
			array(
				'section'               => Sections::LAUNCH,
				'category'              => 'Legal and compliance',
				'title'                 => 'Approve the legal pages',
				'description'           => 'Confirm the privacy notice, terms and cookie handling are the ones you want live.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 240,
			),
			array(
				'section'               => Sections::LAUNCH,
				'category'              => 'Legal and compliance',
				'title'                 => 'Search set-up',
				'description'           => 'An SEO title and description written in SureRank for every page, old pages redirected, and the sitemap sent to Google.',
				'owner_side'            => TemplateSteps::INTERNAL,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 250,
			),
			array(
				'section'               => Sections::LAUNCH,
				'category'              => 'Legal and compliance',
				'title'                 => 'Launch checks',
				'description'           => 'Backups running, every page locked, analytics recording visits, and a test email delivered.',
				'owner_side'            => TemplateSteps::INTERNAL,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 260,
			),
			array(
				'section'               => Sections::LAUNCH,
				'category'              => 'Review and launch',
				'title'                 => 'Final review and sign-off to go live',
				'description'           => 'Walk the finished site and confirm you are happy for it to go live.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'optional'              => 0,
				'position'              => 270,
			),
		);
	}

	/**
	 * Creates and publishes version 1, if there is nothing published yet.
	 *
	 * Safe to call on every activation rather than only the first: it does
	 * nothing at all once a version exists, so a plugin reactivated a year in
	 * never disturbs a checklist somebody is halfway through.
	 *
	 * @param int $author Who to record as having created it.
	 * @return bool Whether it created one.
	 */
	public static function seed( int $author = 0 ): bool {
		if ( ! self::READY ) {
			// The definition is missing more than half of itself. Publishing it
			// would hand a client an incomplete checklist as though it were the
			// agreed one.
			return false;
		}

		if ( null !== Templates::current() ) {
			return false;
		}

		$draft = Templates::create_draft( self::NAME, $author );

		if ( null === $draft ) {
			return false;
		}

		foreach ( self::steps() as $step ) {
			TemplateSteps::add( $draft['id'], $step, $author );
		}

		return null !== Templates::publish( $draft['id'], $author );
	}

	/**
	 * Seeds once on a site that was already installed when the checklist was
	 * finished. Activation never runs again on an update, so without this a
	 * live site would keep its empty checklist screen.
	 *
	 * One option read on an ordinary request. The option is set whatever the
	 * seed does: a site that already has a version of its own keeps it.
	 *
	 * @return void
	 */
	public static function maybe_seed_after_update(): void {
		if ( ! self::READY || get_option( self::CHECKED_OPTION ) ) {
			return;
		}

		self::seed();
		update_option( self::CHECKED_OPTION, 1, true );
	}
}
