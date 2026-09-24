<?php
/**
 * Plugin Name: Local Mailpit
 * Description: Skickar labbmejl till Mailpit i stället för riktiga mottagare.
 *
 * @package Foreningsplugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

add_filter('wp_mail_from', static function (string $from): string {
    if (is_email($from)) {
        return $from;
    }

    return 'wordpress@localhost.local';
});

add_action('phpmailer_init', static function (PHPMailer\PHPMailer\PHPMailer $phpmailer): void {
    $phpmailer->isSMTP();
    $phpmailer->Host = getenv('MAILPIT_SMTP_HOST') ?: 'mailpit';
    $phpmailer->Port = (int) (getenv('MAILPIT_SMTP_PORT') ?: 1025);
    $phpmailer->SMTPAuth = false;
    $phpmailer->SMTPAutoTLS = false;
    $phpmailer->SMTPSecure = '';
});
