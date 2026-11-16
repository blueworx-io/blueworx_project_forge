# GitHub issues in Forge

Date: 2026-11-16. Issue #608 (follows #29).

## Why

Work gets raised in two places. Issues opened in GitHub never reach Forge, so
nobody triages them or counts them against anyone's time. And Forge items that
need code have no GitHub issue, although every build is meant to start from
one. Luke wants GitHub issues pulled into Forge for triage, and Forge items
that need code to get an issue, with the two kept matching.

Not every Forge item needs an issue. Plenty are ordinary work with no code.

## Goals

1. Connect GitHub once, on the Connections page.
2. Link each repo to the client site it belongs to.
3. A new issue in a linked repo appears in Forge, in that site's Future Idea, linked
   to the issue.
4. During Documentation period every item needs a repo chosen, or "No repo
   needed", before it can move to Up Next.
5. Choosing a repo creates the GitHub issue and links the two.
6. Once linked, the two stay matching (see "Keeping them matching").

## Non-goals

- Comments do not sync either way. Some Forge comments are seen by the client
  and some are internal, so copying them is a leak waiting to happen (Luke,
  2026-11-16).
- Assignees, GitHub labels other than the stage label, GitHub milestones and
  GitHub Projects boards.
- Pull requests. The Daily Triage page already puts the pull request link on
  the Forge item.
- Issues that were already open before a repo was linked. Only issues opened
  after linking come across. *(Claude's call: the Forge repo alone has hundreds
  of old issues. A one-off import can be its own issue later.)*

## Anti-goals

- No duplicates: one issue, one item, however many times GitHub resends a
  message or someone saves twice.
- A GitHub that is down or slow never stops a Forge save. Anything that fails
  is retried, and failures show on the Connections page.
- The token is never shown back in full, logged or sent to the browser.
- A message claiming to come from GitHub that is not signed with the shared
  secret is refused.
- Nothing from GitHub moves an item past In review. Only the reviewer can
  release work, and nothing here works around that rule.

## Approach

Three ways were considered:

- **A token plus one organisation-wide GitHub hook (picked by Luke,
  2026-11-16).** A token from the blueworx-io account does the writing. One hook
  on the blueworx-io organisation tells Forge about issue changes in every repo
  as they happen. It matches how SureCart, Cloudways and Slack are connected.
  Issues Forge creates show as raised by blueworx-io.
- A GitHub app. Issues would show as raised by "Forge", but it means more setup
  and more to keep running.
- A token only, with Forge checking GitHub every few minutes. No hook to set
  up, but changes arrive late and it uses up GitHub's request limits.

## Connecting

- A GitHub row on the in-app Connections page, administrators only: the token,
  the organisation name (blueworx-io), and the shared secret for the hook.
  Saved sealed like the Cloudways token, shown back as the last four
  characters only.
- The row shows whether the token works, when the last message from GitHub
  arrived, and any recent failures.
- The token needs to read and write issues on the organisation's repos. A
  fine-grained token with Issues (read and write) and Metadata (read) is
  enough. *(Claude's call.)*
- The hook is set up by hand in GitHub, once. A short setup note in `docs/`
  says where to click, what address to paste and which events to tick (Issues
  only). *(Claude's call: setting up the hook from Forge would need an admin
  token, which is more power than Forge should hold.)*

## Linking repos to sites

- On a client site in Forge, a "GitHub repos" setting: pick one or more repos
  from the organisation. *(Claude's call.)*
- A repo belongs to one site at most. Picking a repo already linked elsewhere
  says which site has it.
- Issues from repos with no site are ignored.
- Unlinking a repo stops new issues coming across. Items already linked keep
  their link and keep matching. *(Claude's call.)*

## From GitHub into Forge

When a new issue opens in a linked repo:

- A Forge item is created in that site's Future Idea, loose: no project or
  milestone (Luke, 2026-11-16). Future Idea rather than Triage, because moving
  into Triage needs a primary person and a reviewer, which GitHub can't supply.
  Someone moves it on by hand.
- No Slack message or other alert when one arrives, for now (Luke,
  2026-11-16).
- Clients see these items on their board like any other item, and repos may
  be public. Nothing is hidden (Luke, 2026-11-16).
- The item is recorded as created by the administrator who connected GitHub,
  and its history says it came from GitHub and who opened the issue there.
  Forge needs a real person as creator. *(Claude's call.)*
- Level: feature. Type: Bug if the issue has the `bug` label, otherwise
  Feature. *(Claude's call.)*
- Title from the issue title. Item description from the issue body.
- The item records which issue it came from, and its repo choice is already
  made, so the Documentation period check is met and no second issue is
  created.
- Forge never edits the body of an issue a person wrote in GitHub. It posts one
  comment on the issue with a link to the Forge item. That one comment is the
  only comment Forge ever writes. *(Claude's call.)*
- Issues that Forge itself created (see below) are not brought back in.

## Repo choice in Documentation period

- Applies to features and sub-features only. Projects, milestones and releases
  never get an issue of their own. *(Claude's call.)*
- Repos can be public or private. Issues created in a public repo can be read
  by anyone, and that is fine (Luke, 2026-11-16).
- A new check on the Documentation period gate, before the reviewer's
  approval: **Repo**. The choices are the repos linked to the item's site,
  then every other repo in the organisation, then **No repo needed** (Luke,
  2026-11-16). *(Order is Claude's call.)*
- The item cannot move to Up Next until this is answered.
- It can be answered at any earlier stage too. It shows on the item's panel
  alongside its other links.
- If GitHub is not connected, the only choice is No repo needed, so the gate
  never gets stuck. *(Claude's call.)*

## Creating the issue

When a repo is chosen and the item has no issue yet:

- Forge creates the issue in that repo straight away (or as soon as GitHub
  answers, if it is down).
- Issue title: the item title. Issue body: the item's description, scope,
  requirements and acceptance criteria under plain headings, then a link
  back to the Forge item. Empty sections are left out.
- The issue's link shows on the Forge item.
- Changing the repo after the issue exists is not allowed. The choice locks
  once there is an issue. *(Claude's call: moving issues between repos is
  rare and GitHub can do it itself.)*
- Changing from a repo to No repo needed before the issue exists is fine.

## Keeping them matching

Once an item and an issue are linked (Luke agreed these, 2026-11-16):

- **Stage.** Forge decides it. The issue carries one label, `forge: <stage
  name>`, swapped for the new one whenever the stage changes.
- **Forge closes, GitHub closes.** Released closes the issue as completed.
  Cancelled, rejected, duplicate or deferred closes it as not planned.
- **Forge reopens, GitHub reopens.** If the item leaves Released or a closing
  outcome (reopened, deferred work picked back up, sent back from review),
  the issue reopens. Forge decides. *(Claude's call.)*
- **GitHub closes, Forge moves to In review.** Forge only moves one stage at a
  time, so this applies when the item is In development and that stage's
  check is met. The move is recorded as automatic, from GitHub, naming who
  closed the issue. In every other case the stage stays put and an internal
  comment says the issue was closed in GitHub, so someone sees it.
  *(Claude's call.)* An issue closed as not planned only adds that comment;
  it never cancels the item. *(Claude's call.)*
- **GitHub reopens.** An internal comment says so. The stage does not
  change. *(Claude's call.)*
- **Title.** Edits copy both ways.
- **Description.** For items that came from GitHub, edits to the issue body
  copy into the item description. Forge never writes back to that body, and
  scope, requirements and acceptance criteria added in Forge stay in Forge.
  For items that started in
  Forge, Forge rewrites the issue body when its fields change. An edit to
  that body in GitHub adds an internal comment saying so, rather than
  overwriting several Forge fields from one block of text. *(Claude's call.)*
- **Comments** do not sync.
- A change Forge makes in GitHub is not echoed back as a change from GitHub,
  and the other way round.

## Left to the builder

- Where the link lives: new columns on work items or a table of their own.
  It needs the repo, the issue number and GitHub's issue id. "No repo needed"
  must be stored distinctly from "not answered".
- How outgoing calls queue and retry. The Slack events register is the
  pattern to follow.
- How incoming messages are recorded so a resend is spotted, using GitHub's
  delivery id and the issue id.
- Turning item text into issue Markdown and back.
- Whether the stage labels are created up front or on first use.

## Built in three parts

Each part is its own pull request.

1. **Connect and bring issues in.** Connections row, repos on sites, the hook,
   new issues landing in Future Idea.
2. **Repo choice and issue creation.** The new gate check, the repo picker on
   the item, creating the issue.
3. **Keeping them matching.** Stage labels, closing both ways, title and
   description edits.

## Done when

- GitHub is connected on the Connections page and the page says it is working.
- A new issue in a linked repo appears in that site's Future Idea within a minute,
  linked both ways, and only once.
- An item in Documentation period cannot move to Up Next without a repo or No
  repo needed.
- Choosing a repo creates one issue that links back to the item.
- Moving the item in Forge changes the issue's stage label. Releasing it
  closes the issue.
- Closing the issue in GitHub moves the item to In review, or leaves a comment
  when it cannot.
- Title edits match on both sides.
- Turning GitHub off, or a bad token, never blocks a save in Forge.
