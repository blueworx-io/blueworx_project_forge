# Changelog

All notable changes to Forge Project Management are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Releases before 1.37.0 predate this file; their history is in the repository's
commits and pull requests.

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
