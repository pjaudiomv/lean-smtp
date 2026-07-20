# Lean SMTP

A small WordPress plugin that routes `wp_mail()` through an **SMTP** server or the **Amazon SES** API — the essentials of wp-mail-smtp, without the bulk.

## Features

- **SMTP** transport (TLS / SSL / none, optional auth). Works with any provider; point it at `email-smtp.{region}.amazonaws.com` for SES-over-SMTP.
- **Amazon SES (API)** transport via the SES v2 endpoint, signed with a hand-rolled AWS Signature V4 signer — **no AWS SDK dependency**.
- **From identity** — set From name/email, with optional "force" to override what other plugins set.
- **Test email** button on the settings page.
- **Send log** (optional) with an admin viewer and clear button.
- **Encrypted secrets** — SMTP password and SES secret key are AES-256-CBC encrypted at rest, keyed to the site salts.

## How it works

- **SMTP** is configured on the PHPMailer instance in the `phpmailer_init` action; WordPress does the actual sending.
- **SES** short-circuits `wp_mail()` via the `pre_wp_mail` filter: PHPMailer assembles the MIME message, and the raw bytes are POSTed to `email.{region}.amazonaws.com/v2/email/outbound-emails`.
- From name/email are applied through the standard `wp_mail_from` / `wp_mail_from_name` filters, so they govern both transports.

## Development

```bash
make dev    # docker compose dev site at http://localhost:8082; Mailpit SMTP sink UI at http://localhost:8027
make lint   # phpcs, WordPress-Core standard (tabs, snake_case)
make fmt    # phpcbf auto-fix
make test   # phpunit in Docker
make build  # zip a release into build/
```

To exercise the plugin end-to-end locally, set **Mailer: SMTP · Host: `mailpit` · Port: `1025` · Encryption: None · Auth: off** and watch messages arrive in Mailpit.

Run a single test:

```bash
docker compose -f docker-compose.test.yml run --rm test --filter test_authorization_header_matches_aws_get_vanilla_vector
```

## License

GPL-2.0-or-later.
