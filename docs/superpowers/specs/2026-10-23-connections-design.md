# Connections: Cloudways server status and multisite deploys

Date: 2026-10-23. Issue #621. Later work split out: #629 (does each site
load), #630 (new Cloudways apps from a starter copy).

## Why

Luke found out about the last outage because clients messaged him. He wants
Forge to show him first. Separately, starting a new client site means several
manual steps across Cloudways, WordPress and Forge; he wants to start one from
Forge.

Built in two parts, each its own pull request: **A** the Connections page and
Cloudways status, **B** multisite deploys.

## Goals

- A Connections page in Settings listing each outside service: connected or
  not, and when it last checked in.
- Connect Cloudways; see every server's state on a status page.
- Connect the studio's WordPress multisite; create a new client site on it
  from Forge, linked to its client, with logins sent.

## Non-goals (later)

- Checking that each site actually loads (#629). Servers only for now
  (Luke, 2026-10-23).
- Slack alerts and background checks. Forge checks only when the status page
  is opened (Luke, 2026-10-23); Slack comes later and needs background checks
  with it.
- Creating new Cloudways apps (#630). For now a deploy is a new site on the
  existing multisite (Luke, 2026-10-23).

## Anti-goals

- No secret (API key, connection key) is ever shown back in full, logged or
  sent to the browser.
- No password is ever emailed or shown. New users set their own.
- Nobody but an administrator can see the status page, connect a service or
  create a site.

## A. Connections page

- In Settings, in the React app (ARCH-7), administrators only.
- One row per service: name, connected or not, last checked in, and
  *Connect* / *Disconnect*. Cloudways and WordPress multisite now. Google
  Calendar's studio credentials (#606) move here once both exist.
  *(Claude's call.)*
- Secrets are stored encrypted at rest and shown back only as their last
  four characters.
- SureCart stores and Slack stay on the existing Connections screen in
  WordPress admin for now (Luke, 2026-10-23). The new page lists them with a
  link there, so there is one place to start from. Moving them into the app
  is later work.

## A. Cloudways status

- **Connecting:** the Cloudways account email and API key.
- **Status page:** its own page in the app, administrators only. *(Claude's
  call.)* Every server on the Cloudways account, with its state as Cloudways
  reports it (running, stopped, or unreachable when Cloudways can't be
  asked), and when Forge last checked.
- Under each server, the applications on it, each matched to its Forge client
  site by address where one matches; the rest are listed as unmatched. This
  shows which clients a down server affects. *(Claude's call.)*
- **When it checks:** on opening the status page, plus a *Check now*
  button. Nothing runs in the background (Luke, 2026-10-23). The builder
  throttles repeat checks so opening the page twice doesn't hit the
  Cloudways API twice in a few seconds.
- **Flagging:** a server not running also appears on the standup's
  "needs attention" list, **to administrators only**, matching who may see
  the status page. *(Claude's call.)* That list only shows what was
  last known, since nothing checks in the background.

## B. Multisite deploys

- **Connecting:** in Connections, the multisite network's address and a key.
  Luke will name the network later; nothing in the build depends on which.
- **On the network:** the studio's own site is **not** on this network
  (Luke, 2026-10-23), so the Forge client plugin can be network-activated
  there. It provides a secure route that creates a site. Today only client
  sites sign calls *to* the studio (`client/includes/Signer.php`); nothing on
  a client site yet checks a signed call *from* the studio, so this route
  needs that check built, with replay protection. New security-sensitive
  code, not reuse. Only the studio, holding the network's key, can call it. *(Claude's call: keeps it in this repo, rather than the Enhancement
  plugin.)* Everything added stays under `client/` (ARCH-1,
  `bin/check-artifacts.mjs`).
- **Creating a site:** *Create site* sits on a client's Forge site that has
  no WordPress site connected yet, so the record work, hours and onboarding
  already hang off is filled in, not duplicated. A client with no Forge site
  yet gets one made first, the normal way. An administrator gives the
  site's address on the network and its title; Forge asks the network to
  create it. Plugins switched on across the network come with it.
  *(Claude's call.)*
- **Linking:** that Forge site is connected to the new WordPress site, with
  no manual key copying. *(Claude's call.)*
- **Logins:** looked up by email on the network, so somebody who already has
  an account there is added to the new site rather than given a second one.
  The client's **admins** in Forge get **Editor plus Forge: Manager**; the
  client's **viewers** get **Forge: Manager only** (they read Forge, they do
  not edit the site); studio staff and people limited to another of the
  client's sites get nothing. New accounts get WordPress's own "set your
  password" email. The studio's network administrators already have access.
  *(Claude's call.)*
- **Result:** Forge shows each step as it happens (site created, linked,
  logins sent) and ends with links to the new site and its admin. A step
  that fails says which one and leaves what is done in place, with a way to
  try again from that step.

## Safety

- Security-sensitive throughout (API keys, a route that creates sites and
  users): run the `security-review` skill before committing each part.
- The create-site route refuses anything not signed by the studio, validates
  the address and title, and never creates a super admin.

## Left to the builder

- Which Cloudways API calls give server state and the applications on each
  server, and how the API key becomes a token.
- Storage for connections and last-known server state.
- Exact screens and wording, from the Forge kit and the `frontend-design`
  skill.
- How "address" is matched between a Cloudways application and a Forge client
  site (domain, ignoring `www.` and scheme, is the obvious start).

## Done when

- **A:** an administrator connects Cloudways in Connections, opens the
  status page and sees every server's state and which clients sit on each; a
  stopped server is flagged there and on the standup.
- **B:** an administrator connects the multisite, creates a site for a
  client from Forge, and the site exists on the network, is linked to the
  client in Forge, and the client's people have received their set-password
  emails.
- Each part updates `src/rules.ts`, the changelog and the version in its own
  PR.
