# Lean SMTP plugin review

Date: October 13, 2026  
Reviewed commit: `174621e6cf79753a0e634747b464a18ad6c1f46f`

## Summary

The review found **six reproducible issues**, plus gaps in tests, CI, and release tooling. The existing checks passed: **PHPCS and 101 tests with 369 assertions**. Temporary regression checks exposed problems those tests miss.

The review did not change plugin source files. Provider requests were mocked; no real emails were sent. An isolated Docker test stack was used and removed after testing.

## Bugs

### 1. High: cancelled emails can still be sent

Both mail handlers ignore the incoming `$short_circuit` value. When an earlier `pre_wp_mail` filter returns `false`, the API handler still contacts the provider and reports success. An earlier handler that already sent the message could also cause duplicate delivery.

**Reproduction:** register a `pre_wp_mail` callback at priority 1 that returns `false`, select Resend, and call `wp_mail()`. The mocked provider still receives one request.

**Recommendation:** return immediately when the incoming value is non-null, before sending, logging, or firing success hooks. Apply this to both the API and offline handlers.

References:

- [API handler](includes/class-lean-smtp-mailer.php#L337)
- [Offline handler](includes/class-lean-smtp-mailer.php#L258)

### 2. High: Bcc stripping fails with certain mail hooks

The Bcc stripper only handles CRLF line endings. On Linux, a `phpmailer_init` callback selecting `isSendmail()` produces LF endings, leaving `Bcc:` in the MIME submitted to SES or Mailgun.

**Reproduction:** select SES, add a `phpmailer_init` callback that calls `isSendmail()`, and send a message with a Bcc recipient. The hidden address remains in the outgoing API payload. Actual recipient exposure depends on provider handling; no live provider delivery was tested. A control test confirmed normal CRLF stripping works.

**Recommendation:** support both line endings or enforce a consistent format before stripping. Test folded Bcc headers and ensure body content remains untouched.

Reference: [MIME helper](includes/trait-lean-smtp-mime.php#L24).

### 3. Medium: mail filters execute twice

WordPress applies the `wp_mail` filter before invoking `pre_wp_mail`; the API and offline handlers apply it again. Body wrappers, attachments, and filter side effects can therefore be duplicated.

**Reproduction:** a filter appending ` [filtered]` to the subject changes `Subject` into `Subject [filtered] [filtered]` through the API path. A separate offline check also recorded two filter invocations.

**Recommendation:** remove the second application in both handlers and test through actual `wp_mail()` calls.

References:

- [API handler](includes/class-lean-smtp-mailer.php#L338)
- [Offline handler](includes/class-lean-smtp-mailer.php#L259)
- [WordPress source](https://developer.wordpress.org/reference/functions/wp_mail/)

### 4. Medium: Resend breaks HTML messages with a plain-text alternative

When another plugin sets `AltBody`, PHPMailer's `preSend()` changes `ContentType` to `multipart/alternative`. The subsequent Resend check only recognizes `text/html`, so it sends the HTML markup as plain text and discards the alternative.

**Reproduction:** send an HTML message while a `phpmailer_init` callback sets `AltBody`. The outgoing JSON contains `"text":"<p>HTML body</p>"` and no `html` field.

**Recommendation:** preserve the HTML body and plain-text alternative when translating the assembled message to Resend's payload. Add coverage for HTML with an alternative, attachments, and inline images.

Reference: [Resend mapping](includes/class-lean-smtp-resend.php#L104).

### 5. Medium: API transports silently drop embedded images

WordPress added the sixth `$embeds` argument in 6.9. The plugin's copied mail assembly never reads it, so `cid:` references remain without their image attachments.

**Reproduction:** call `wp_mail()` with an HTML body referencing `cid:logo` and an embeds array containing the image. The API payload lacks `Content-ID: <logo>`. The same input works through native WordPress in a control test.

**Recommendation:** implement the embeds argument and its associated `wp_mail_embed_args` filter, and preserve embeds in the success/failure hook payloads where appropriate.

References:

- [Message assembly](includes/class-lean-smtp-mailer.php#L340)
- [WordPress documentation](https://developer.wordpress.org/reference/functions/wp_mail/)

### 6. Medium: Gmail key rotation does not invalidate cached tokens

The token cache key includes the service-account email and sender, but not the private key. Replacing credentials for the same account continues using the old token, potentially making a test appear successful without validating the replacement credentials.

**Reproduction:** send with one generated private key, replace it with another key for the same service-account email, then send again. Only one token exchange occurs across both sends.

**Recommendation:** include a credential fingerprint in the cache key and add token invalidation/recovery for authentication failures. Test cache hits, expiry, rotation, and rejected cached tokens.

Reference: [Token cache](includes/class-lean-smtp-gmail.php#L179).

## Test gaps

1. **Exercise API transports through actual `wp_mail()` calls.** Most current API tests call `send_via_api()` directly, bypassing the hook integration that exposed the cancellation and double-filter bugs.
2. **Test Gmail's HTTP lifecycle.** Cover token exchange, cache expiry, key rotation, failed authentication, and send responses. Existing Gmail tests cover signing and configuration, but not token caching or sending.
3. **Add SMTP delivery tests using Mailpit.** Cover actual delivery and complement it with appropriate fixtures for authentication failures and encryption settings.
4. **Cover administrative and lifecycle behavior.** Add checks for permission/nonce failures, uninstall cleanup, WP-CLI, and multisite behavior.
5. **Keep regression coverage for the findings above.** Include normal-path controls so tests distinguish supported behavior from the failure scenarios.

## CI and release improvements

### Test the advertised minimum versions

The readme promises PHP 8.1 and WordPress 6.0, but CI runs PHP 8.3/8.4 against only latest WordPress. Add coverage for the declared minimum versions and keep the WordPress test library compatible with each selected core version.

Also add `Requires PHP` and `Requires at least` to the main plugin header, which currently omits them.

References:

- [PR workflow matrix](.github/workflows/pull-requests.yml#L38)
- [Declared requirements](readme.txt#L4)
- [Main plugin header](lean-smtp.php#L3)

### Validate versions before publishing

GitHub release creation precedes version validation. That validation is skipped entirely when WordPress.org credentials are absent and never checks `LEAN_SMTP_VERSION`.

Move a complete consistency check ahead of publication. Validate the Git tag, plugin header, version constant, readme stable tag, and matching changelog entry independently of deployment credentials.

References:

- [Release publication](.github/workflows/release.yml#L104)
- [Credential-dependent deployment validation](.github/scripts/deploy-wordpress.sh#L24)
- [Version comparison](.github/scripts/deploy-wordpress.sh#L44)

### Make Docker tests reproducible

The test Dockerfile falls back from `composer install` to `composer update`, which can silently test a different dependency set. There is also no `.dockerignore` excluding local `vendor/`, so `COPY . .` can overwrite the dependencies just installed in the image.

Remove the update fallback and exclude local dependencies and other unnecessary build context files.

Reference: [Test Dockerfile](Dockerfile.test#L15).

### Fix stale build archives

The ZIP target has no dependencies. Subsequent `make build` calls reuse an existing archive even after new commits, unless it is removed first.

Make the archive rebuild explicitly or depend on the intended source revision. A repeated build during the review reported `Nothing to be done for build` after the initial archive was created; the missing prerequisites explain why a revision change would not invalidate it.

Reference: [Build target](Makefile#L17).

## Small feature additions

These additions fit the plugin's deliberately minimal scope:

- **Clear saved credentials:** provide an explicit action, since a blank secret field intentionally keeps the existing value.
- **Richer test email:** optionally send HTML, an attachment, and an inline image to expose message-format issues a plain-text test cannot catch.
- **Provider message IDs in logs:** retain the provider's identifier to make support and delivery tracing easier.
- **Optional age-based log expiration:** complement the row-count cap so old message content does not remain indefinitely on low-volume sites.

Prioritize the reproducible bugs and their regression tests before adding more providers.

## Validation evidence and limitations

| Check | Result |
| --- | --- |
| PHPCS (`make lint`) | Passed |
| Existing PHPUnit suite in Docker | 101 tests, 369 assertions; passed |
| Temporary review checks | 9 tests, 19 assertions; 7 failed assertions across 6 distinct issues, 2 controls passed |
| Release archive inspection | Archive contained plugin runtime files and excluded the configured development files |
| Live provider delivery | Not performed; HTTP requests were mocked |
| Repository source changes during review | None |

Temporary evidence was saved outside the repository:

- Regression checks: `/tmp/lean-smtp-review/ReviewTest.php`
- Results: `/tmp/lean-smtp-review/results.txt`

Those temporary files may be removed by the operating system and are not committed regression coverage. The review examined local workflow definitions; it did not verify remote branch protections or GitHub Actions run history. File references describe the reviewed commit and may move as fixes are made.
