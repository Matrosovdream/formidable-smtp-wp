<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shortcode: [frm-emails-entry-simple entry="12345"]
 * Output (one line per email):
 *   Status - Subject - Date (MM/DD)
 */
add_action('init', function () {
    add_shortcode('frm-emails-entry-simple', 'frm_emails_entry_simple_shortcode');
});

function frm_emails_entry_simple_shortcode($atts = []) {
    if ( ! class_exists('FrmSmtpEmailModel') ) {
        return '<div style="color:#b00">FrmSmtpEmailModel not found.</div>';
    }

    $atts      = shortcode_atts(['entry' => ''], $atts, 'frm-emails-entry-simple');
    $entry_raw = trim((string)$atts['entry']);
    $entry_id  = (ctype_digit($entry_raw) && (int)$entry_raw > 0) ? (int)$entry_raw : 0;

    if ( $entry_id <= 0 ) {
        return '<div style="color:#b00">Please provide a valid entry id, e.g. <code>[frm-emails-entry-simple entry="14485"]</code>.</div>';
    }

    $model = new FrmSmtpEmailModel();
    $rows  = $model->getAllByEntryId( $entry_id, [
        'order_by' => 'date_sent',
        'order'    => 'DESC',
        'limit'    => 1000,
    ]);

    if ( is_wp_error($rows) ) {
        return '<div style="color:#b00">DB error: ' . esc_html($rows->get_error_message()) . '</div>';
    }

    if ( empty($rows) ) {
        return '<div>No emails found.</div>';
    }

    ob_start();
    echo '<div class="frm-emails-entry-simple-list">';
    foreach ( $rows as $r ) {
        $subject = (string)($r['subject'] ?? '');
        $status  = (string)($r['status'] ?? '');
        $dateRaw = (string)($r['date_sent'] ?? '');
        $date    = $dateRaw ? date_i18n('m/d', strtotime($dateRaw)) : '';

        // Render status via shortcode
        $status_html = do_shortcode('[frm-email-status status="' . esc_attr($status) . '"]');

        echo '<div class="frm-emails-entry-simple-line">'
            . $status_html
            . ' - '
            . esc_html($subject)
            . ' - '
            . esc_html($date)
            . '</div>';
    }
    echo '</div>';
    return ob_get_clean();
}
