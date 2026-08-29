<?php
/**
 * The checklist a fresh install starts with.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

/**
 * ONB-1 (#159). Version 1 of the launch checklist, seeded on activation.
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
 * **This definition is not finished, and will not seed until it is.** ONB-1
 * settles that version 1 covers the twelve categories in the brief's §11.2.
 * Five of them are named in the decision itself, because they are the
 * launch-critical ones, and they are below. The remaining seven are in the
 * brief, which is not in this repository, and they have not been supplied.
 *
 * They are deliberately not guessed. A checklist is worked through by somebody
 * who believes it is the agreed one, so an invented step is worse than a
 * missing one — and a version published with five of twelve categories would
 * be handed to a client as though it were complete. So READY stays false, and
 * activation seeds nothing, until the list is filled in.
 */
final class Version1 {

	/**
	 * Whether the definition below is the whole of §11.2.
	 *
	 * False until the seven missing categories are supplied. Flip it in the
	 * same change that adds them, and not before: it is the only thing standing
	 * between a client and a checklist that is missing more than half of itself
	 * without saying so.
	 */
	public const READY = false;

	/**
	 * What the first version is called.
	 */
	public const NAME = 'Launch checklist';

	/**
	 * The five launch-critical categories, named in ONB-1.
	 *
	 * Categories rather than steps: each of these becomes one or more steps
	 * once the brief's own wording is available. What is fixed here is which
	 * ones gate a launch, because that part is decided (ONB-1) and does not
	 * depend on the missing seven.
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
	 * Incomplete: see the class comment and READY.
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
				'position'              => 20,
			),
			array(
				'section'               => Sections::FOUNDATIONS,
				'category'              => 'Email and SMTP',
				'title'                 => 'Settle how the site sends email',
				'description'           => 'Confirm which service the site sends through and give us the access to configure it. Forge stores no SMTP credentials (NOTIF-3).',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'position'              => 30,
			),
			array(
				'section'               => Sections::LAUNCH,
				'category'              => 'Legal and compliance',
				'title'                 => 'Approve the legal pages',
				'description'           => 'Confirm the privacy notice, terms and cookie handling are the ones you want live.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'position'              => 80,
			),
			array(
				'section'               => Sections::LAUNCH,
				'category'              => 'Review and launch',
				'title'                 => 'Final review and sign-off to go live',
				'description'           => 'Walk the finished site and confirm you are happy for it to go live.',
				'owner_side'            => TemplateSteps::CLIENT,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'position'              => 90,
			),

			/*
			 * The seven remaining categories from §11.2 belong here, across
			 * Foundations and Build reviews. They are not invented: see READY.
			 */
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
}
