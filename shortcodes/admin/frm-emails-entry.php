<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shortcode: [frm-emails-entry entry="12345"]
 * - No filters/header, no pagination
 * - Renders responsive "cards" per email
 * - Shows: Subject, View Content (modal), Email From, Email To, Status, Date
 * - Hides Entry ID
 */
add_action('init', function () {
    add_shortcode('frm-emails-entry', 'frm_emails_entry_shortcode');
});

function frm_emails_entry_shortcode($atts = []) {
    if ( ! class_exists('FrmSmtpEmailModel') ) {
        return '<div style="color:#b00">FrmSmtpEmailModel not found.</div>';
    }

    $atts = shortcode_atts(['entry' => ''], $atts, 'frm-emails-entry');
    $entry_raw = trim((string)$atts['entry']);
    $entry_id  = (ctype_digit($entry_raw) && (int)$entry_raw > 0) ? (int)$entry_raw : 0;

    if ( $entry_id <= 0 ) {
        return '<div style="color:#b00">Please provide a valid entry id, e.g. <code>[frm-emails-entry entry="14485"]</code>.</div>';
    }

    // Fetch all emails for this entry (newest first). No pagination => use a large limit.
    $model = new FrmSmtpEmailModel();
    $rows  = $model->getAllByEntryId( $entry_id, [
        'order_by' => 'date_sent',
        'order'    => 'DESC',
        'limit'    => 1000, // adjust if you expect more than 1000 messages per entry
    ]);

    if ( is_wp_error($rows) ) {
        return '<div style="color:#b00">DB error: ' . esc_html($rows->get_error_message()) . '</div>';
    }

    ob_start();
    ?>
    <style>
        .fel-grid { display:grid; gap:14px; grid-template-columns:repeat(auto-fit, minmax(260px,1fr)); }
        .fel-card { border:1px solid #e5e7eb; border-radius:12px; background:#fff; box-shadow:0 3px 12px rgba(0,0,0,.04); overflow:hidden; display:flex; flex-direction:column; }
        .fel-card header { padding:12px 14px; border-bottom:1px solid #f0f2f5; background:#f9fafb; }
        .fel-card header .subject { font-weight:600; line-height:1.35; color:#111827; word-break:break-word; }
        .fel-card .body { padding:12px 14px; display:flex; flex-direction:column; gap:10px; }
        .fel-meta { display:grid; gap:6px; font-size:13px; color:#4b5563; }
        .fel-row { display:flex; align-items:baseline; gap:8px; }
        .fel-label { min-width:70px; color:#6b7280; }
        .fel-actions { margin-top:6px; display:flex; gap:8px; }
        .fel-btn { display:inline-block; padding:8px 12px; border-radius:8px; border:1px solid #1f6feb; background:#1f6feb; color:#fff; text-decoration:none; cursor:pointer; }
        .fel-badge { display:inline-block; border:1px solid #e5e7eb; padding:2px 8px; border-radius:999px; font-size:12px; color:#374151; background:#f9fafb; }

        /* Modal */
        #fel-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; opacity:0; z-index:2147483647; align-items:flex-start; justify-content:center; padding:6vh 12px; }
        #fel-modal-overlay.show { display:flex; opacity:1; }
        #fel-modal { background:#fff; width:min(960px,96vw); max-height:88vh; overflow:auto; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.2); }
        #fel-modal header { padding:12px 16px; border-bottom:1px solid #eaeef2; display:flex; justify-content:space-between; align-items:center; }
        #fel-modal .title { font-weight:600; }
        #fel-modal .close { border:none; background:transparent; font-size:22px; line-height:1; cursor:pointer; }
        #fel-modal .body { padding:16px; }
        #fel-modal .meta { margin-bottom:10px; color:#57606a; font-size:13px; }
        #fel-modal .content { border:1px solid #eaeef2; border-radius:8px; padding:12px; }
        #fel-modal .content pre { white-space:pre-wrap; }
    </style>

    <div class="fel-grid">
        <?php if (empty($rows)): ?>
            <div class="fel-card">
                <header><div class="subject">No emails found</div></header>
                <div class="body"><div class="fel-meta">There are no emails for this entry.</div></div>
            </div>
        <?php else: ?>
            <?php foreach ($rows as $i => $r):
                $rid      = (int)($r['id'] ?? 0);
                $subject  = (string)($r['subject'] ?? '');
                $from     = (string)($r['email_from'] ?? '');
                $to       = (string)($r['email_to'] ?? '');
                $status   = $r['status'];
                $dateRaw  = (string)($r['date_sent'] ?? '');
                $dateFmt  = $dateRaw ? date_i18n('Y-m-d H:i', strtotime($dateRaw)) : '';

                $html     = (string)($r['content_html'] ?? '');
                $plain    = (string)($r['content_plain'] ?? '');

                // Prepare viewable content (sanitize HTML; fallback to plain text)
                if ($html !== '') {
                    $content = wp_kses_post($html);
                } else {
                    $content = '<pre>' . esc_html($plain) . '</pre>';
                }
                $tpl_id = 'fel-content-' . $rid . '-' . $i;
            ?>
                <div class="fel-card">
                    <header>
                        <div class="subject"><?php echo esc_html($subject); ?></div>
                    </header>
                    <div class="body">
                        <div class="fel-meta">
                            <div class="fel-row"><div class="fel-label">From</div><div><?php echo esc_html($from); ?></div></div>
                            <div class="fel-row"><div class="fel-label">To</div><div style="word-break:break-word;"><?php echo esc_html($to); ?></div></div>
                            <div class="fel-row">
                                <div class="fel-label">
                                    Status
                                </div>
                                <div>
                                    <?php echo do_shortcode('[frm-email-status status="' . esc_attr($status) . '"]'); ?>
                                </div>
                            </div>
                            <div class="fel-row"><div class="fel-label">Date</div><div><?php echo esc_html($dateFmt); ?></div></div>
                        </div>
                        <div class="fel-actions">
                            <a href="#" class="fel-btn fel-view" data-tpl="<?php echo esc_attr($tpl_id); ?>" data-title="<?php echo esc_attr($subject); ?>">View content</a>
                        </div>
                        <!-- hidden content template for modal -->
                        <div id="<?php echo esc_attr($tpl_id); ?>" style="display:none"><?php echo $content; ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Modal -->
    <div id="fel-modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
        <div id="fel-modal">
            <header>
                <div class="title">Email content</div>
                <button type="button" class="close" aria-label="Close">&times;</button>
            </header>
            <div class="body">
                <div class="meta"></div>
                <div class="content"></div>
            </div>
        </div>
    </div>

    <script>
    (function(){
        const overlay = document.getElementById('fel-modal-overlay');
        const modal   = document.getElementById('fel-modal');
        const bodyEl  = modal.querySelector('.content');
        const metaEl  = modal.querySelector('.meta');

        function openModal(title, html){
            metaEl.textContent = title || '';
            bodyEl.innerHTML = html || '<em>No content</em>';
            overlay.classList.add('show');
            overlay.setAttribute('aria-hidden','false');
        }
        function closeModal(){
            overlay.classList.remove('show');
            overlay.setAttribute('aria-hidden','true');
            bodyEl.innerHTML = '';
            metaEl.textContent = '';
        }

        overlay.addEventListener('click', function(e){
            if (e.target === overlay) closeModal();
        });
        modal.querySelector('.close').addEventListener('click', closeModal);

        document.addEventListener('click', function(e){
            const btn = e.target.closest('a.fel-view');
            if (!btn) return;
            e.preventDefault();
            const tplId = btn.getAttribute('data-tpl');
            const title = btn.getAttribute('data-title') || '';
            const tpl   = document.getElementById(tplId);
            if (tpl) {
                openModal(title, tpl.innerHTML);
            }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
