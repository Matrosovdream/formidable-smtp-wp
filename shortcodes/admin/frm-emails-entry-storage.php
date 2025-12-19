<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shortcode: [frm-emails-entry-storage entry="12345"]
 * - AJAX loads emails for entry via FrmEmailLogService::getEmailLogsByEntry($entry_id)
 * - Responsive cards
 * - Loader overlay (same style idea as list)
 */

add_action('init', function () {
    add_shortcode('frm-emails-entry-storage', 'frm_emails_entry_storage_shortcode');
});

/** AJAX handlers */
add_action('wp_ajax_frm_emails_entry_storage_fetch', 'frm_emails_entry_storage_fetch_ajax');
add_action('wp_ajax_nopriv_frm_emails_entry_storage_fetch', 'frm_emails_entry_storage_fetch_ajax');

function frm_emails_entry_storage_shortcode($atts = []) {
    if ( ! class_exists('FrmEmailLogService') ) {
        return '<div style="color:#b00">Storage plugin not active.</div>';
    }

    $atts = shortcode_atts(['entry' => ''], $atts, 'frm-emails-entry-storage');
    $entry_raw = trim((string)$atts['entry']);
    $entry_id  = (ctype_digit($entry_raw) && (int)$entry_raw > 0) ? (int)$entry_raw : 0;

    if ( $entry_id <= 0 ) {
        return '<div style="color:#b00">Please provide a valid entry id, e.g. <code>[frm-emails-entry-storage entry="14485"]</code>.</div>';
    }

    $ajax_url = admin_url('admin-ajax.php');
    $nonce    = wp_create_nonce('frm_emails_entry_storage_fetch');

    ob_start();
    ?>
    <style>
        .fel-wrap { font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
        .fel-grid { position:relative; display:grid; gap:14px; grid-template-columns:repeat(auto-fit, minmax(260px,1fr)); }
        .fel-card { border:1px solid #e5e7eb; border-radius:12px; background:#fff; box-shadow:0 3px 12px rgba(0,0,0,.04); overflow:hidden; display:flex; flex-direction:column; }
        .fel-card header { padding:12px 14px; border-bottom:1px solid #f0f2f5; background:#f9fafb; }
        .fel-card header .subject { font-weight:600; line-height:1.35; color:#111827; word-break:break-word; }
        .fel-card .body { padding:12px 14px; display:flex; flex-direction:column; gap:10px; }
        .fel-meta { display:grid; gap:6px; font-size:13px; color:#4b5563; }
        .fel-row { display:flex; align-items:baseline; gap:8px; }
        .fel-label { min-width:70px; color:#6b7280; }
        .fel-actions { margin-top:6px; display:flex; gap:8px; }
        .fel-btn { display:inline-block; padding:8px 12px; border-radius:8px; border:1px solid #1f6feb; background:#1f6feb; color:#fff; text-decoration:none; cursor:pointer; }

        .fel-error { color:#b00; padding:8px 0; display:none; }

        /* Loader overlay (same style idea as list) */
        .fel-loader {
            position:absolute;
            inset: 0;
            display:none;
            align-items:center;
            justify-content:center;
            z-index: 20;
            background: rgba(255,255,255,.55);
            backdrop-filter: blur(1px);
            border-radius: 12px;
        }
        .fel-loader.show { display:flex; }
        .fel-spinner {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 10px solid rgba(31,111,235,.20);
            border-top-color: rgba(31,111,235,.95);
            animation: felSpin 0.9s linear infinite;
        }
        @keyframes felSpin { to { transform: rotate(360deg); } }

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

    <div class="fel-wrap"
         id="fel-wrap-<?php echo (int)$entry_id; ?>"
         data-ajax-url="<?php echo esc_attr($ajax_url); ?>"
         data-nonce="<?php echo esc_attr($nonce); ?>"
         data-entry-id="<?php echo (int)$entry_id; ?>">

        <div class="fel-error" id="fel-error-<?php echo (int)$entry_id; ?>"></div>

        <div class="fel-grid" id="fel-grid-<?php echo (int)$entry_id; ?>">
            <div class="fel-loader" id="fel-loader-<?php echo (int)$entry_id; ?>" aria-hidden="true">
                <div class="fel-spinner" aria-label="Loading"></div>
            </div>

            <!-- initial placeholder -->
            <div class="fel-card" style="grid-column:1/-1;">
                <header><div class="subject">Loading…</div></header>
                <div class="body"><div class="fel-meta">Fetching emails for Entry #<?php echo (int)$entry_id; ?>…</div></div>
            </div>
        </div>
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
        const wrap   = document.getElementById('fel-wrap-<?php echo (int)$entry_id; ?>');
        const grid   = document.getElementById('fel-grid-<?php echo (int)$entry_id; ?>');
        const loader = document.getElementById('fel-loader-<?php echo (int)$entry_id; ?>');
        const errorEl= document.getElementById('fel-error-<?php echo (int)$entry_id; ?>');

        const ajaxUrl = wrap.getAttribute('data-ajax-url');
        const nonce   = wrap.getAttribute('data-nonce');
        const entryId = parseInt(wrap.getAttribute('data-entry-id') || '0', 10);

        const overlay = document.getElementById('fel-modal-overlay');
        const modal   = document.getElementById('fel-modal');
        const bodyEl  = modal.querySelector('.content');
        const metaEl  = modal.querySelector('.meta');

        let inflight = null;

        function setLoading(on){
            loader.classList.toggle('show', !!on);
            loader.setAttribute('aria-hidden', on ? 'false' : 'true');
        }
        function setError(msg){
            errorEl.textContent = msg || '';
            errorEl.style.display = msg ? 'block' : 'none';
        }

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

        function escapeHtml(s){
            return String(s ?? '')
                .replace(/&/g,'&amp;')
                .replace(/</g,'&lt;')
                .replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;')
                .replace(/'/g,'&#039;');
        }

        function renderCards(items){
            if (!Array.isArray(items) || !items.length) {
                grid.innerHTML = `
                    <div class="fel-loader ${loader.classList.contains('show') ? 'show' : ''}" id="${loader.id}" aria-hidden="true">
                        <div class="fel-spinner" aria-label="Loading"></div>
                    </div>
                    <div class="fel-card" style="grid-column:1/-1;">
                        <header><div class="subject">No emails found</div></header>
                        <div class="body"><div class="fel-meta">There are no emails for this entry.</div></div>
                    </div>
                `;
                // restore loader node reference after innerHTML rewrite
                return;
            }

            const cards = items.map((r, idx) => {
                const rid     = parseInt(r.id || 0, 10);
                const subject = String(r.subject || '');
                const from    = String(r.email_from || '');
                const to      = String(r.email_to || '');
                const status  = r.status;
                const opened  = (r.is_opened ? 'Yes' : 'No');
                const dateFmt = String(r.date_sent || '');

                // Content: prefer html else plain pre
                let contentHtml = '';
                if (r.content_html) {
                    contentHtml = String(r.content_html);
                } else {
                    contentHtml = '<pre>' + escapeHtml(r.content_plain || '') + '</pre>';
                }

                const tplId = `fel-content-${entryId}-${rid}-${idx}`;

                return `
                <div class="fel-card">
                    <header>
                        <div class="subject">${escapeHtml(subject)}</div>
                    </header>
                    <div class="body">
                        <div class="fel-meta">
                            <div class="fel-row"><div class="fel-label">From</div><div>${escapeHtml(from)}</div></div>
                            <div class="fel-row"><div class="fel-label">To</div><div style="word-break:break-word;">${escapeHtml(to)}</div></div>
                            <div class="fel-row"><div class="fel-label">Status</div><div>${escapeHtml(String(status ?? ''))}</div></div>
                            <div class="fel-row"><div class="fel-label">Date</div><div>${escapeHtml(dateFmt)}</div></div>
                            <div class="fel-row"><div class="fel-label">Opened</div><div>${escapeHtml(opened)}</div></div>
                        </div>
                        <div class="fel-actions">
                            <a href="#" class="fel-btn fel-view" data-tpl="${tplId}" data-title="${escapeHtml(subject)}">View content</a>
                        </div>
                        <div id="${tplId}" style="display:none">${contentHtml}</div>
                    </div>
                </div>
                `;
            }).join('');

            // keep loader overlay as first child so it covers the grid
            grid.innerHTML = `
                <div class="fel-loader" id="${loader.id}" aria-hidden="true">
                    <div class="fel-spinner" aria-label="Loading"></div>
                </div>
                ${cards}
            `;
        }

        async function fetchEntryEmails(){
            setError('');

            if (inflight) inflight.abort();
            inflight = new AbortController();

            setLoading(true);

            const post = new FormData();
            post.append('action', 'frm_emails_entry_storage_fetch');
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

                // json.data.result is the service response (same shape as getEmailLogsAll)
                const items = (json.data && json.data.items) ? json.data.items : [];
                renderCards(items);

                // Re-bind loader reference (it got replaced by innerHTML)
                const newLoader = document.getElementById(loader.id);
                if (newLoader) {
                    // swap DOM reference
                    loader.replaceWith(newLoader);
                }
            } catch (err) {
                if (err && err.name === 'AbortError') return;
                setError(err.message || 'AJAX error');
                grid.innerHTML = `
                    <div class="fel-loader" id="${loader.id}" aria-hidden="true">
                        <div class="fel-spinner" aria-label="Loading"></div>
                    </div>
                    <div class="fel-card" style="grid-column:1/-1;">
                        <header><div class="subject">No emails found</div></header>
                        <div class="body"><div class="fel-meta">Unable to load emails for this entry.</div></div>
                    </div>
                `;
            } finally {
                // loader node might have been replaced
                const latestLoader = document.getElementById(loader.id);
                if (latestLoader) {
                    latestLoader.classList.remove('show');
                    latestLoader.setAttribute('aria-hidden','true');
                }
            }
        }

        // Modal click handler
        document.addEventListener('click', function(e){
            const btn = e.target.closest('a.fel-view');
            if (!btn) return;
            e.preventDefault();
            const tplId = btn.getAttribute('data-tpl');
            const title = btn.getAttribute('data-title') || '';
            const tpl   = document.getElementById(tplId);
            if (tpl) openModal(title, tpl.innerHTML);
        });

        // Initial load
        fetchEntryEmails();
    })();
    </script>
    <?php
    return ob_get_clean();
}

function frm_emails_entry_storage_fetch_ajax() {
    if ( ! class_exists('FrmEmailLogService') ) {
        wp_send_json_error(['message' => 'Storage plugin not active.'], 400);
    }

    $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
    if ( ! wp_verify_nonce($nonce, 'frm_emails_entry_storage_fetch') ) {
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

    // NOTE:
    // - For cards we can return raw items; JS renders them.
    // - If you prefer PHP rendering, tell me and I’ll switch to returning HTML.
    wp_send_json_success([
        'items' => $items,
    ]);
}
