# Contributing to Lean SMTP

Thanks for helping improve Lean SMTP. It is deliberately small — a handful of
mail transports and a handful of settings — so the guiding principle for
contributions is to keep it lean. New features earn their place by being broadly
useful without adding weight or upsells.

## How to Contribute

1. Fork the repository.
2. Create a branch for your change.
3. Make your change, with tests and passing lint (see below).
4. Open a pull request against `main`.

Take a look at the [issues](https://github.com/pjaudiomv/lean-smtp/issues) for
bugs and ideas you might be able to help with. Once your pull request is merged,
it goes out in the next version.

## Local Development Setup

The dev stack runs WordPress, a Mailpit SMTP sink, and MariaDB in Docker:

```bash
make dev
```

- WordPress: http://localhost:8082
- Mailpit (catches everything `wp_mail()` sends): http://localhost:8027

Complete the WordPress install wizard, then activate **Lean SMTP** under Plugins.
The plugin is volume-mounted, so edits take effect immediately. WP-CLI and the
MariaDB client are available inside the container:

```bash
docker compose exec wordpress wp --allow-root plugin list
docker compose exec wordpress wp --allow-root lean-smtp status
```

To exercise the plugin end to end, configure it as **Mailer: SMTP**, Host
`mailpit`, Port `1025`, Encryption None, Auth off, then watch mail arrive in
Mailpit.

## Code Standards

This project follows the **WordPress-Core** coding standard (tabs, snake_case,
Yoda conditions), enforced with PHP CodeSniffer. The rules live in `.phpcs.xml`,
and `.editorconfig` covers whitespace — install the EditorConfig plugin for your
editor so formatting stays consistent.

Check and auto-fix style:

```bash
make lint   # phpcs
make fmt    # phpcbf, fixes what it can
```

A few conventions worth knowing:

- `lean_smtp_` option prefix, `Lean_SMTP_` class prefix.
- Singleton classes with static hook methods.
- **Secrets never round-trip to the browser** — password/API-key fields render
  empty; a blank submission keeps the stored value. Never echo a decrypted
  secret into the page or into `wp lean-smtp status`.
- Any setting can be pinned in `wp-config.php` as an upper-case constant; read
  settings through `Lean_SMTP_Config` so that keeps working.

## Testing

Tests use [PHPUnit](https://phpunit.de/) with the
[WordPress test suite](https://github.com/wp-phpunit/wp-phpunit) and run inside
Docker, so you need neither PHP nor MySQL installed locally:

```bash
make test          # spins up MariaDB, installs WP core, runs the suite
make test-clean    # remove the test containers, images, and volumes
```

Run a single test by filter:

```bash
docker compose -f docker-compose.test.yml run --rm test --filter test_name
```

### Writing tests

Test files live in `tests/` with a `test-` prefix (e.g. `tests/test-feature.php`)
and extend `WP_UnitTestCase`, which gives you a full WordPress environment. HTTP
calls to mail providers are intercepted with the `pre_http_request` filter — see
`tests/test-mailgun.php` and `tests/test-resend.php` for the pattern.

### Adding a mail provider

Keep the shared assembly shared:

1. Implement `Lean_SMTP_Api_Transport` in its own `includes/` file — auth and
   body shape only. Read the `PHPMailer` you are handed; don't re-parse the
   `wp_mail()` arguments.
2. Register it in `Lean_SMTP_Mailer::transports()`.
3. Add a `data-mailer` section to the settings page and a case to
   `Lean_SMTP_CLI::mailer_rows()`.
4. Add its options to `uninstall.php`.
5. Never let a `Bcc` header reach the wire — carry those recipients in the
   provider's envelope (see `strip_bcc_header()`).

### Changing the send-log table

Edit the `CREATE TABLE` in `Lean_SMTP_Logger::create_table()` **and** bump
`Lean_SMTP_Logger::DB_VERSION` in the same change. `register_activation_hook()`
does not fire when a plugin is updated, so without the bump a new column never
reaches a site that already has the table, and every insert naming it fails.
`maybe_upgrade()` re-runs `dbDelta` when the stored version differs.

If the new column holds any part of the message, it must be behind its own
opt-in setting, off by default — `lean_smtp_log_headers` and
`lean_smtp_log_body` are the precedent. A stored body contains password-reset
links and personal data; the send log's default is to record *that* mail was
sent, not what was in it.

## Releasing

Maintainers cut releases by pushing a version tag. Three things must always
match in the same change: the plugin header `Version`, the `LEAN_SMTP_VERSION`
constant, and the readme.txt `Stable tag` — and every bump gets a matching
`== Changelog ==` entry.

- **CI** runs lint and the test matrix (PHP 8.3 / 8.4) on every pull request and
  push to `main`.
- Pushing a tag like `0.2.0` runs lint + tests, attaches a built zip to a GitHub
  release, and — when the `WORDPRESS_USERNAME` / `WORDPRESS_PASSWORD` secrets are
  set — publishes to the WordPress.org SVN repository (trunk, assets, and tag).
- A tag containing `beta` is published only as a GitHub release, not to
  WordPress.org.
