# Sakura Mail (custom.sakura-mail)

A mailbox on Sakura Internet's rental server, read and sent from Muse
through the helper in `bin/mail_muse.py`. The helper has two modes:

- **Bridge mode (default)**: the helper POSTs JSON over HTTPS to an
  intermediate adapter running as a **Cloudflare Worker**
  (`worker/muse-mail-bridge.js`). The request carries the registered
  connection's credential (a bridge API token) via the usual surrogate
  swap, and the Worker performs the IMAP/SMTP work over TLS sockets with
  the mailbox password from its own Worker secrets. This is the mode
  that needs no password from the user. (A PHP variant of the bridge is
  in `bridge/` for Sakura accounts that include web space; a mail-only
  plan has no web space and cannot host it.)
- **Direct mode (`--direct`)**: the helper talks IMAP/SMTP to the mail
  server itself, with a per-task password on stdin. Mailbox protocols
  are gated by Muse's *Direct network protocols* permissions
  (`email_mailbox`, `email_smtp`), each connection needing the user's
  approval.

> All addresses and server names in this repository are placeholders
> (`you@your-domain.example`, `your-initial-domain.sakura.ne.jp`, …).
> Substitute your own values. No real password or token is included.

## Account and servers

- Address / login user: `you@your-domain.example` — Sakura login IDs for
  domain addresses are the **full address**; using only the part before
  the `@` is a common failure.
- Server (initial domain): `your-initial-domain.sakura.ne.jp`
  (the domain's MX points at the same host).
- IMAP: port 993, SSL (Courier-IMAP).
- SMTP: port 587, STARTTLS (Sendmail; port 465 SSL also answers).
- SPF should cover the Sakura server for the sending domain.

## Credential model

Direct mode is transient, per task:

- The mailbox password is never written to a file, env var, argv, log or
  this doc. The user supplies it per task (usually as an app password).
- The helper reads the password from **stdin, first line only**:
  `printf '%s' "$PW" | python3 bin/mail_muse.py --direct check` — in
  practice the password is passed through the exec tool's stdin field
  for that one run.
- Never repeat the password in chat, reports or memory. If a login
  fails, report only that it failed.

The stored-credential route works through the bridge, because the
surrogate swap does not extend to mail protocols: using the `hsurr:`
surrogate directly as the IMAP password fails with "Login failed."
(verified) — the swap happens inside HTTP requests to registered hosts,
and IMAP/SMTP login bytes are not substituted.

- The connection `custom.sakura-mail` is registered with
  `credentials.request_api_access` (auth_scheme `api_key`, placement
  `bearer_header`, api_hosts = the Worker host) and holds the **bridge
  API token** (not the mailbox password).
- The Worker checks the token (secret `BRIDGE_TOKEN`) and reads the
  mailbox password from its secret `MAIL_PASS`. Both values are entered
  by the user — the token invented by them, the password known to
  them — and neither passes through chat.
- Bridge URL: the deployed Worker URL (a `*.workers.dev` URL or a
  custom domain such as `mail-bridge.your-domain.example`); the helper
  reads it from the `MUSE_MAIL_BRIDGE_URL` env var or its `BRIDGE_URL`
  constant.
- Note: sends from the Worker originate from Cloudflare's network
  (non-Japanese IPs), so Sakura's foreign-IP filter must be disabled
  for `send` to work (see Notes). Receiving is unaffected by that
  filter.
- Verify in stages after deployment: the bridge answers
  `{"action": "ping"}` with ok once the token matches; then `check`,
  then a `send` to the mailbox itself confirmed via `list`.

## Prerequisites (user side)

1. The mailbox exists in Sakura's control panel with a password set.
2. For direct mode: Muse settings → Permissions → Direct network
   protocols: `email_mailbox` and `email_smtp` set to **Ask**.

## Commands

Run from this connector's directory. Output is JSON. All commands use
bridge mode unless `--direct` is given (direct mode reads the password
from stdin and talks IMAP/SMTP itself).

- `check` — login, list folders, INBOX total/unseen counts. Use this as
  the connectivity and credential proof.
- `list [--limit N]` — recent INBOX messages (uid, date, from, subject),
  newest first. Read-only (EXAMINE/PEEK); does not mark mail as read.
- `read --uid UID` — full plain-text body of one message.
- `send --to ADDR --subject S --body-file PATH` — send plain-text mail
  from the mailbox address. Body comes from a file so long texts stay
  out of argv. Sending is an outward action: follow the user's
  instruction for the recipient and content, and confirm consequential
  sends first.

## Bridge deployment (one-time, user side)

1. In the Cloudflare dashboard (the account that hosts the domain's
   DNS): Workers & Pages → Create → create a Worker and paste
   `worker/muse-mail-bridge.js` as its code, then deploy.
2. On the Worker, add secrets: `BRIDGE_TOKEN` (a self-chosen long
   random string) and `MAIL_PASS` (the mailbox password). Neither value
   is shared with the assistant.
3. Tell the assistant the Worker URL (the `*.workers.dev` URL, or set
   a custom domain and use that).
4. The assistant reconnects the `custom.sakura-mail` credential for
   that host, the user enters the same `BRIDGE_TOKEN` there, the helper
   is pointed at the URL, and verification runs `ping` → `check` →
   `send`/`list`.

For the PHP variant (accounts with web space): upload
`bridge/muse-mail-bridge.php` into the web root, and create
`mail-bridge-config.php` from `bridge/mail-bridge-config.sample.php`
one level **above** the web root, holding the same token and the
mailbox password.

## Notes

- Connection failures with a plain-text banner starting `muse:` come
  from the permission gateway, not the mail server: the matching Direct
  network protocols row is still on Deny, or the per-connection approval
  was declined.
- Sakura's 国外IPアドレスフィルター (foreign IP address filter, on by
  default) blocks sending from non-Japanese IPs: SMTP AUTH succeeds
  (235) but MAIL FROM is refused with `550 5.7.1 Command rejected`.
  Receiving (IMAP) is unaffected. Disable the filter in Sakura's server
  control panel to resolve it (verified end to end: after disabling, a
  test mail was delivered to the INBOX). Sakura's official help names
  this filter as the cause of exactly this error.
