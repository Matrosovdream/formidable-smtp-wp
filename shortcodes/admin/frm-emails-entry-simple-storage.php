<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shortcode: [frm-emails-entry-simple entry="12345"]
 * - AJAX loads emails for entry via FrmEmailLogService::getEmailLogsByEntry($entry_id)
 * - Output rows: status icon (tooltip) - Subject - Date (MM/DD)
 * - Loader overlay (same idea as your cards version)
 *
 * Colors:
 *   Failed       → red
 *   Sent/Waiting → blue
 *   Confirmed    → green
 *   fallback     → gray
 */

add_action('init', function () {
    add_shortcode('frm-emails-entry-simple-storage', 'frm_emails_entry_simple_storage_shortcode');
});

/** AJAX handlers */
add_action('wp_ajax_frm_emails_entry_simple_storage_fetch', 'frm_emails_entry_simple_storage_fetch_ajax');
add_action('wp_ajax_nopriv_frm_emails_entry_simple_storage_fetch', 'frm_emails_entry_simple_storage_fetch_ajax');

function frm_emails_entry_simple_storage_shortcode($atts = []) {

    if ( ! class_exists('FrmEmailLogService') ) {
        return '<div style="color:#b00">Storage plugin not active.</div>';
    }

    $atts      = shortcode_atts(['entry' => ''], $atts, 'frm-emails-entry-simple');
    $entry_raw = trim((string)$atts['entry']);
    $entry_id  = (ctype_digit($entry_raw) && (int)$entry_raw > 0) ? (int)$entry_raw : 0;

    if ( $entry_id <= 0 ) {
        return '<div style="color:#b00">Please provide a valid entry id, e.g. <code>[frm-emails-entry-simple entry="14485"]</code>.</div>';
    }

    $ajax_url = admin_url('admin-ajax.php');
    $nonce    = wp_create_nonce('frm_emails_entry_simple_storage_fetch');

    ob_start();
    ?>
    <style>
        .fes-wrap { font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; position:relative; }
        .fes-error { color:#b00; padding:6px 0; display:none; font-size:12px; }

        .fes-list { position:relative; display:flex; flex-direction:column; gap:2px; }

        .fes-line {
            font-size: 10px;
            line-height: 1.1em;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .fes-icon {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .fes-icon.red { background-color: #dc2626; }   /* Failed */
        .fes-icon.blue { background-color: #2563eb; }  /* Sent, Waiting */
        .fes-icon.green { background-color: #16a34a; } /* Confirmed */
        .fes-icon.gray { background-color: #6b7280; }  /* fallback */

        .fes-subject { word-break: break-word; }
        .fes-date { color:#6b7280; }

        /* Loader overlay */
        .fes-loader {
            position:absolute;
            inset: 0;
            display:none;
            align-items:center;
            justify-content:center;
            z-index: 20;
            background: rgba(255,255,255,.55);
            backdrop-filter: blur(1px);
            border-radius: 10px;
            min-height: 18px;
        }
        .fes-loader.show { display:flex; }
        .fes-spinner {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            border: 6px solid rgba(31,111,235,.20);
            border-top-color: rgba(31,111,235,.95);
            animation: fesSpin 0.9s linear infinite;
        }
        @keyframes fesSpin { to { transform: rotate(360deg); } }

        .fes-empty { font-size: 11px; color:#6b7280; padding:2px 0; }
    </style>

    <div class="fes-wrap"
         id="fes-wrap-<?php echo (int)$entry_id; ?>"
         data-ajax-url="<?php echo esc_attr($ajax_url); ?>"
         data-nonce="<?php echo esc_attr($nonce); ?>"
         data-entry-id="<?php echo (int)$entry_id; ?>">

        <div class="fes-error" id="fes-error-<?php echo (int)$entry_id; ?>"></div>

        <div class="fes-list" id="fes-list-<?php echo (int)$entry_id; ?>">
            <div class="fes-loader" id="fes-loader-<?php echo (int)$entry_id; ?>" aria-hidden="true">
                <div class="fes-spinner" aria-label="Loading"></div>
            </div>

            <!-- initial placeholder -->
            <div class="fes-line">
                <span class="fes-icon gray" title="Loading"></span>
                <span class="fes-subject">Loading…</span>
                <span class="fes-date">-</span>
            </div>
        </div>
    </div>

    <script>
    (function(){
        const wrap   = document.getElementById('fes-wrap-<?php echo (int)$entry_id; ?>');
        const list   = document.getElementById('fes-list-<?php echo (int)$entry_id; ?>');
        const loader = document.getElementById('fes-loader-<?php echo (int)$entry_id; ?>');
        const errorEl= document.getElementById('fes-error-<?php echo (int)$entry_id; ?>');

        const ajaxUrl = wrap.getAttribute('data-ajax-url');
        const nonce   = wrap.getAttribute('data-nonce');
        const entryId = parseInt(wrap.getAttribute('data-entry-id') || '0', 10);

        let inflight = null;

        function setLoading(on){
            loader.classList.toggle('show', !!on);
            loader.setAttribute('aria-hidden', on ? 'false' : 'true');
        }

        function setError(msg){
            errorEl.textContent = msg || '';
            errorEl.style.display = msg ? 'block' : 'none';
        }

        function escapeHtml(s){
            return String(s ?? '')
                .replace(/&/g,'&amp;')
                .replace(/</g,'&lt;')
                .replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;')
                .replace(/'/g,'&#039;');
        }

        function statusToColor(status){
            const s = String(status ?? '').trim().toLowerCase();
            if (s === 'failed') return 'red';
            if (s === 'sent') return 'blue';
            if (s === 'waiting') return 'blue';
            if (s === 'confirmed') return 'green';
            return 'gray';
        }

        function formatMMDD(dateStr){
            // Expecting something parseable by Date()
            // We format MM/DD using UTC-ish parts to avoid locale weirdness; still "good enough" for this UI.
            if (!dateStr) return '';
            const d = new Date(dateStr);
            if (isNaN(d.getTime())) return '';
            const mm = String(d.getMonth() + 1).padStart(2,'0');
            const dd = String(d.getDate()).padStart(2,'0');
            return mm + '/' + dd;
        }

        function normalizeItems(items){
            if (!Array.isArray(items)) return [];

            // Your service likely returns items with keys:
            // subject, status, date_sent
            // But if status is numeric, we keep it as-is (tooltip will show it).
            return items.map(r => ({
                subject: (r && r.subject) ? String(r.subject) : '',
                status:  (r && (r.status !== undefined && r.status !== null)) ? r.status : '',
                date_sent: (r && r.date_sent) ? String(r.date_sent) : ''
            }));
        }

        function renderLines(items){
            items = normalizeItems(items);

            if (!items.length) {
                // keep loader as first child so it overlays
                list.innerHTML = `
                    <div class="fes-loader" id="${loader.id}" aria-hidden="true">
                        <div class="fes-spinner" aria-label="Loading"></div>
                    </div>
                    <div class="fes-empty">No emails found.</div>
                `;
                return;
            }

            const html = items.map(r => {
                const subject = escapeHtml(r.subject);
                const status  = r.status;
                const statusLabel = escapeHtml(String(status ?? ''));
                const color   = statusToColor(statusLabel);
                const date    = formatMMDD(r.date_sent);

                return `
                    <div class="fes-line">
                        <span class="fes-icon ${color}" title="${statusLabel}"></span>
                        <span class="fes-subject">${subject}</span>
                        ${date ? `<span class="fes-date">- ${escapeHtml(date)}</span>` : ``}
                    </div>
                `;
            }).join('');

            list.innerHTML = `
                <div class="fes-loader" id="${loader.id}" aria-hidden="true">
                    <div class="fes-spinner" aria-label="Loading"></div>
                </div>
                ${html}
            `;
        }

        async function fetchEntryEmails(){
            setError('');

            if (inflight) inflight.abort();
            inflight = new AbortController();

            setLoading(true);

            const post = new FormData();
            post.append('action', 'frm_emails_entry_simple_storage_fetch');
            post.append('nonce', nonce);
            post.append('entry_id', String(entryId));

            try {
                const res = await fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: post,
                    signal: inflight.signal
                });

                const json = await res.json();
                if (!json || !json.success) {
                    throw new Error((json && json.data && json.data.message) ? json.data.message : 'Request failed');
                }

                const items = (json.data && json.data.items) ? json.data.items : [];
                renderLines(items);

            } catch (err) {
                if (err && err.name === 'AbortError') return;

                setError(err.message || 'AJAX error');
                list.innerHTML = `
                    <div class="fes-loader" id="${loader.id}" aria-hidden="true">
                        <div class="fes-spinner" aria-label="Loading"></div>
                    </div>
                    <div class="fes-empty">Unable to load emails for this entry.</div>
                `;
            } finally {
                const latestLoader = document.getElementById(loader.id);
                if (latestLoader) {
                    latestLoader.classList.remove('show');
                    latestLoader.setAttribute('aria-hidden','true');
                }
            }
        }

        fetchEntryEmails();
    })();
    </script>
    <?php

    return ob_get_clean();
}

function frm_emails_entry_simple_storage_fetch_ajax() {

    if ( ! class_exists('FrmEmailLogService') ) {
        wp_send_json_error(['message' => 'Storage plugin not active.'], 400);
    }

    $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
    if ( ! wp_verify_nonce($nonce, 'frm_emails_entry_simple_storage_fetch') ) {
        wp_send_json_error(['message' => 'Invalid nonce.'], 403);
    }

    $entry_raw = isset($_POST['entry_id']) ? trim((string) wp_unslash($_POST['entry_id'])) : '';
    $entry_id  = (ctype_digit($entry_raw) && (int)$entry_raw > 0) ? (int)$entry_raw : 0;

    if ( $entry_id <= 0 ) {
        wp_send_json_error(['message' => 'Invalid entry id.'], 400);
    }

    $service = new FrmEmailLogService();

    // Your service method
    $result = $service->getEmailLogsByEntry( $entry_id );

    if ( ! is_array($result) || empty($result['success']) ) {
        $msg = is_array($result) && ! empty($result['message']) ? (string)$result['message'] : 'Service request failed.';
        wp_send_json_error(['message' => $msg], 500);
    }

    $items = $result['data']['items'] ?? [];

    wp_send_json_success([
        'items' => $items,
    ]);
}
