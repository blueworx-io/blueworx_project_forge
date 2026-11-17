# Setting up GitHub for Forge

Done once, by an administrator. About ten minutes.

1. On the blueworx-io GitHub account, make a fine-grained token. Set the resource owner to the organisation and repository access to **All repositories**. Give it **Issues: Read and write** and **Metadata: Read**.
2. Make up a long random secret and keep it to hand.
3. In Forge, open **Settings → Connections → GitHub** and enter the token and the secret.
4. In GitHub, open the organisation's **Settings → Webhooks → Add webhook**:
   - Payload URL: `https://blueworx.io/wp-json/blueworx-forge/v1/github/webhook`
   - Content type: `application/json`
   - Secret: the same secret as step 2
   - Events: **Let me select individual events**, then tick **Issues** only
5. Open each client's page in Forge and link their repos to the right site.

GitHub sends a test message when the webhook is saved. It shows as **Last checked in** on the GitHub row in Forge.

If a delivery fails, press **Redeliver** on it in GitHub. It is safe: the same issue is never added twice.

New issues in a linked repo then appear in Future Idea. Only issues opened after a repo is linked come across.
