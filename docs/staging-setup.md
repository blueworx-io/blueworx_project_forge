# Setting up a staging pair

How to get Forge running on two real WordPress sites — a studio and a client —
and prove the connection between them works. This is the first thing to do on
real hosting, before anyone tries to use Forge for real work: it finds the
problems a local test cannot, and it finds them cheaply.

Roughly twenty minutes.

## What you need

- Two WordPress sites, 6.5 or later, on PHP 8.2 or later, both on HTTPS.
- Administrator access to both.
- The ability to add a line to `wp-config.php` on both.
- A GitHub personal access token that can read this repository.

The two sites must be separate installs. A subsite of a multisite, or two
subdirectories of one install, will appear to work and will not be testing the
thing this exists to test.

## 1. Install the plugins

Both plugins are attached to every [release](https://github.com/blueworx-io/blueworx_project_forge/releases).
Download the two zips from the newest one, then on each site go to
**Plugins → Add New → Upload Plugin**:

| Site | Plugin |
|---|---|
| Studio | `blueworx-forge.zip` |
| Client | `blueworx-forge-client-<version>.zip` |

Activate each one. Uploading a zip is only for this first install — after that
the sites update themselves.

## 2. Let the sites see updates

This is a private repository, so a site cannot see its own releases without a
token. Add this to `wp-config.php` on **both** sites, above the line that says
`That's all, stop editing`:

```php
define( 'BLUEWORX_PLUGIN_UPDATE_TOKEN', 'github_pat_...' );
```

Without it the plugins install and run perfectly well and simply never offer an
update, which is a quiet enough failure to go unnoticed for months.

## 3. Register the client site, on the studio

On the studio: **Forge → Client sites**. Enter the client's name and its web
address, and register it.

The key appears once, on the screen that issues it. Copy both the site id and
the key now — there is nowhere to look the key up later. If you lose it, issue a
new one; that is what the button is for, and it costs nothing.

## 4. Connect the client site, on the client

On the client site: **Forge → Connection**. Paste in the studio's address, the
site id and the key, and save.

The screen then asks the studio whether it accepts them and tells you what came
back. "Connected to the studio as ..." means it worked. Anything else is real:
the screen does not report success unless the studio said so.

On a live client site, prefer putting the credentials in `wp-config.php`
instead, so the key is not in the database and does not travel in a database
export:

```php
define( 'BWX_FORGE_STUDIO_URL', 'https://studio.example' );
define( 'BWX_FORGE_CLIENT_SITE_ID', 'site_...' );
define( 'BWX_FORGE_CLIENT_KEY', '...' );
```

The screen shows those as fixed and leaves them alone.

## 5. Check it end to end

- On the client site, **Forge** shows the record the studio holds for it, and
  when it last synced.
- On the studio, cut the site off. The client site keeps showing what it last
  saw, says it is out of date, and its Connection screen says it was refused.
- Issue a new key on the studio and paste it in on the client. It works again
  without re-registering.

That is the whole trust model, exercised on real hosting.

## When it does not work

The studio deliberately tells a refused caller nothing about why — that is what
stops someone sorting real site ids from invented ones. The reason is on the
studio, under **Forge → Client sites**, in the refused requests list.

| What it says | What it usually means |
|---|---|
| `unknown_site` | The site id is mistyped, or the site was registered on a different studio |
| `revoked_site` | The site has been cut off. Issue a new key to undo that |
| `bad_signature` | The key is wrong — usually a partial paste |
| `stale_request` | The two servers' clocks are more than five minutes apart. Fix the clock, not Forge |
| `replayed_request` | Something is repeating a request; worth asking why |

Nothing at all in the list, and the client site says it cannot reach the studio,
means the request never arrived: the client site's host is blocking outbound
HTTP requests, or the studio's address is wrong. Some managed hosts block
outbound calls by default and will allow a named domain on request.

## What this does not test

There is no project data yet. This proves installation, updates, identity and
revocation — the plumbing. Real use waits on the designed screens.
