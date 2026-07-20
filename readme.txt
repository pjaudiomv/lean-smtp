=== Simple SMTP ===
Contributors: pjaudiomv
Tags: smtp, mail, email, ses, wp_mail
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Routes wp_mail() through an SMTP server or the Amazon SES API, with From identity control, a test-email button, and optional send logging.

== Description ==

Simple SMTP makes WordPress send its mail through a real mail service instead of the host's default PHP mail, which improves deliverability. It is deliberately small: two transports, a handful of settings, no upsells.

* **SMTP** — any host/port with TLS, SSL, or no encryption, optional username/password. Point it at `email-smtp.{region}.amazonaws.com` to use Amazon SES over SMTP.
* **Amazon SES (API)** — sends through the SES v2 API using a hand-rolled AWS Signature V4 signer, so no AWS SDK is bundled.
* **From identity** — set the From name and address, and optionally force them over anything another plugin sets.
* **Test email** — a button on the settings page to confirm your configuration works.
* **Send log** — optional; records the most recent sends (recipient, subject, result) with a viewer and a clear button.
* **Encrypted secrets** — the SMTP password and SES secret key are stored AES-256 encrypted, keyed to your site salts.

== Installation ==

1. Upload the plugin and activate it.
2. Go to Settings → Simple SMTP.
3. Choose a mailer (SMTP or Amazon SES) and fill in the connection details.
4. Set your From Email and From Name.
5. Save, then use "Send a Test Email" to confirm it works.

For Amazon SES: create an IAM user limited to `ses:SendRawEmail`/`ses:SendEmail`, verify your From address (or domain) in the SES console, and, if your account is still in the SES sandbox, verify the recipient too.

== Changelog ==

= 0.1.0 =
* Initial release. SMTP and Amazon SES (API) transports for wp_mail(), From name/email with optional forcing, a test-email button, optional send logging, and AES-256 at-rest encryption of stored credentials.
