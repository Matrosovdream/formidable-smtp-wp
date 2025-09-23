<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shortcode: [frm-email-status status="Sent|Failed|Waiting|Confirmed|0|1|2|3|..."]
 * Renders a colored badge for an email status.
 */
add_action('init', function () {
    add_shortcode('frm-email-status', 'frm_email_status_badge_shortcode');
});

/** Numeric → title map (mirrors your model’s STATUS_MAP) */
const FRM_EMAIL_STATUS_MAP = [
    0 => 'Sent',
    1 => 'Failed',
    2 => 'Waiting',
    3 => 'Confirmed',
];

/** Normalize any raw status (int or string) to a human title */
function frm_email_status_title($raw) : string {
    if ($raw === '' || $raw === null) return '';
    if (is_numeric($raw)) {
        $i = (int) $raw;
        return FRM_EMAIL_STATUS_MAP[$i] ?? (string) $raw;
    }
    return (string) $raw;
}

/** Decide badge class from title */
function frm_email_status_class(string $title) : string {
    $t = strtolower(trim($title));
    if ($t === 'sent' || $t === 'confirmed') return 'fel-badge--ok';
    if ($t === 'failed' || $t === 'error' || $t === 'bounced') return 'fel-badge--bad';
    if ($t === 'waiting' || $t === 'queued' || $t === 'pending') return 'fel-badge--wait';
    return 'fel-badge--neutral';
}

/** The shortcode renderer */
function frm_email_status_badge_shortcode($atts = []) {
    $atts = shortcode_atts(['status' => ''], $atts, 'frm-email-status');
    $title = frm_email_status_title($atts['status']);
    $class = frm_email_status_class($title);

    // Print CSS once per request
    static $printed_css = false;
    ob_start();
    if (!$printed_css) {
        $printed_css = true; ?>
        <style>
            .fel-badge{display:inline-block;border:1px solid #e5e7eb;padding:2px 8px;border-radius:999px;font-size:12px;color:#374151;background:#f9fafb;line-height:1.6}
            .fel-badge--ok{background:#ecfdf5;border-color:#10b981;color:#065f46}
            .fel-badge--bad{background:#fef2f2;border-color:#ef4444;color:#7f1d1d}
            .fel-badge--wait{background:#fffbeb;border-color:#f59e0b;color:#78350f}
            .fel-badge--neutral{background:#f3f4f6;border-color:#d1d5db;color:#374151}
        </style>
    <?php }
    ?>
    <span class="fel-badge <?php echo esc_attr($class); ?>">
        <?php echo esc_html($title ?: '—'); ?>
    </span>
    <?php
    return ob_get_clean();
}
