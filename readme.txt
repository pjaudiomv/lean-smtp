=== Lean SMTP ===
Contributors: pjaudiomv
Tags: smtp, mail, email, ses, wp_mail
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Route wp_mail() through SMTP or the Amazon SES, Mailgun, or Resend API — with From control, wp-config overrides, and mail-failure alerts.

== Description ==

Lean SMTP makes WordPress send its mail through a real mail service instead of the host's default PHP mail, which improves deliverability. It is deliberately small: a handful of transports, a handful of settings, no upsells.

Website: https://leansmtp.com

Every provider below also offers plain SMTP, so the SMTP transport alone covers all of them. The API transports exist for hosts that block outbound mail ports (25/465/587), which is common on shared hosting and some managed platforms.

* **SMTP** — any host/port with TLS, SSL, or no encryption, optional username/password.
* **Amazon SES (API)** — the SES v2 API, signed with a hand-rolled AWS Signature V4 signer, so no AWS SDK is bundled.
* **Mailgun (API)** — US or EU region, sending the message as MIME so attachments and formatting survive untouched.
* **Resend (API)** — a single API key, nothing else to configure.
* **Offline** — record every message and send nothing, for staging sites that must never mail real customers. wp_mail() still reports success, so plugins behave exactly as they would in production.
* **From identity** — set the From name and address, and optionally force them over anything another plugin sets.
* **Reply-To** — a site-wide default for replies, useful when the From address is a no-reply. A Reply-To the message set itself is always kept.
* **wp-config.php overrides** — pin any setting in code instead of the database, so credentials can live in environment variables and never diverge between environments.
* **Failure alerts** — an admin notice when mail stops going out, so a broken mailer isn't discovered via missed password resets. It clears itself once mail works again.
* **WP-CLI** — `wp lean-smtp test` and `wp lean-smtp status`.
* **Test email** — a button on the settings page to confirm your configuration works.
* **Send log** — optional; records the most recent sends (recipient, subject, result) with a viewer and a clear button. The message headers, attachment filenames and body can each be recorded too, behind their own settings and off by default.
* **Encrypted secrets** — stored passwords and API keys are AES-256 encrypted, keyed to your site salts.

Deliberately not included: Gmail and Microsoft 365 OAuth. Both need an OAuth consent flow, refresh-token storage, and (for Google) app verification — more machinery than the rest of this plugin combined. Use an app password or a provider above.

== Installation ==

1. Upload the plugin and activate it.
2. Go to Settings → Lean SMTP.
3. Choose a mailer and fill in the connection details.
4. Set your From Email and From Name.
5. Save, then use "Send a Test Email" to confirm it works.

For Amazon SES: create an IAM user limited to `ses:SendRawEmail`/`ses:SendEmail`, verify your From address (or domain) in the SES console, and, if your account is still in the SES sandbox, verify the recipient too.

For Mailgun: use the sending domain exactly as it appears in your Mailgun dashboard, and pick the region matching the account the key was issued in — a US key is not valid against the EU stack.

For Resend: create an API key with send permission and verify your From domain.

== Configuring in wp-config.php ==

Any setting can be defined as a constant instead of saved in the database. The constant name is the option name in upper case, and it always wins — the settings page shows the field as read-only and names the constant, so what you see is always what is in force. Removing the constant restores whatever was saved before.

    define( 'LEAN_SMTP_MAILER', 'ses' );
    define( 'LEAN_SMTP_FROM_EMAIL', 'noreply@example.com' );
    define( 'LEAN_SMTP_SES_REGION', 'us-east-1' );
    define( 'LEAN_SMTP_SES_ACCESS_KEY', 'AKIA…' );
    define( 'LEAN_SMTP_SES_SECRET_KEY', getenv( 'SES_SECRET_KEY' ) );

Secrets defined this way are used as-is and are never written to the database. Run `wp lean-smtp status` to see every setting and where it came from.

== External services ==

Lean SMTP sends email through whichever mail service you configure. When — and only when — you select an API transport and your site sends mail, the plugin connects to that provider's HTTPS API and transmits the message being sent (recipients, subject, body, headers, and any attachments) along with the credentials you entered, so the provider can authenticate the request and deliver the message. Nothing is sent until you configure and select a transport, and the plugin contacts no other services: there is no telemetry, tracking, or analytics.

The provider you choose is the data controller for the mail you route through it. Review its terms and privacy policy:

* Amazon SES — sends to `email.{region}.amazonaws.com`. Terms: https://aws.amazon.com/service-terms/ · Privacy: https://aws.amazon.com/privacy/
* Mailgun — sends to `api.mailgun.net` or `api.eu.mailgun.net`. Terms: https://www.mailgun.com/legal/terms/ · Privacy: https://www.mailgun.com/legal/privacy-policy/
* Resend — sends to `api.resend.com`. Terms: https://resend.com/legal/terms-of-service · Privacy: https://resend.com/legal/privacy-policy

The SMTP transport connects only to the host you configure and bundles no third-party service. The Offline mailer connects to nothing at all: messages are written to the local send log and never leave your server.

== Screenshots ==

1. Settings page: pick a mailer, set the From identity, and configure the transport. A dismissible notice warns when the last send failed.
2. Amazon SES — region and IAM access key; the secret key is stored encrypted and never shown.
3. Mailgun — sending domain, US/EU region, and API key.
4. Resend — a single API key.
5. Send a test email, and review the optional send log of recent attempts.

== Changelog ==

= 0.3.0 =
* Added an Offline mailer: every message is recorded and nothing is sent, for staging sites that must not mail real customers. wp_mail() still reports success, so other plugins behave as they would in production.
* The send log can now record the message headers, attachment filenames and body. Each is a separate setting and both are off by default — a stored body contains password-reset links and anything else your site mails. Recorded content is shown in an expandable row in the log viewer, alongside the full error text for a failed send.
* Added a Reply-To setting, applied to both the SMTP and API paths. A Reply-To the message set for itself is always kept.
* The log table is now upgraded in place when the plugin is updated. Previously the table was only ever created on activation, which does not run on update.

= 0.2.1 =
* The settings screen's CSS and JavaScript are now enqueued as files on that screen only, instead of being printed inline.
* Each setting is now registered with its own named sanitize callback, with the wp-config.php constant guard applied as a separate filter on top.

= 0.2.0 =
* Added a Mailgun transport (US and EU regions), sending the assembled message as MIME.
* Added a Resend transport.
* Bcc recipients on API sends are now carried as an explicit envelope and the Bcc header is stripped from the delivered message, so hidden recipients are never disclosed to other recipients.
* Any setting can now be pinned in wp-config.php as an upper-case constant; a pinned setting renders read-only on the settings page and is left untouched when the form is saved.
* Added an admin notice when a send fails, so a silently broken mailer is noticed. It clears itself after the next successful send.
* Added WP-CLI commands: `wp lean-smtp test [<recipient>]` and `wp lean-smtp status`.
* The API transports now share one message assembly behind a Lean_SMTP_Api_Transport interface, so all of them reproduce core's wp_mail() semantics identically.

= 0.1.1 =
* Fixed Amazon SES sends failing with an HTTP 403 "signature does not match" error caused by the secret key being encrypted twice on its first save (WordPress runs a setting's sanitize callback twice when the option is first created). Re-enter your SES secret key (and SMTP password) after updating.
* SES requests now sign only the required host and date headers, avoiding signature mismatches when the HTTP transport rewrites the Content-Type header.
* Fixed failed SES sends being written to the send log twice; every send is now recorded exactly once.

= 0.1.0 =
* Initial release. SMTP and Amazon SES (API) transports for wp_mail(), From name/email with optional forcing, a test-email button, optional send logging, and AES-256 at-rest encryption of stored credentials.
