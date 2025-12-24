<?php
/*
Plugin Name: Formidable SMTP Logs Extension
Description: 
Version: 1.0
Plugin URI: 
Author URI: 
Author: Stanislav Matrosov
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Variables
define('FRM_SMTP_BASE_URL', __DIR__);

// Initialize core

require_once 'classes/FrmSmtpInit.php';


add_action('init', 'FrmSmtpInit');
function FrmSmtpInit() {
    
    if( isset( $_GET['update_exported'] ) ) {

        smtpUpdateExported();
        exit();

    }

    if( isset( $_GET['get_unexported'] ) ) {

        smtpGetUnexported();
        exit();

    }

}

function smtpGetUnexported() {

    global $wpdb;
    $table = $wpdb->prefix . 'frm_emails_log';

    $query = "SELECT COUNT(*) as count FROM {$table} WHERE updated IS NULL";

    $result = $wpdb->get_var($query);

    echo 'Unexported logs count: ' . $result;

}

function smtpUpdateExported() {

    global $wpdb;
    $table = $wpdb->prefix . 'frm_emails_log';

    $query = "UPDATE {$table} SET updated = 1 WHERE id < 3210613354";

    $wpdb->query($query);

    echo 'Exported status updated for logs.';

}



final class WPMSMTP_Gmail_Response_Mailer {

    public static function init(): void {
        add_action(
            'wp_mail_smtp_providers_gmail_mailer_process_response',
            [ __CLASS__, 'handle' ],
            10,
            2
        );
    }

    /**
     * @param mixed $response
     * @param PHPMailer $phpmailer
     */
    public static function handle( $response, $phpmailer ): void {

        $email = 'matrosovdream@gmail.com';

        // Extract recipient (optional, for context)
        $to = '';
        if ( is_object($phpmailer) && ! empty($phpmailer->to[0][0]) ) {
            $to = $phpmailer->to[0][0];
        }

        $subject = '[WP Mail SMTP] Gmail send response';

        $message  = "Recipient:\n" . ($to ?: 'N/A') . "\n\n";
        $message .= "Response:\n";
        $message .= print_r($response, true);

        wp_mail(
            $email,
            $subject,
            $message,
            [ 'Content-Type: text/plain; charset=UTF-8' ]
        );
    }
}

WPMSMTP_Gmail_Response_Mailer::init();












































