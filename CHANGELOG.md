# Changelog

All notable changes to Forge Project Management are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Releases before 1.37.0 predate this file; their history is in the repository's
commits and pull requests.

## [2.14.0] - 2026-08-20

### Added

- The board. Every piece of work on a site, in a column for the stage it is at,
  grouped under whether it is still being captured, waiting on an approval, in
  delivery, or finished.
- Drag a card to move the work. A move the workflow does not allow puts the card
  straight back and says why, rather than leaving the board showing something
  that never happened.
- Click a card to open it: the same moves as buttons, the fields to fill in, and
  everything that has happened to it so far.
- Work can be added from the board.

## [2.13.0] - 2026-08-20

### Added

- Work is now a record: projects, milestones, features and sub-features, plus
  bugs, feedback and tasks that can hang anywhere or nowhere. A level can be
  skipped, and work can only ever belong to one site.
- The twelve stages, fixed. They cannot be renamed, reordered or added to,
  because everything else — the gates, the board, the reports — is written
  against them.
- Work moves one stage at a time, through one route that records every move and
  who made it. A move that fails leaves the item exactly as it was.

### Changed

- Settled where each screen lives: the ones you work in are the app, the ones
  that configure Forge stay in the WordPress admin. Nothing visible changes
  today — it stops the same screen being built twice.

## [2.12.0] - 2026-08-20

### Added

- People are now records of their own, on a new Forge → People screen. One
  person has one account however many clients they work with, so somebody can be
  staff on one client and a viewer on another without being two people — and
  offboarding them ends their access to every client in one action, keeping
  everything they ever did.
- Access to a client is given and ended from that client's row on Forge →
  Clients, either across the whole client or on one of its sites.
- A client that lists permitted email domains now has that enforced: its own
  people must use an address at one of them.

### Fixed

- The Clients screen was serving a very large page once a studio had a lot of
  clients, and became slow to use. It now loads in a fraction of the time.

## [2.11.0] - 2026-08-19

### Added

- Forge now shows whether each client site is actually connected, when it last
  called, and whether that site can send email. A site nobody has connected, one
  that has simply gone quiet, and one that has stopped working now read
  differently, so a quiet site is not mistaken for a broken one.
- Connection keys are issued, rotated and revoked from Forge → Clients, against
  the site they belong to. A new key is shown once, on the screen that issues it.

## [2.10.0] - 2026-08-19

### Added

- Clients and their sites are now records in their own right. A client can have
  more than one site, and each site is its own workspace — work, hours and
  onboarding will belong to the site rather than to the client. Add and manage
  them from Forge → Clients. Nothing is ever deleted: a client or site you
  finish with is made inactive and kept.

## [2.9.3] - 2026-08-19

### Changed

- The foundation decisions are signed off. Two changed in review: moving a job
  outside the normal rules, and booking someone past their capacity, can both be
  done by any administrator rather than by one named person.

## [2.9.2] - 2026-08-19

### Fixed

- The refused requests list no longer fills with replays that never happened.
  Every successful request from a client site was logging one, because
  WordPress asks a route's permission question twice and a signed request can
  only answer it once. A log of false alarms hides the real ones.

## [2.9.1] - 2026-08-19

### Changed

- The two plugins are now named "BlueWorx Labs | Forge Parent Site" and
  "BlueWorx Labs | Forge Client Site" in the WordPress plugins list, so it is
  obvious which is which on a site running one of them.

## [2.9.0] - 2026-08-19

### Added

- A Connection screen on the client plugin: paste in the site id and key the
  studio issued, see whether the studio accepts them, and disconnect. Setting a
  client site up no longer needs anyone to edit a file or call an API.
- Credentials set in wp-config.php are shown as such and left alone, so the
  safer way of storing them still works.

## [2.8.1] - 2026-08-19

### Fixed

- The studio plugin no longer ships a test configuration file to live sites.
  It reached the 2.8.0 release because the two workflows that decide what ships
  had drifted apart; a check now fails if they ever disagree again.

## [2.8.0] - 2026-08-18

### Added

- A Forge screen in the studio's dashboard for connecting a client site. It
  registers the site, shows its key once, issues a replacement key, and cuts a
  site off. Doing any of that previously meant hand-crafting an API call.
- The same screen lists recently refused requests, which is where you look when
  a client site says it cannot connect.

## [2.7.0] - 2026-08-18

### Fixed

- Forge's own screen now looks the same whatever theme a site uses. It was
  quietly picking up the theme's colours, fonts and spacing, which would have
  meant the app looking different on every site it was installed on.
- Forge no longer changes anything else on a site. Its styling loads on its own
  screens and nowhere else, and the admin bar no longer sits over the app.

## [2.6.0] - 2026-08-18

### Added

- Both interfaces now take their colours, type and spacing from one file. Change
  a colour once and it changes in the studio and on every client site — there is
  no second copy to keep in step, and a check fails the build if one ever
  appears.

### Changed

- The design system's token files moved out of the design intake folder, which
  never ships, to the top of the repository, which does. Re-imports from the
  design tool still arrive as a pull request rather than changing the product
  underneath it.

## [2.5.0] - 2026-08-18

### Added

- A client site now shows the details the studio holds for it, read from the
  studio each time rather than kept on the client's own site. Change them in
  one place and that is the only place they live.
- Every screen says when it last heard from the studio. If the studio cannot be
  reached, the site keeps showing what it last saw and says plainly that it is
  doing so — rather than an error page, or worse, an old page that looks
  current.
- A site that has been cut off says so rather than sitting there looking
  connected.
- A "check again" link, for when you have just fixed something and do not want
  to wait for the next refresh.

## [2.4.0] - 2026-08-18

### Added

- A client site can now prove which client it is. You register the site from
  the studio, which hands you a key once; the site uses it to sign every
  request it makes. Nothing else is accepted — a site you have not registered
  cannot connect, however it asks.
- You can cut a site off, from the studio, without touching the site itself.
  Revoking stops it immediately even though it still holds its key, which is
  the point: a site you want disconnected is not going to help you do it.
- You can also issue a site a fresh key, which stops the old one working the
  moment you do.
- Every refused attempt is recorded — which site it claimed to be, why it was
  turned away, and when — so a key being tried repeatedly is something you can
  see rather than something you find out about later.

## [2.3.0] - 2026-08-18

### Added

- One command now sets up both sides of Forge at once — the studio and a client
  site — as two separate, throwaway WordPress installs. Everything that follows
  needs the two talking to each other, and until now there was only ever one to
  test against.
- The checks on every change now prove the client site really is a client site:
  it has no command-centre code on it and cannot answer for one. That was
  previously only checked inside the zip file; it is now checked on a running
  site.

## [2.2.0] - 2026-08-17

### Added

- Forge now ships as two separate plugins: the one you run, and a smaller one
  that goes on a client's own site. A client's WordPress cannot contain the
  command-centre code at all — not "is set up not to show it", but the files
  are not there. The build refuses to produce a client plugin that has any of
  it, and that refusal is checked on every change.
- The client plugin installs, activates and updates itself independently. It
  does nothing yet; the client workspace follows once a site can prove which
  client it is.

## [2.1.0] - 2026-08-17

### Added

- The rules every part of the API follows from here on. Two of them you will
  notice: if somebody else changed an item since you opened it, your save is
  refused and you are shown what changed, instead of one of you quietly
  overwriting the other. And if a save is sent twice — a slow connection, a
  second click — it still only happens once.
- When an item cannot move to the next stage, the answer now lists everything
  that is missing, not just the first thing found. No more fixing one item,
  resubmitting, and being told about the next.

## [2.0.1] - 2026-08-17

### Fixed

- The server-side test suite can no longer report success without having run
  anything. A build where the tests fail to run at all now fails, instead of
  passing quietly — which is the failure that hides broken code for months.

## [2.0.0] - 2026-08-17

### Changed

- Forge has been rebuilt from the ground up and is now a separate plugin,
  **Blueworx Forge**. It installs alongside the old Forge Project Management
  rather than replacing it, so both can run on the same site while you move
  items across by hand. The old plugin is untouched and stays installable from
  its 1.37.2 release.
- This release is the new plugin's foundation: it installs, activates and
  updates itself, and nothing more. The screens follow, built from the new
  design.

## [1.39.0] - 2026-08-17

### Added

- The decision record: the forty-seven product and architecture questions the
  platform rebuild depends on, each with the question, what else was considered,
  the approved answer, and what would have to be rebuilt if it ever changes.
- A check that the record stays complete. Adding a new question to the list now
  fails the build until somebody answers it, so the rebuild cannot quietly start
  on a guess.
- The three documents the rebuild is built from: who is allowed to do what on
  each of the two sites, how a piece of work moves from idea to released and what
  it has to satisfy at each step, and what the system actually stores. Between
  them they say what gets built and, just as importantly, what must always be
  refused.

## [1.37.2] - 2026-08-17

### Changed

- The local test WordPress now runs on a port of its own, so it can no longer
  collide with another project's test site — which made every test fail at once
  while the site itself looked perfectly healthy.
- The seven long-standing linter warnings about the app's forms and search box
  are gone. Each was the linter objecting to a pattern that is correct here —
  a form field seeded from saved settings, a search box that has to follow a
  shared link — so each now says why it is deliberate. Nothing about the app
  behaves differently, and the linter is silent again, which means the next real
  warning will be noticed.

## [1.37.1] - 2026-08-17

### Fixed

- Four places on the Status screen printed diagnostic values without escaping
  them first, and the "Copy Status Report" button embedded the report in a way
  that could cut itself off mid-report. Both are fixed, and a test now proves the
  copied report is complete and the screen renders.

### Changed

- All of the plugin's PHP now follows the WordPress coding standards, checked on
  every pull request. Formatting only — nothing about how the plugin behaves
  changed, and the full test suite passes unchanged.

## [1.37.0] - 2026-08-17

### Added

- The plugin now updates itself on live sites. Once a release is published,
  WordPress offers it like any other plugin update — no more uploading a zip by
  hand. Each site needs a one-off access token in its `wp-config.php`.
- Automatic checks now run on every pull request: the code is linted and built,
  the version and changelog must be updated, no dependency can be added without
  being approved first, and a real WordPress site is created from scratch to test
  against.
- Tests that prove the plugin actually works after install — it activates
  cleanly, every item screen in the admin opens, the app page loads and the app
  itself starts up, and the public data endpoint answers.
- Uninstalling now clears the plugin's own settings, cached data and roles.
  Features, releases, bugs, feedback and the app page are deliberately left
  alone: they are the site's content, so a reinstall picks them straight back up.

### Changed

- Zips are now built by a script that lists exactly which files may be included
  and then checks the finished file before handing it over, so development files
  cannot reach a live site. `npm run zip` still works and does this.
