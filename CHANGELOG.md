# Changelog

All notable changes to Forge Project Management are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Releases before 1.37.0 predate this file; their history is in the repository's
commits and pull requests.

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
