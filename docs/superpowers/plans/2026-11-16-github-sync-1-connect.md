# GitHub sync, part 1: connect and bring issues in — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Connect GitHub on the Connections page, link repos to client sites, and turn every new issue in a linked repo into a loose Forge item in Future Idea.

**Architecture:** A new `includes/GitHub/` module holds the settings, the HTTP client, repo-to-site links, item-to-issue links and the inbound handler. One public REST route receives GitHub's organisation hook, checks its signature, records the delivery so resends are ignored, and hands the payload to the handler. Nothing goes *to* GitHub in this part except reads (checking the token, listing repos).

**Tech Stack:** PHP 8 WordPress plugin (namespaced, autoloaded), React + Vite app, PHPUnit (no WordPress runtime), Playwright against the local WordPress harness.

**Spec:** `docs/superpowers/specs/2026-11-16-github-issue-sync-design.md` (sections Connecting, Linking repos to sites, From GitHub into Forge). Issue #608.

## Global Constraints

- Branch: `github-issue-sync-1`. One pull request, draft, linked to #608. Never auto-merge.
- Minor version bump: 2.220.0 → 2.221.0 in `package.json`, both plugin headers and `BWX_FORGE_VERSION` (`blueworx-forge.php`, `client/blueworx-forge-client.php`). Changelog entry in `CHANGELOG.md`, written for the person using Forge.
- `src/rules.ts` gets the new rules in the same pull request (a new section `github`, title "GitHub").
- No new dependencies.
- The token and the hook secret are sealed with `Tenancy\Secrets::seal()`, shown back only as `••••` + last four characters, never logged or sent to the browser.
- Every route goes through `Rest\Server::register_route()` with a permission callback and a scope.
- Run before committing: `npm run lint`, `npm run build`, `composer lint`, `vendor/bin/phpunit`, and the Playwright suite (`npm run wp:up` then `npm test`). Lint once at the end and report findings to Luke rather than looping.
- The webhook route is security-sensitive: run the `security-review` skill before the pull request.
- Plain words in every message a person sees.

## Review Focus

1. GitHub sends the same delivery twice (it retries on timeouts) → one item, not two. Test in Task 6.
2. A message with no signature, or one signed with the wrong secret → 401, nothing stored. Test in Task 5.
3. An issue title longer than 191 characters, or an issue with an empty body → the item is still created, title cut to 191, description empty. Test in Task 6.
4. An issue opened in a repo no site is linked to, or before GitHub is connected → ignored, answered 200 so GitHub doesn't keep retrying. Test in Task 6.
5. Linking a repo that another site already has → refused, naming the other site. Test in Task 4.

---

### Task 1: Tables and the item column

**Files:**
- Modify: `includes/Data/Schema.php` (VERSION 53 → 54; three table-name methods; three CREATE statements; one column on work items)
- Modify: `includes/Work/Items.php` (`defaults()` and `hydrate()` carry `github_repo`)
- Modify: `src/types.ts` (`WorkItem.github_repo: string`)
- Test: `tests/php/SchemaTest.php`

**Interfaces:**
- Produces: `Schema::github_repos_table(): string` (`{prefix}bwx_forge_github_repos`), `Schema::github_links_table(): string` (`…_github_links`), `Schema::github_deliveries_table(): string` (`…_github_deliveries`). Work item field `github_repo` — `''` unanswered, `'none'` no repo needed, or `owner/name` lower-cased.

Tables (dbDelta formatting rules in `definitions()` apply — two spaces after PRIMARY KEY, no backticks):

```sql
github_repos:      repo varchar(191) NOT NULL, client_site_id varchar(32) NOT NULL,
                   created_at bigint(20) unsigned NOT NULL DEFAULT 0,
                   created_by bigint(20) unsigned NOT NULL DEFAULT 0,
                   PRIMARY KEY  (repo), KEY client_site_id (client_site_id)
github_links:      item_id varchar(32) NOT NULL, repo varchar(191) NOT NULL,
                   issue_number int(11) unsigned NOT NULL DEFAULT 0,
                   issue_id bigint(20) unsigned NOT NULL DEFAULT 0,
                   origin varchar(10) NOT NULL DEFAULT 'forge',
                   title_seen varchar(191) NOT NULL DEFAULT '',
                   body_hash varchar(64) NOT NULL DEFAULT '',
                   created_at bigint(20) unsigned NOT NULL DEFAULT 0,
                   PRIMARY KEY  (item_id), UNIQUE KEY issue_id (issue_id)
github_deliveries: id varchar(64) NOT NULL, event varchar(32) NOT NULL DEFAULT '',
                   action varchar(32) NOT NULL DEFAULT '', outcome varchar(20) NOT NULL DEFAULT '',
                   received_at bigint(20) unsigned NOT NULL DEFAULT 0,
                   PRIMARY KEY  (id), KEY received_at (received_at)
work_items (add):  github_repo varchar(191) NOT NULL DEFAULT ''
```

- [ ] **Step 1: Write the failing test** — in `SchemaTest`, `test_github_tables_are_defined()`: `Schema::VERSION === 54`; `definitions()` has keys for the three new tables; the work items statement contains `github_repo varchar(191) NOT NULL DEFAULT ''`; the links statement contains `UNIQUE KEY issue_id (issue_id)`.
- [ ] **Step 2: Run it** — `vendor/bin/phpunit --filter test_github_tables_are_defined` → FAIL.
- [ ] **Step 3: Implement** the table methods, statements, VERSION bump, `github_repo` in `Items::defaults()` (`''`) and `hydrate()` (string), and the TypeScript field. Also add the three tables to whatever `tables_exist()` and `uninstall.php` walk (follow how `slack_events` is listed).
- [ ] **Step 4: Run it** → PASS. Run the full PHPUnit suite → PASS.
- [ ] **Step 5: Commit** — `Add the GitHub tables (#608)`.

### Task 2: Settings and the GitHub client

**Files:**
- Create: `includes/GitHub/Settings.php`, `includes/GitHub/Client.php`
- Test: `tests/php/GitHubSettingsTest.php`, `tests/php/GitHubClientTest.php`

**Interfaces:**
- Produces, `GitHub\Settings` (option `bwx_forge_github`, autoload false; status option `bwx_forge_github_status`):
  - `save( string $token, string $org, string $secret, string $login, int $by ): void` — seals token and secret, keeps hints, `connected_at = bwx_forge_now()`, `connected_by = $by`.
  - `token(): ?string`, `secret(): ?string`, `org(): string`, `login(): string`
  - `connected(): bool` (token, secret and org all present)
  - `token_hint(): string` (`••••1234`, `''` when not connected)
  - `connected_at(): int`, `actor(): int` (the WordPress user who connected; work brought in is recorded under them)
  - `forget(): void` (deletes both options and the repo-list transient)
  - `heard( int $at ): void` and `last_heard(): int` — when the last signed message arrived
- Produces, `GitHub\Client` (base `https://api.github.com`, headers `Authorization: Bearer <token>`, `Accept: application/vnd.github+json`, `X-GitHub-Api-Version: 2022-11-28`, `User-Agent: Blueworx-Forge`, timeout 10):
  - `const DENIED = 'bwx_forge_github_denied'` (401/403), `const UNREACHABLE = 'bwx_forge_github_unreachable'` (transport error), `const REFUSED = 'bwx_forge_github_refused'` (any other non-2xx; message carries status and GitHub's `message`, cut to 120 characters)
  - `request( string $method, string $path, array $body = array(), ?string $token = null ): array|WP_Error` — decoded JSON body on 2xx; `$token` overrides the saved one (for checking before saving)
  - `whoami( string $token ): array|WP_Error` — `GET /user`, returns `['login' => …]`
  - `org_repos( string $org ): array|WP_Error` — `GET /orgs/{org}/repos?per_page=100&page=N` until a page has fewer than 100; returns lower-cased `owner/name` strings, sorted; cached 10 minutes in transient `bwx_forge_github_repos`

- [ ] **Step 1: Write failing tests** (queue answers in `$GLOBALS['bwx_forge_test_http']`, as `CloudwaysClientTest` does):
  - `test_the_token_and_secret_are_sealed_and_hinted()` — after `save('ghp_abcd1234','blueworx-io','s3cret','blueworx-io',1)`, the stored option does not contain `ghp_abcd1234` or `s3cret`; `token()` returns it; `token_hint() === '••••1234'`; `connected()` is true; after `forget()`, `connected()` is false.
  - `test_a_refused_token_is_denied()` — a 401 answer → `WP_Error` with code `Client::DENIED`.
  - `test_repos_are_read_across_pages()` — first page 100 repos, second page 2 → 102 names, lower-cased, sorted; the second request's URL contains `page=2`.
  - `test_requests_carry_the_api_headers()` — the recorded request has the Bearer header, the API version header and `User-Agent: Blueworx-Forge`.
- [ ] **Step 2: Run them** → FAIL (classes missing).
- [ ] **Step 3: Implement** both classes, following `Cloudways\Settings` and `Cloudways\Client`.
- [ ] **Step 4: Run them** → PASS.
- [ ] **Step 5: Commit** — `Keep the GitHub connection and talk to GitHub (#608)`.

### Task 3: Connect GitHub on the Connections page

**Files:**
- Create: `includes/Rest/GitHubController.php`
- Modify: `includes/Rest/Server.php` (register it), `includes/Rest/ConnectionsController.php` (`answer()` gains a `github` service), `src/components/ConnectionsPanel.tsx` (a `GitHubForm`, the row's hint)
- Create: `tests/support/github-stub.php`, `tests/e2e/helpers/github.js`
- Test: `tests/e2e/github-connect.spec.js`

**Interfaces:**
- Consumes: `Settings`, `Client::whoami()`, `Client::org_repos()`.
- Produces routes (all `Permissions::manage`, scope `SCOPE_OPEN`):
  - `POST /connections/github` body `{token, org, secret}` → checks the token with `whoami()` and `org_repos($org)` before saving; answers the Connections list. Errors: empty field → 400 `invalid_github` naming the field; `DENIED` → 422 "GitHub did not accept that token, or it can't see that organisation. Nothing was saved."; `UNREACHABLE` → 502 "GitHub could not be reached, so the token could not be checked. Nothing was saved; try again."
  - `DELETE /connections/github` → `Settings::forget()`; repo links and item links stay.
  - `GET /github/repos` → `{connected: bool, repos: [{repo, site_id}]}` — every org repo, with the site it is linked to (`''` if none).
- Connections service row: `{id: 'github', name: 'GitHub', connected, hint, connected_at, last_checked: Settings::last_heard()}`. The form explains: "A fine-grained token from the blueworx-io account with Issues (read and write) and Metadata (read) on the organisation's repos. The secret is the one you'll paste into the organisation's webhook. Both are kept sealed and never shown again."
- Test stub: `tests/support/github-stub.php` answers `pre_http_request` for `https://api.github.com/` from state in option `bwx_forge_github_stub` (`repos`, `down`, `denied`, and a `calls` log of method + path + body); a test route `bwx-forge-test/v1/github` reads and sets it. Any token starting `good-` is accepted. Helper `github.js` exports `installGitHubStub()`, `removeGitHubStub()`, `githubSays(request, state)`, `githubCalls(request)`, modelled on `cloudways.js`.

- [ ] **Step 1: Write the failing spec** `github-connect.spec.js`:
  - `connects with a good token and shows it only by its last four` — fill the form with `good-token-9876`, org `blueworx-io`, secret `whsec-1`; the GitHub row shows Connected and `••••9876`; the page source never contains `good-token-9876` or `whsec-1`.
  - `a refused token saves nothing` — token `bad-token` → the alert reads "GitHub did not accept that token…"; row still Not connected.
  - `disconnecting forgets the token` — Disconnect → Not connected.
- [ ] **Step 2: Run it** (`npx playwright test tests/e2e/github-connect.spec.js`) → FAIL.
- [ ] **Step 3: Implement** the controller, the service row and the form (copy `CloudwaysForm`'s shape, three fields, `data-testid="bwx-github-form"`, `bwx-github-token`, `bwx-github-org`, `bwx-github-secret`, `bwx-github-save`).
- [ ] **Step 4: Run it** → PASS.
- [ ] **Step 5: Commit** — `Connect GitHub from the Connections page (#608)`.

### Task 4: Link repos to client sites

**Files:**
- Create: `includes/GitHub/Repos.php`
- Modify: `includes/Rest/GitHubController.php` (two routes), `src/components/ClientProfileScreen.tsx` (a repo chooser per site in the Sites panel)
- Test: `tests/php/GitHubReposTest.php`, `tests/e2e/github-repos.spec.js`

**Interfaces:**
- Produces, `GitHub\Repos`:
  - `normalise( string $repo ): string` — pure; trims, lower-cases; `''` unless it matches `^[a-z0-9_.-]+/[a-z0-9_.-]+$`
  - `conflicts( array $wanted, string $site_id, array $taken ): array<string,string>` — pure; `$taken` is repo → site id; returns repo → other site id for every wanted repo held by a different site
  - `for_site( string $site_id ): array<int,string>`, `site_for( string $repo ): string` (`''` if none), `all(): array<string,string>` (repo → site id)
  - `set_for_site( string $site_id, array $repos, int $by ): true|WP_Error` — replaces the site's list; refuses with `bwx_forge_github_repo_taken` (409, data `{repo, site_id, site_name}`) when `conflicts()` is not empty; refuses repos not in `Client::org_repos()` with `bwx_forge_github_repo_unknown` (400)
- Routes (`Permissions::manage`, scope `SCOPE_SITE` on `site_id`): `GET /client-sites/{site_id}/github-repos` → `{repos: [...]}`; `PUT /client-sites/{site_id}/github-repos` body `{repos: [...]}`.
- Message on conflict: "{repo} is already linked to {site name}. Unlink it there first."

- [ ] **Step 1: Write failing PHP tests**:
  - `test_repo_names_are_normalised()` — `' Blueworx-IO/Forge '` → `'blueworx-io/forge'`; `'not a repo'` → `''`; `'a/b/c'` → `''`.
  - `test_a_repo_held_by_another_site_conflicts()` — `conflicts(['a/x','a/y'], 'cst_1', ['a/x' => 'cst_2', 'a/y' => 'cst_1'])` === `['a/x' => 'cst_2']`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** `Repos` and the routes.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Write the failing spec** `github-repos.spec.js` (stub installed, GitHub connected, stub repos `blueworx-io/alpha`, `blueworx-io/beta`):
  - `a site keeps the repos chosen for it` — choose `alpha` on site A in the client's Sites panel; reload; still chosen.
  - `a repo linked elsewhere is refused by name` — with `alpha` on site A, PUT `alpha` for site B → 409, message names site A.
- [ ] **Step 6: Implement** the chooser: under each active site in the Sites panel, a "GitHub repos" multi-select from `GET /github/repos` (repos linked to another site shown disabled with that site's name), saved with PUT. Hidden when GitHub is not connected. Use the kit's existing pieces; follow the `frontend-design` skill.
- [ ] **Step 7: Run** → PASS.
- [ ] **Step 8: Commit** — `Link GitHub repos to client sites (#608)`.

### Task 5: The webhook route

**Files:**
- Create: `includes/GitHub/Signature.php`, `includes/GitHub/Deliveries.php`
- Create: `includes/GitHub/Module.php`
- Modify: `includes/Rest/Permissions.php` (new `github()` callback; add it to `behind_the_door()`'s exempt list), `includes/Rest/GitHubController.php` (the route), `includes/Plugin.php` (call `GitHub\Module::register()`)
- Test: `tests/php/GitHubSignatureTest.php`, `tests/php/PermissionsTest.php`, `tests/e2e/github-webhook.spec.js`

**Interfaces:**
- Produces:
  - `Signature::valid( string $raw, string $header, string $secret ): bool` — pure; `$header` is `X-Hub-Signature-256`, `sha256=<hex>`; compares `hash_hmac('sha256', $raw, $secret)` with `hash_equals`; false for empty secret or a header without the `sha256=` prefix.
  - `Permissions::github( WP_REST_Request $request ): bool` — true only when GitHub is connected and `Signature::valid( $request->get_body(), $request->get_header('x_hub_signature_256'), Settings::secret() )`.
  - `Deliveries::claim( string $id, string $event, string $action ): bool` — inserts; false when the id was seen before (primary key). `Deliveries::settle( string $id, string $outcome ): void`. `Deliveries::prune( int $older_than ): int` — deletes rows received before `$older_than`.
  - `GitHub\Module::register(): void` — called from `Plugin.php` next to the other modules; schedules a daily event `bwx_forge_github_prune` (the way `Slack\Morning` schedules its own) that prunes deliveries older than 30 days, and clears it on deactivation alongside the plugin's other scheduled events. Later parts add their hooks here.
  - Route `POST /github/webhook` — permission `Permissions::github`, scope `SCOPE_OPEN` with reason "GitHub's organisation hook. Proved by its signature with the shared secret, not by a login." Reads `X-GitHub-Event` and `X-GitHub-Delivery`; a seen delivery answers 200 `{ok: true, outcome: 'duplicate'}`; otherwise `Settings::heard( now )`, passes to `Inbound::handle()` (Task 6), settles the delivery with the outcome, answers 200 `{ok: true, outcome}`. `ping` answers outcome `pong`.

- [ ] **Step 1: Write failing PHP tests**:
  - `test_a_correct_signature_is_valid()` — body `{"a":1}`, secret `s`, header `'sha256=' . hash_hmac('sha256','{"a":1}','s')` → true.
  - `test_a_wrong_secret_or_missing_prefix_is_not()` — same header checked with secret `t` → false; header without `sha256=` → false; empty secret → false.
  - In `PermissionsTest`: `test_the_github_hook_is_outside_the_door()` — `behind_the_door( array( Permissions::class, 'github' ) )` is false.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** `Signature`, `Permissions::github`, `Deliveries`, the route.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Write the failing spec** `github-webhook.spec.js`. Add to `helpers/github.js`: `sendHook(request, event, payload, {secret, delivery})` — signs the JSON body with Node's `crypto.createHmac('sha256', secret)` and posts it to `/wp-json/blueworx-forge/v1/github/webhook` with `X-GitHub-Event`, `X-GitHub-Delivery`, `X-Hub-Signature-256`, signed out.
  - `an unsigned message is refused` — no signature header → 401.
  - `a message signed with the wrong secret is refused` → 401.
  - `a ping is answered` → 200, outcome `pong`; the GitHub row's "Last checked in" is no longer Never.
- [ ] **Step 6: Run** → PASS.
- [ ] **Step 7: Commit** — `Receive GitHub's organisation hook, signed (#608)`.

### Task 6: New issues become items in Future Idea

**Files:**
- Create: `includes/GitHub/Inbound.php`, `includes/GitHub/Links.php`, `includes/GitHub/IssueText.php`
- Modify: `includes/Work/Events.php` (a `VIA_GITHUB = 'github'` constant, and wherever the item history turns `via` into words: "from GitHub")
- Test: `tests/php/GitHubIssueTextTest.php`, `tests/php/GitHubInboundTest.php`, `tests/e2e/github-inbound.spec.js`

**Interfaces:**
- Consumes: `Repos::site_for()`, `Settings::actor()`, `Validate::item()`, `Items::create()`, `Events::append()`.
- Produces:
  - `Links::add( string $item_id, string $repo, int $number, int $issue_id, string $origin, string $title, string $body_hash ): bool` — false when the issue or item is already linked (unique keys).
  - `Links::for_item( string $item_id ): ?array`, `Links::for_issue( int $issue_id ): ?array`, `Links::seen( string $item_id, array $fields ): void` (updates `title_seen` / `body_hash`).
  - `Links::url( array $link ): string` — `https://github.com/{repo}/issues/{number}`.
  - `IssueText::to_html( string $markdown ): string` — pure; blank-line-separated paragraphs become `<p>`, single newlines `<br>`, lines starting `- ` or `* ` become a `<ul>`; everything escaped with `esc_html` first; result passes `Fields::ALLOWED_HTML`. No Markdown library.
  - `IssueText::title( string $title ): string` — pure; trimmed, cut to 191 characters on a character boundary (`mb_substr`).
  - `Inbound::values_for( array $issue, string $repo ): array` — pure; `title`, `problem`, `level => 'feature'`, `work_type => 'bug'` if any label's name is `bug` (case-insensitive) else `'feature'`, `github_repo => $repo`.
  - `Inbound::handle( string $event, array $payload ): string` — outcomes: `pong`, `ignored`, `duplicate`, `created`, `failed`. Part 1 handles `issues`/`opened` only; any other event or action → `ignored`.

Rules for `opened`: repo = `Repos::normalise( repository.full_name )`; no site for it → `ignored`; `Links::for_issue( issue.id )` exists → `duplicate`; else validate `values_for()` through `Validate::item( $values, false )`, create the item under `Settings::actor()` with the site's client, `Links::add( …, 'github', title, sha256(body) )`, and write the creation history entry with `Events::append()` directly instead of `record_creation()`: the same fields `record_creation()` writes (`action` `Events::CREATED`, `to_stage`, `gate` `Transitions::CREATE_GATE`, `cycle`, `actor`) plus `via` `Events::VIA_GITHUB` and `detail` "Came from GitHub: {repo}#{number}, opened by {user.login}." If `Links::add` fails because GitHub raced a second delivery, delete the just-created item with `Items::delete()` and answer `duplicate`.

- [ ] **Step 1: Write failing PHP tests**:
  - `test_paragraphs_and_lists_become_html()` — `"One\n\nTwo\n- a\n- b"` → `<p>One</p><p>Two</p><ul><li>a</li><li>b</li></ul>`.
  - `test_markup_in_an_issue_is_escaped()` — `"<script>x</script>"` → `<p>&lt;script&gt;x&lt;/script&gt;</p>`.
  - `test_an_empty_body_is_an_empty_description()` — `''` → `''`.
  - `test_long_titles_are_cut_to_fit()` — 300 × `é` → 191 characters, valid UTF-8.
  - `test_a_bug_label_makes_a_bug()` — labels `[{name:'Bug'}]` → `work_type` `bug`; none → `feature`; level always `feature`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** `IssueText`, `Inbound::values_for`, `Links`.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Write the failing spec** `github-inbound.spec.js` (GitHub connected through the stub, repo `blueworx-io/alpha` linked to a fresh site):
  - `a new issue lands in Future Idea, loose` — send `issues`/`opened` for issue 7 (id 70007, title "Fix the footer", body "It overlaps.", user `octo`) → outcome `created`; `GET /work-items?client_site_id=` finds one item titled "Fix the footer", stage `future-idea`, `parent_id` `''`, `github_repo` `blueworx-io/alpha`; its history mentions "blueworx-io/alpha#7" and "octo".
  - `the same delivery twice makes one item` — same delivery id again → `duplicate`; still one item.
  - `the same issue under a new delivery makes one item` — new delivery id, same issue id → `duplicate`.
  - `an issue in an unlinked repo is ignored` — repo `blueworx-io/beta` → `ignored`, no item.
  - `a very long title and an empty body still come across` — 250-character title, `body: null` → item title is 191 characters, description empty.
  - `clients see it on their board` — the client's board route lists the item (nothing hidden).
- [ ] **Step 6: Implement** `Inbound::handle` and wire it into the route.
- [ ] **Step 7: Run** → PASS.
- [ ] **Step 8: Commit** — `Bring new GitHub issues into Future Idea (#608)`.

### Task 7: Setup note, rules, version and changelog

**Files:**
- Create: `docs/github-setup.md`
- Modify: `src/rules.ts`, `CHANGELOG.md`, `package.json`, `blueworx-forge.php`, `client/blueworx-forge-client.php`

- [ ] **Step 1: Write `docs/github-setup.md`** — plain steps: make the fine-grained token on the blueworx-io account (resource owner the organisation; repository access All repositories; Issues read and write; Metadata read); make up a long random secret; connect in Forge (Settings → Connections → GitHub); in GitHub, organisation Settings → Webhooks → Add webhook: Payload URL `https://blueworx.io/wp-json/blueworx-forge/v1/github/webhook`, content type `application/json`, the same secret, "Let me select individual events" → Issues only; then link repos on each client's page. Say what to expect: a ping shows as "Last checked in" on the GitHub row.
- [ ] **Step 2: Add rules** in a `github` section: new issues in a linked repo come in as a loose item in Future Idea, recorded under the admin who connected GitHub (why: Forge needs a real person, and moving to Triage needs a primary person and reviewer); only issues opened after a repo is linked come across (why: old issues would flood Future Idea); a repo belongs to one site (why: an issue must land in one place); comments never sync (why: some Forge comments are internal).
- [ ] **Step 3: Bump to 2.221.0** in all four places and add the changelog entry under `### Added`: "Connect GitHub from Settings → Connections and link repos to each client's sites. New issues raised in a linked repo now appear in Forge in Future Idea, ready to triage."
- [ ] **Step 4: Run every check** — `npm run lint`, `npm run build`, `composer lint`, `vendor/bin/phpunit`, `npm test` → all pass (report lint findings, don't loop).
- [ ] **Step 5: Run the `security-review` skill** on the branch and act on anything real.
- [ ] **Step 6: Commit** — `Note how to set up GitHub, and version 2.221.0 (#608)`. Open a draft pull request linked to #608 saying what it does and what wasn't tested (no real GitHub organisation hook was exercised; the stub stands in for GitHub).
