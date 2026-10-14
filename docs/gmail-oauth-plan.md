# Plan: Gmail OAuth2 (Workspace-Internal) transport

A **second** Gmail transport alongside the shipping service-account one. It
authenticates with the OAuth 2.0 **authorization-code** flow against a
customer-owned Google Cloud app whose consent screen is set to **Internal**
(Workspace org). This is the only OAuth path that needs no Google app
verification *and* has non-expiring refresh tokens — see "Why Internal-only".

This does **not** unlock consumer `@gmail.com`. Consumer accounts force an
External consent screen, which in Testing status expires refresh tokens after
7 days and otherwise demands the verification/security-assessment burden the
existing `CLAUDE.md` non-goal is about. That non-goal stands as written; this
feature sits beside it.

## Why it's worth building

The shipping `gmail` transport (service account + domain-wide delegation) needs
a Super Admin to authorize the service account's client ID for impersonation
across the **whole domain** — a grant many admins won't give. This transport
needs only a **per-mailbox browser consent** by the owner of the sending
account. Same Gmail API, same send path; the only thing that changes is how the
access token is obtained, and who has to approve it.

## Why Internal-only

`gmail.send` is a Google **restricted** scope. Publishing status decides the UX:

| Consent screen | Verification | "Unverified app" warning | Refresh token |
|---|---|---|---|
| **Internal** (Workspace) | none | none | **never expires** |
| External + Testing | none | yes, every consent | **expires in 7 days** |
| External + Production | required (security assessment) | none once verified | never expires |

Only **Internal** gives a headless, no-verification, non-expiring setup. So the
UI, docs and `is_configured()` guidance all assume Internal, and the setup
instructions tell the admin to set the consent screen user type to Internal.

## Architecture fit & the one wrinkle

Everything on the *send* side fits the existing shape: the transport implements
`Lean_SMTP_Api_Transport`, is handed a `preSend()`-ed PHPMailer, posts
`getSentMIMEMessage()` as base64url raw MIME to `users.messages.send`, and —
like the service-account transport — **leaves the `Bcc` header in place**
(Gmail consumes it; `messages.send` has no envelope). It does *not* use
`Lean_SMTP_Mime`.

The wrinkle is auth: OAuth authorization-code is a **stateful, interactive**
consent round-trip. That does not belong in `send()` (transports are pure) — it
lives in an admin-post handler beside the settings page, exactly like the
existing `lean_smtp_test` action. The transport's `access_token()` only ever
deals with the already-stored refresh token.

## New files

- `includes/class-lean-smtp-gmail-oauth.php` — the transport
  (`Lean_SMTP_Gmail_OAuth implements Lean_SMTP_Api_Transport`).

- `includes/trait-lean-smtp-gmail-send.php` — `Lean_SMTP_Gmail_Send` trait
  holding `send_message()`, `base64url()`, and the `SEND_ENDPOINT`/`SCOPE`/
  `TOKEN_URI` constants, so both Gmail transports share the identical send path
  (endpoint, raw MIME, Bcc-left-in rule) and it can't drift between them.
  **Decided: extract the trait.** This touches the shipping
  `Lean_SMTP_Gmail` — move those members onto the trait and have it `use`
  the trait; its existing `jwt_assertion` unit test and send assertions must
  still pass unchanged.

## Settings (new options)

| Option | Kind | Notes |
|---|---|---|
| `lean_smtp_gmail_oauth_client_id` | text | OAuth client ID (not secret, but opaque) |
| `lean_smtp_gmail_oauth_client_secret` | secret | via `secret_field()` / `sanitize_secret()` |
| `lean_smtp_gmail_oauth_refresh_token` | secret | **not a pasted field** — written by the callback handler, encrypted via `Lean_SMTP_Crypto`; shown only as "Connected as x@y / Disconnect" |
| `lean_smtp_gmail_oauth_sender` | email | the connected mailbox; **captured from the id_token `email` claim on connect**, never entered by hand |

All read through `Lean_SMTP_Config`, so each gets `wp-config.php` constant
support for free (constant refresh token = plaintext, skips the decryptor — same
rule as every other secret). Client ID + secret pinned by constant render
locked; the refresh token can't be set by constant in practice but the guard
still applies harmlessly.

## The transport class

```
const MAILER slug: 'gmail_oauth'
label(): "Gmail / Google Workspace (OAuth)"

is_configured(): client_id, client_secret, refresh_token, sender all present

send( $phpmailer ):
    token = access_token();  is_wp_error -> return
    return send_message( token, $phpmailer->getSentMIMEMessage() )   // shared with service-account path

access_token():   // mirrors Lean_SMTP_Gmail::access_token(), different grant
    cache_key = 'lean_smtp_gmail_oauth_token_' . md5( client_id . '|' . sender . '|' . refresh_token )
    cached transient -> return
    POST TOKEN_URI {
        grant_type: 'refresh_token',
        client_id, client_secret, refresh_token
    }
    non-2xx / no access_token:
        if body.error == 'invalid_grant':
            delete stored refresh token + sender   // grant was revoked/expired
            WP_Error 'reconnect_required' -> surfaces a "reconnect" admin notice
        else WP_Error with Google's error_description
    set_transient( cache_key, token, expires_in - 60 )
    return token
```

`send_message()` is byte-identical to the service-account transport's (hence the
shared trait).

## The OAuth consent round-trip (admin layer)

Added to `Lean_SMTP_Settings::init()` as admin-post actions, matching
`handle_test()`:

1. **`admin_post_lean_smtp_gmail_connect`** — cap check + nonce; build the
   Google auth URL and `wp_redirect()` to it:
   ```
   https://accounts.google.com/o/oauth2/v2/auth
     ?client_id=...&redirect_uri=<exact>&response_type=code
     &scope=openid%20email%20https://www.googleapis.com/auth/gmail.send
     &access_type=offline&prompt=consent
     &state=<nonce>
   ```
   `access_type=offline` + `prompt=consent` is what makes Google return a
   refresh token (and re-issue one on reconsent). **Scope includes
   `openid email`** so the connected mailbox comes back in the id_token (see
   Sender, below).

2. **`admin_post_lean_smtp_gmail_callback`** — the registered redirect URI.
   Verify `state` nonce; exchange `code`:
   ```
   POST TOKEN_URI { grant_type: 'authorization_code', code,
                    client_id, client_secret, redirect_uri }
   ```
   Store `refresh_token` (encrypted). **Sender: decode the `id_token` from the
   token response (JWT; read the `email` claim — no signature check needed, it
   came straight from Google's TLS endpoint) and store that as the connected
   mailbox** — the admin never types it. `Lean_SMTP_Notices::resume()` / clear
   any reconnect notice; `wp_safe_redirect()` back to the settings page with an
   ok/fail flag (reuse the `lsmtp_test`-style notice pattern).

3. **`admin_post_lean_smtp_gmail_disconnect`** — cap + nonce; best-effort
   `POST oauth2.googleapis.com/revoke?token=<refresh_token>`, then delete the
   stored refresh token, sender and cached transient.

### Redirect URI (the support-ticket generator)

`admin_url( 'admin-post.php?action=lean_smtp_gmail_callback' )` is the redirect
URI. Google requires it registered in the Cloud Console **exactly** (scheme,
host, path, query). The settings section must **print this URL read-only for
the admin to copy**, because a trailing-slash or http/https mismatch is the #1
failure. Localhost and `127.0.0.1` are allowed by Google for dev; a real
install needs its public https admin URL registered.

## Settings page section (`data-mailer="gmail_oauth"`)

- Intro: Workspace only; set the Cloud consent screen user type to **Internal**;
  enable the Gmail API; create an **OAuth client ID (Web application)**; add the
  redirect URI shown below.
- Read-only **redirect URI** field (copyable).
- Client ID (`text_field`) + Client secret (`secret_field`).
- **Connection status**: if `is_configured()` → "Connected as `sender`" +
  Disconnect button; else a Connect button (disabled until client ID/secret are
  *saved*, since the auth URL needs them server-side).
- `js/settings.js` already toggles sections by `data-mailer`; the new slug is
  picked up for free once it's in `mailer_labels()`.

## Wiring checklist (the `CLAUDE.md` "adding a provider" list)

- [ ] `lean-smtp.php`: `require_once` the new transport (and trait) before the
      mailer, after `class-lean-smtp-gmail.php`.
- [ ] `Lean_SMTP_Mailer`: `const MAILER_GMAIL_OAUTH = 'gmail_oauth';` and add
      `self::MAILER_GMAIL_OAUTH => 'Lean_SMTP_Gmail_OAuth'` to `transports()`.
      `mailers()` / `is_valid_mailer()` pick it up automatically.
- [ ] `Lean_SMTP_Settings`: `register_setting()` the four options (literal
      sanitize callbacks — `sanitize_text_field` for client ID,
      `sanitize_gmail_oauth_secret`/`sanitize_email` for the rest; the refresh
      token needs no register_setting since it's never posted through the form);
      add the label to `mailer_labels()` + a tag to `mailer_picker()`; add the
      `data-mailer` section; add the three `admin_post_*` actions in `init()`.
- [ ] `Lean_SMTP_CLI::mailer_rows()`: a `gmail_oauth` case (sender, client ID,
      a `secret_row` for the secret, and a "Connected: yes/no" line — never the
      refresh token value, per the no-secrets-in-status rule).
- [ ] `uninstall.php`: add the four options and the
      `_transient_lean_smtp_gmail_oauth_token_%` cleanup (mirror the existing
      service-account transient sweep).
- [ ] `Lean_SMTP_Config` constant support: free; document the four
      `LEAN_SMTP_GMAIL_OAUTH_*` constants.

## Tests

- `jwt_assertion` has no analogue here (no signing). Pure/unit-testable pieces:
  - the auth-URL builder (given client_id/redirect/state/sender → exact query).
  - `access_token()` error mapping — feed a fake `invalid_grant` body, assert it
    clears the stored token and returns the `reconnect_required` WP_Error (mock
    the HTTP layer the way the suite already does for the other transports).
  - the callback handler's state-nonce rejection path.
- `send()` / `send_message()` are covered by the same raw-MIME assertions the
  service-account transport uses (shared trait → one set of tests).

## Docs / release

- `readme.txt`: new transport in the description + a Changelog entry; bump
  `Version` header, `LEAN_SMTP_VERSION`, and `Stable tag` together (release
  rule).
- Setup walkthrough (consent screen = Internal, redirect URI, Connect).

## Decisions (settled)

1. **Two separate picker cards** — flat `gmail` and `gmail_oauth`, matching the
   flat `transports()` registry. No sub-selector.
2. **Sender captured via `openid email`** — the consent request adds those two
   scopes and the callback reads the `email` claim from the id_token; the admin
   never types the send-as mailbox. There is no `_sender` text field.
3. **Shared `Lean_SMTP_Gmail_Send` trait** — the send path is extracted from the
   shipping transport and `use`d by both; existing Gmail tests must stay green.
