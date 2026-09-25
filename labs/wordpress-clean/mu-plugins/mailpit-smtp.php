<?php
/**
 * Route wp_mail to Mailpit SMTP (container hostname "mailpit":1025).
 * Installed into the WordPress named volume — not bind-mounted.
 */
add_action(
	'phpmailer_init',
	static function ( $phpmailer ): void {
		$phpmailer->isSMTP();
		$phpmailer->Host        = 'mailpit';
		$phpmailer->Port        = 1025;
		$phpmailer->SMTPAuth    = false;
		$phpmailer->SMTPSecure  = false;
		$phpmailer->SMTPAutoTLS = false;
		// Official WP image defaults From to wordpress@localhost (rejected).
		if ( empty( $phpmailer->From ) || false !== stripos( (string) $phpmailer->From, 'localhost' ) ) {
			$phpmailer->From = 'wordpress@example.test';
		}
		if ( empty( $phpmailer->FromName ) ) {
			$phpmailer->FromName = 'WordPress Clean';
		}
	}
);

add_filter(
	'wp_mail_from',
	static function ( string $from ): string {
		if ( $from === '' || false !== stripos( $from, 'localhost' ) ) {
			return 'wordpress@example.test';
		}
		return $from;
	}
);
