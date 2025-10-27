<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shortcode: [frm-emails-entry-simple entry="12345"]
 * Output: status icon (with tooltip) - Subject - Date (MM/DD)
 * Colors:
 *   Failed → red
 *   Sent / Waiting → blue
 *   Confirmed → green
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

    ob_start(); ?>

    <style>
        .frm-emails-entry-simple-line {
            font-size: 10px;
            line-height: 1.1em;
            margin-bottom: 0px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .fel-icon {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .fel-icon.red { background-color: #dc2626; }   /* Failed */
        .fel-icon.blue { background-color: #2563eb; }  /* Sent, Waiting */
        .fel-icon.green { background-color: #16a34a; } /* Confirmed */
        .fel-icon.gray { background-color: #6b7280; }  /* fallback */
    </style>

    <div class="frm-emails-entry-simple-list">
        <?php foreach ( $rows as $key => $r ) :
            $subject = (string)($r['subject'] ?? '');
            $status  = (string)($r['status'] ?? '');
            $dateRaw = (string)($r['date_sent'] ?? '');
            $date    = $dateRaw ? date_i18n('m/d', strtotime($dateRaw)) : '';

            // Determine color
            switch ( $status ) {
                case 'Failed':     $color = 'red'; break;
                case 'Sent':       $color = 'blue'; break;
                case 'Waiting':    $color = 'blue'; break;
                case 'Confirmed':  $color = 'green'; break;
                default:           $color = 'gray'; break;
            }
        ?>
            <div class="frm-emails-entry-simple-line">
                <span class="fel-icon <?php echo esc_attr($color); ?>" 
                      title="<?php echo esc_attr($status); ?>"></span>
                <span><?php echo esc_html($subject); ?></span>
                <span>- <?php echo esc_html($date); ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <?php
    return ob_get_clean();
}
