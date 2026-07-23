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
		$split = strpos( $raw_mime, "\r\n\r\n" );
		if ( false === $split ) {
			return $raw_mime;
		}

		$kept    = [];
		$dropped = false;

		foreach ( explode( "\r\n", substr( $raw_mime, 0, $split ) ) as $line ) {
			$is_continuation = isset( $line[0] ) && ( ' ' === $line[0] || "\t" === $line[0] );
			if ( $dropped && $is_continuation ) {
				continue; // A folded second line of the Bcc header.
			}
			$dropped = 0 === stripos( $line, 'bcc:' );
			if ( ! $dropped ) {
				$kept[] = $line;
			}
		}

		return implode( "\r\n", $kept ) . substr( $raw_mime, $split );
	}
}
