<p align="center">
  <img src="assets/banner-772x250.png" alt="Lean SMTP" width="772">
</p>

# Lean SMTP

[![pull-request](https://github.com/pjaudiomv/lean-smtp/actions/workflows/pull-requests.yml/badge.svg)](https://github.com/pjaudiomv/lean-smtp/actions/workflows/pull-requests.yml)
[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](LICENSE)

A small WordPress plugin that routes `wp_mail()` through an **SMTP** server or the **Amazon SES**, **Mailgun**, or **Resend** API — the essentials of wp-mail-smtp, without the bulk.

Every provider below also offers plain SMTP, so the SMTP transport alone covers all of them. The API transports exist for hosts that block outbound mail ports (25/465/587), common on shared hosting and some managed platforms.

## Features

- **SMTP** transport (TLS / SSL / none, optional auth) — works with any provider.
- **Amazon SES (API)** via the SES v2 endpoint, signed with a hand-rolled AWS Signature V4 signer — **no AWS SDK dependency**.
- **Mailgun (API)** — US or EU region, sending the message as MIME so attachments and formatting survive untouched.
- **Resend (API)** — a single API key, nothing else to configure.
- **From identity** — set From name/email, with optional "force" to override what other plugins set.
- **wp-config.php overrides** — pin any setting in code, so credentials can live in environment variables and never drift between environments.
- **Failure alerts** — a dismissible admin notice when mail stops going out; it clears itself once mail works again.
- **WP-CLI** — `wp lean-smtp test` and `wp lean-smtp status`.
- **Test email** button on the settings page.
- **Send log** (optional) with an admin viewer and clear button.
- **Encrypted secrets** — stored passwords and API keys are AES-256-CBC encrypted at rest, keyed to the site salts.

Deliberately **not** included: Gmail / Microsoft 365 OAuth. Both need an OAuth consent flow, refresh-token storage, and (for Google) app verification — more machinery than the rest of the plugin combined. Use an app password or one of the providers above.

## How it works

- **SMTP** is configured on the PHPMailer instance in the `phpmailer_init` action; WordPress does the actual sending.
- The **API** transports short-circuit `wp_mail()` via the `pre_wp_mail` filter: the plugin assembles the message with PHPMailer once — a faithful port of core's `wp_mail()` handling — then hands it to the selected transport, which adds only authentication and body shape. Raw-MIME providers (SES, Mailgun) read `getSentMIMEMessage()`; Resend reads the structured properties.
- From name/email are applied through the standard `wp_mail_from` / `wp_mail_from_name` filters, so they govern every transport.

## Configuring in wp-config.php

Any setting can be a constant instead of a stored option. The constant name is the option name upper-cased, and it always wins — the settings page shows the field read-only and names the constant.

```php
define( 'LEAN_SMTP_MAILER', 'ses' );
define( 'LEAN_SMTP_FROM_EMAIL', 'noreply@example.com' );
define( 'LEAN_SMTP_SES_REGION', 'us-east-1' );
define( 'LEAN_SMTP_SES_SECRET_KEY', getenv( 'SES_SECRET_KEY' ) );
```

Secrets defined this way are used as-is and never written to the database. Run `wp lean-smtp status` to see every setting and where it came from.

## Development

```bash
make dev    # docker compose dev site at http://localhost:8082; Mailpit SMTP sink UI at http://localhost:8027
make lint   # phpcs, WordPress-Core standard (tabs, snake_case)
make fmt    # phpcbf auto-fix
make test   # phpunit in Docker
make build  # zip a release into build/
```

WP-CLI and the MariaDB client are baked into the dev image:

```bash
docker compose exec wordpress wp --allow-root lean-smtp status
```

To exercise the plugin end-to-end locally, set **Mailer: SMTP · Host: `mailpit` · Port: `1025` · Encryption: None · Auth: off** and watch messages arrive in Mailpit.

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full workflow, coding standards, and how to add a mail provider.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
