# Setting up GitHub for Forge

Done once, by an administrator. About five minutes.

1. Sign in to GitHub as the blueworx-io account and make a fine-grained token. Set repository access to **All repositories**. Give it **Issues: Read and write**, **Webhooks: Read and write** and **Metadata: Read**.
2. In Forge, open **Settings → Connections → GitHub**, paste the token and press **Connect GitHub**. That is all Forge asks for: it makes its own webhook secret.
3. Open each client's page in Forge and tick their repos under the right site. Forge adds its webhook to each repo you tick, and takes it off when you untick it. If GitHub won't let it, the repo isn't linked and Forge says why.

New issues in a linked repo then appear in Future Idea. Only issues opened after a repo is linked come across.

If something goes wrong later, such as GitHub no longer accepting the token, it shows on the GitHub row on the Connections page. Connecting again with a new token fixes the webhooks on every linked repo.

If a delivery fails, press **Redeliver** on it in the repo's webhook settings on GitHub. It is safe: the same issue is never added twice.
