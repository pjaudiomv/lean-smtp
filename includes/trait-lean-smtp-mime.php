<?php
/**
 * MIME helpers shared by the raw-MIME transports (SES, Mailgun).
 *
 * @package lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Lean_SMTP_Mime {

	/**
	 * Remove the Bcc header from an assembled message.
	 *
	 * PHPMailer writes one because it assumes a sendmail-style transport will
	 * consume and drop it; an API transport does not. Neither SES nor Mailgun
	 * documents stripping a supplied Bcc, so leaving it in risks disclosing the
	 * hidden recipients to everyone on the message. Callers must therefore pass
	 * those addresses to the provider as an explicit envelope instead — this
	 * only rewrites the header block, never who the message reaches.
	 */
	protected static function strip_bcc_header( string $raw_mime ): string {
		// PHPMailer emits CRLF for most transports but LF for the sendmail/qmail
		// mailers (a `phpmailer_init` callback can switch to those), so detect the
		// ending in use rather than assuming CRLF — otherwise the header block is
		// never found and the Bcc leaks out intact. Only the header block is
		// rewritten; the body after the blank line is returned byte-for-byte.
		$eol       = ( false !== strpos( $raw_mime, "\r\n" ) ) ? "\r\n" : "\n";
		$separator = $eol . $eol;

		$split = strpos( $raw_mime, $separator );
		if ( false === $split ) {
			return $raw_mime;
		}

		$kept    = [];
		$dropped = false;

		foreach ( explode( $eol, substr( $raw_mime, 0, $split ) ) as $line ) {
			$is_continuation = isset( $line[0] ) && ( ' ' === $line[0] || "\t" === $line[0] );
			if ( $dropped && $is_continuation ) {
				continue; // A folded second line of the Bcc header.
			}
			$dropped = 0 === stripos( $line, 'bcc:' );
			if ( ! $dropped ) {
				$kept[] = $line;
			}
		}

		return implode( $eol, $kept ) . substr( $raw_mime, $split );
	}
}
