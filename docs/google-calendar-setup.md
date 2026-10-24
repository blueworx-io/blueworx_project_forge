# Setting up Google Calendar for Forge

Done once, by whoever looks after the studio's Google Workspace. About ten minutes.

1. Go to <https://console.cloud.google.com/> signed in with a studio Workspace account and create a project, e.g. "Blueworx Forge".
2. **APIs & Services → Library**: find **Google Calendar API** and press **Enable**.
3. **APIs & Services → OAuth consent screen**: choose **Internal** (only the studio's Workspace accounts can connect), give it a name and support email, and save.
4. On the consent screen's **Data access** (or **Scopes**) step, add `https://www.googleapis.com/auth/calendar.readonly`. That is the only scope Forge asks for.
5. **APIs & Services → Credentials → Create credentials → OAuth client ID**, type **Web application**.
6. Under **Authorised redirect URIs** add the exact address shown in Forge under **Settings → Google Calendar**. It looks like:
   `https://<your-forge-site>/wp-admin/admin-post.php?action=bwx_forge_google`
7. Copy the **Client ID** and **Client secret** into Forge's **Settings → Google Calendar** and press **Save Google client**.

Each staff member then opens their own page in Forge (the pill in the top-right corner) and presses **Connect Google Calendar**.

Forge only reads calendars. It syncs a person's calendar when they open Forge, at most every five minutes, or when they press **Sync now**.
