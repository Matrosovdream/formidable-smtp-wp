<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shortcode: [frm-emails-list-storage]
 * AJAX endpoint: admin-ajax.php?action=frm_emails_list_storage_fetch
 */

add_action('init', function () {
    add_shortcode('frm-emails-list-storage', 'frm_emails_list_storage_shortcode');
});

/** AJAX handlers */
add_action('wp_ajax_frm_emails_list_storage_fetch', 'frm_emails_list_storage_fetch_ajax');
add_action('wp_ajax_nopriv_frm_emails_list_storage_fetch', 'frm_emails_list_storage_fetch_ajax');

function frm_emails_list_storage_shortcode($atts = []) {
    if ( ! class_exists('FrmEmailLogService') ) {
        return '<div style="color:#b00">Storage plugin not active.</div>';
    }

    $q = wp_unslash($_GET);

    // Read raw values for UI
    $entry_id_raw = array_key_exists('entry_id', $q) ? trim((string)$q['entry_id']) : '';
    $subject      = isset($q['subject'])     ? trim((string)$q['subject']) : '';
    $email_from   = isset($q['email_from'])  ? trim((string)$q['email_from']) : '';
    $email_to     = isset($q['email_to'])    ? trim((string)$q['email_to']) : '';
    // US format in UI: MM/DD/YYYY (kept as string; we parse it server-side)
    $date_from    = isset($q['date_from'])   ? trim((string)$q['date_from']) : '';
    $date_to      = isset($q['date_to'])     ? trim((string)$q['date_to']) : '';

    // UI state (default)
    $page_num   = isset($q['fel_page'])     ? max(1, (int)$q['fel_page']) : 1;
    $per_page   = isset($q['fel_per_page']) ? max(1, (int)$q['fel_per_page']) : 25;

    // Sorting: we will send ["id" => "desc"] by default
    $order      = isset($q['fel_order']) ? strtolower(trim((string)$q['fel_order'])) : 'desc';
    $order      = in_array($order, ['asc','desc'], true) ? $order : 'desc';

    $ajax_url = admin_url('admin-ajax.php');
    $nonce    = wp_create_nonce('frm_emails_list_storage_fetch');

    ob_start();
    ?>
    <style>
        .frm-emails-wrap { font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
        .frm-emails-filters { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin-bottom:12px; }
        .frm-emails-filters .field { display:flex; flex-direction:column; }
        .frm-emails-filters input[type="text"], .frm-emails-filters input[type="number"], .frm-emails-filters input[type="date"], .frm-emails-filters select {
            padding:6px 8px; border:1px solid #d0d7de; border-radius:6px; min-width:160px;
        }
        .frm-emails-filters .submit-btn { padding:8px 12px; border:1px solid #1f6feb; background:#1f6feb; color:#fff; border-radius:6px; cursor:pointer; }
        .frm-emails-filters .reset-btn { padding:8px 12px; border:1px solid #d0d7de; background:#fff; color:#24292f; border-radius:6px; cursor:pointer; text-decoration:none; }

        .frm-emails-results { position: relative; }

        .frm-emails-table { width:100%; border-collapse: collapse; }
        .frm-emails-table th, .frm-emails-table td { border-bottom:1px solid #eaeef2; padding:8px 10px; vertical-align: top; }
        .frm-emails-table th { text-align:left; background:#f6f8fa; }
        .frm-emails-table .view-btn { padding:6px 10px; border:1px solid #1f6feb; background:#1f6feb; color:#fff; border-radius:6px; cursor:pointer; text-decoration:none; display:inline-block; }
        .frm-emails-pager { display:flex; gap:8px; align-items:center; justify-content:space-between; margin-top:12px; }
        .frm-emails-pager a, .frm-emails-pager button, .frm-emails-pager span {
            padding:6px 10px; border:1px solid #d0d7de; border-radius:6px; text-decoration:none; color:#24292f; background:#fff;
        }
        .frm-emails-pager button { cursor:pointer; }
        .frm-emails-pager .active { background:#1f6feb; color:#fff; border-color:#1f6feb; }
        .frm-emails-pager .disabled { opacity:.45; pointer-events:none; }

        .frm-emails-error { color:#b00; padding:8px 0; display:none; }

        /* BIG centered transparent loader over table */
        .frm-emails-loader {
            position:absolute;
            inset: 0;
            display:none;
            align-items:center;
            justify-content:center;
            z-index: 20;
            background: rgba(255,255,255,.55);
            backdrop-filter: blur(1px);
        }
        .frm-emails-loader.show { display:flex; }

        .frm-emails-spinner {
            width: 86px;
            height: 86px;
            border-radius: 50%;
            border: 10px solid rgba(31,111,235,.20);
            border-top-color: rgba(31,111,235,.95);
            animation: frmSpin 0.9s linear infinite;
        }
        @keyframes frmSpin { to { transform: rotate(360deg); } }

        /* Modal */
        #frm-emails-modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; opacity:0; z-index:2147483647; align-items:flex-start; justify-content:center; padding:6vh 12px; }
        #frm-emails-modal-overlay.show { display:flex; opacity:1; }
        #frm-emails-modal { background:#fff; width:min(900px, 96vw); max-height:88vh; overflow:auto; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.2); }
        #frm-emails-modal header { padding:12px 16px; border-bottom:1px solid #eaeef2; display:flex; justify-content:space-between; align-items:center; }
        #frm-emails-modal .title { font-weight:600; }
        #frm-emails-modal .close { border:none; background:transparent; font-size:22px; line-height:1; cursor:pointer; }
        #frm-emails-modal .body { padding:16px; }
        #frm-emails-modal .meta { margin-bottom:10px; color:#57606a; font-size:13px; }
        #frm-emails-modal .content { border:1px solid #eaeef2; border-radius:8px; padding:12px; }
        #frm-emails-modal .content pre { white-space:pre-wrap; }

        /* Date inputs look like text when using jQuery UI datepicker */
        .frm-emails-filters input.frm-date { min-width: 140px; }

        /* Date column fixed width */
        .frm-emails-table th.col-date,
        .frm-emails-table td.col-date{
            width:155px;
            min-width:155px;
            max-width:155px;
            white-space:nowrap;
        }
    </style>

    <div class="frm-emails-wrap" id="frm-emails-wrap"
         data-ajax-url="<?php echo esc_attr($ajax_url); ?>"
         data-nonce="<?php echo esc_attr($nonce); ?>">
        <form method="get" class="frm-emails-filters" id="frm-emails-filters" action="<?php echo esc_url( get_permalink() ); ?>">
            <div class="field">
                <label for="fe-entry">Entry ID</label>
                <input id="fe-entry" type="number" name="entry_id" value="<?php echo esc_attr($entry_id_raw); ?>" placeholder="e.g. 14485">
            </div>
            <div class="field">
                <label for="fe-subject">Subject</label>
                <input id="fe-subject" type="text" name="subject" value="<?php echo esc_attr($subject); ?>" placeholder="contains...">
            </div>
            <div class="field">
                <label for="fe-from">Email From</label>
                <input id="fe-from" type="text" name="email_from" value="<?php echo esc_attr($email_from); ?>" placeholder="contains...">
            </div>
            <div class="field">
                <label for="fe-to">Email To</label>
                <input id="fe-to" type="text" name="email_to" value="<?php echo esc_attr($email_to); ?>" placeholder="contains...">
            </div>
            <div class="field">
                <label for="fe-date-from">Date From</label>
                <!-- US date format via jQuery UI datepicker -->
                <input id="fe-date-from" type="text" class="frm-date" name="date_from" value="<?php echo esc_attr($date_from); ?>" placeholder="MM/DD/YYYY" autocomplete="off">
            </div>
            <div class="field">
                <label for="fe-date-to">Date To</label>
                <input id="fe-date-to" type="text" class="frm-date" name="date_to" value="<?php echo esc_attr($date_to); ?>" placeholder="MM/DD/YYYY" autocomplete="off">
            </div>

            <input type="hidden" name="fel_per_page" value="<?php echo esc_attr($per_page); ?>">
            <input type="hidden" name="fel_page" value="<?php echo esc_attr($page_num); ?>">
            <input type="hidden" name="fel_sort_key" value="id">
            <input type="hidden" name="fel_sort_dir" value="<?php echo esc_attr($order); ?>">

            <div class="field">
                <label>&nbsp;</label>
                <button class="submit-btn" type="submit">Filter</button>
            </div>
            <div class="field">
                <label>&nbsp;</label>
                <!-- AJAX reset (still has href as fallback) -->
                <a class="reset-btn" id="frm-emails-reset" href="<?php echo esc_url( get_permalink() ); ?>">Reset</a>
            </div>
            <div class="field">
                <label for="fe-per-page">Rows</label>
                <select id="fe-per-page" name="fel_per_page_select">
                    <?php foreach ([10,25,50,100] as $opt): ?>
                        <option value="<?php echo (int)$opt; ?>" <?php selected($per_page, $opt); ?>><?php echo (int)$opt; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <div class="frm-emails-error" id="frm-emails-error"></div>

        <div id="frm-emails-results" class="frm-emails-results">
            <!-- loader overlay (CENTERED ON TABLE AREA) -->
            <div class="frm-emails-loader" id="frm-emails-loader" aria-hidden="true">
                <div class="frm-emails-spinner" aria-label="Loading"></div>
            </div>

            <table class="frm-emails-table">
                <thead>
                <tr>
                    <th><a href="#" id="frm-emails-sort-entry">Entry ID <span id="frm-emails-sort-arrow"><?php echo $order === 'asc' ? '▲' : '▼'; ?></span></a></th>
                    <th>Subject</th>
                    <th>Content</th>
                    <th>Email From</th>
                    <th>Email To</th>
                    <th>Status</th>
                    <th>Opened</th>
                    <th class="col-date">Date</th>
                </tr>
                </thead>
                <tbody id="frm-emails-tbody">
                    <tr><td colspan="8" style="color:#57606a;">Loading…</td></tr>
                </tbody>
            </table>

            <div id="frm-emails-pager"></div>
        </div>
    </div>

    <!-- Modal -->
    <div id="frm-emails-modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
        <div id="frm-emails-modal">
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
        const wrap     = document.getElementById('frm-emails-wrap');
        const form     = document.getElementById('frm-emails-filters');
        const loader   = document.getElementById('frm-emails-loader');
        const errorEl  = document.getElementById('frm-emails-error');
        const tbody    = document.getElementById('frm-emails-tbody');
        const pagerBox = document.getElementById('frm-emails-pager');

        const resetBtn = document.getElementById('frm-emails-reset');

        const ajaxUrl  = wrap.getAttribute('data-ajax-url');
        const nonce    = wrap.getAttribute('data-nonce');

        const overlay  = document.getElementById('frm-emails-modal-overlay');
        const modal    = document.getElementById('frm-emails-modal');
        const bodyEl   = modal.querySelector('.content');
        const metaEl   = modal.querySelector('.meta');

        let inflight = null; // AbortController

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

        function setLoading(on){
            if (!loader) return;
            loader.classList.toggle('show', !!on);
            loader.setAttribute('aria-hidden', on ? 'false' : 'true');
        }
        function setError(msg){
            errorEl.textContent = msg || '';
            errorEl.style.display = msg ? 'block' : 'none';
        }

        function readState(){
            const fd = new FormData(form);

            // per-page select -> hidden fel_per_page
            const perSel = form.querySelector('[name="fel_per_page_select"]');
            if (perSel) fd.set('fel_per_page', perSel.value);

            const entry_id   = (fd.get('entry_id') || '').toString().trim();
            const subject    = (fd.get('subject') || '').toString().trim();
            const email_from = (fd.get('email_from') || '').toString().trim();
            const email_to   = (fd.get('email_to') || '').toString().trim();
            const date_from  = (fd.get('date_from') || '').toString().trim();
            const date_to    = (fd.get('date_to') || '').toString().trim();

            const per_page   = Math.max(1, parseInt(fd.get('fel_per_page') || '25', 10));
            const page_num   = Math.max(1, parseInt(fd.get('fel_page') || '1', 10));

            const sort_key   = (fd.get('fel_sort_key') || 'id').toString();
            const sort_dir   = (fd.get('fel_sort_dir') || 'desc').toString().toLowerCase() === 'asc' ? 'asc' : 'desc';

            return {
                filters: { entry_id, subject, email_from, email_to, date_from, date_to },
                paginate: per_page,
                page_num: page_num,
                sorting: { [sort_key]: sort_dir },
                _ui: { per_page, page_num, sort_key, sort_dir }
            };
        }

        function writePage(n){
            form.querySelector('[name="fel_page"]').value = String(Math.max(1, parseInt(n,10) || 1));
        }
        function resetPage(){
            writePage(1);
        }
        function setSort(sortKey, sortDir){
            form.querySelector('[name="fel_sort_key"]').value = sortKey;
            form.querySelector('[name="fel_sort_dir"]').value = sortDir;
            const arrow = document.getElementById('frm-emails-sort-arrow');
            if (arrow) arrow.textContent = (sortDir === 'asc') ? '▲' : '▼';
        }

        function clearFiltersToDefault(){
            // text/number inputs
            form.querySelector('[name="entry_id"]').value   = '';
            form.querySelector('[name="subject"]').value    = '';
            form.querySelector('[name="email_from"]').value = '';
            form.querySelector('[name="email_to"]').value   = '';
            form.querySelector('[name="date_from"]').value  = '';
            form.querySelector('[name="date_to"]').value    = '';

            // reset paging/sort
            resetPage();
            setSort('id', 'desc');

            // reset rows select to 25 (or first option if missing)
            const perSel = form.querySelector('[name="fel_per_page_select"]');
            if (perSel) {
                perSel.value = '25';
            }
        }

        async function fetchResults(){
            setError('');

            // abort previous request (if any)
            if (inflight) inflight.abort();
            inflight = new AbortController();

            setLoading(true);

            const state = readState();
            const payload = {
                filters: state.filters,
                paginate: state.paginate,
                page_num: state.page_num,
                sorting: state.sorting
            };

            const post = new FormData();
            post.append('action', 'frm_emails_list_storage_fetch');
            post.append('nonce', nonce);
            post.append('payload', JSON.stringify(payload));

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

                tbody.innerHTML = json.data.tbody_html || '<tr><td colspan="8" style="color:#57606a;">No results.</td></tr>';
                pagerBox.innerHTML = json.data.pager_html || '';

            } catch (err) {
                if (err && err.name === 'AbortError') return;
                setError(err.message || 'AJAX error');
                tbody.innerHTML = '<tr><td colspan="8" style="color:#57606a;">No results.</td></tr>';
                pagerBox.innerHTML = '';
            } finally {
                setLoading(false);
            }
        }

        // Submit filter -> reset page_num to 1
        form.addEventListener('submit', function(e){
            e.preventDefault();
            resetPage(); // IMPORTANT requirement
            fetchResults();
        });

        // AJAX reset button (still has href fallback)
        if (resetBtn) {
            resetBtn.addEventListener('click', function(e){
                e.preventDefault();
                clearFiltersToDefault();
                fetchResults();
            });
        }

        // Per-page change -> reset page to 1 and fetch
        const perSel = form.querySelector('[name="fel_per_page_select"]');
        if (perSel) {
            perSel.addEventListener('change', function(){
                resetPage(); // IMPORTANT requirement
                fetchResults();
            });
        }

        // Sort click
        const sortLink = document.getElementById('frm-emails-sort-entry');
        if (sortLink) {
            sortLink.addEventListener('click', function(e){
                e.preventDefault();
                const fd = new FormData(form);
                const current = (fd.get('fel_sort_dir') || 'desc').toString().toLowerCase();
                const nextDir = (current === 'asc') ? 'desc' : 'asc';
                setSort('id', nextDir);
                resetPage(); // IMPORTANT requirement
                fetchResults();
            });
        }

        // Pager clicks
        pagerBox.addEventListener('click', function(e){
            const btn = e.target.closest('[data-page]');
            if (!btn) return;
            e.preventDefault();
            const p = btn.getAttribute('data-page');
            writePage(p);
            fetchResults();
        });

        // Modal: delegate click on view buttons
        document.addEventListener('click', function(e){
            const btn = e.target.closest('a.view-btn');
            if (!btn) return;
            e.preventDefault();
            const title = btn.getAttribute('data-title') || '';
            const tplId = btn.getAttribute('data-tpl');
            const tpl = document.getElementById(tplId);
            if (tpl) openModal(title, tpl.innerHTML);
        });

        // Init US datepickers if jQuery UI is available (no extra enqueue needed if theme already has it)
        function initDatepickers(){
            if (!window.jQuery) return;
            const $ = window.jQuery;
            if (!$.fn || !$.fn.datepicker) return;

            $('.frm-date').datepicker({
                dateFormat: 'mm/dd/yy',
                changeMonth: true,
                changeYear: true
            });
        }
        initDatepickers();

        // Initial load
        fetchResults();
    })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * AJAX endpoint: receives payload JSON and calls storage service.
 */
function frm_emails_list_storage_fetch_ajax() {
    if ( ! class_exists('FrmEmailLogService') ) {
        wp_send_json_error(['message' => 'Storage plugin not active.'], 400);
    }

    $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
    if ( ! wp_verify_nonce($nonce, 'frm_emails_list_storage_fetch') ) {
        wp_send_json_error(['message' => 'Invalid nonce.'], 403);
    }

    $raw  = isset($_POST['payload']) ? (string) wp_unslash($_POST['payload']) : '';
    $data = json_decode($raw, true);
    if ( ! is_array($data) ) {
        wp_send_json_error(['message' => 'Invalid payload JSON.'], 400);
    }

    // Sanitize payload
    $filters  = isset($data['filters']) && is_array($data['filters']) ? $data['filters'] : [];
    $paginate = isset($data['paginate']) ? max(1, (int)$data['paginate']) : 25;
    $page_num = isset($data['page_num']) ? max(1, (int)$data['page_num']) : 1;

    $sorting = isset($data['sorting']) && is_array($data['sorting']) ? $data['sorting'] : ['id' => 'desc'];
    $sort_key = 'id';
    $sort_dir = 'desc';
    foreach ($sorting as $k => $v) {
        $sort_key = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$k);
        $sort_dir = strtolower((string)$v) === 'asc' ? 'asc' : 'desc';
        break;
    }

    $entry_id = isset($filters['entry_id']) ? trim((string)$filters['entry_id']) : '';
    $entry_id = ($entry_id !== '' && ctype_digit($entry_id) && (int)$entry_id > 0) ? (string)(int)$entry_id : '';

    // Parse US date MM/DD/YYYY (or accept YYYY-MM-DD) and send as-is to service
    $date_from = frm_emails_storage_normalize_date_in($filters['date_from'] ?? '');
    $date_to   = frm_emails_storage_normalize_date_in($filters['date_to'] ?? '');

    $payload = [
        'filters' => [
            'entry_id'    => $entry_id,
            'subject'     => isset($filters['subject']) ? trim((string)$filters['subject']) : '',
            'email_from'  => isset($filters['email_from']) ? trim((string)$filters['email_from']) : '',
            'email_to'    => isset($filters['email_to']) ? trim((string)$filters['email_to']) : '',
            'date_from'   => $date_from,
            'date_to'     => $date_to,
        ],
        'paginate' => $paginate,
        'page_num' => $page_num,
        'sorting'  => [
            $sort_key => $sort_dir,
        ],
    ];

    $service = new FrmEmailLogService();
    $result  = $service->getEmailLogsAll($payload);

    if ( ! is_array($result) || empty($result['success']) ) {
        $msg = is_array($result) && ! empty($result['message']) ? (string)$result['message'] : 'Service request failed.';
        wp_send_json_error(['message' => $msg], 500);
    }

    $items      = $result['data']['items'] ?? [];
    $pagination = $result['data']['pagination'] ?? [];

    $tbody_html = frm_emails_storage_render_tbody($items);
    $pager_html = frm_emails_storage_render_pager($pagination);

    wp_send_json_success([
        'tbody_html' => $tbody_html,
        'pager_html' => $pager_html,
    ]);
}

/**
 * Accepts:
 *  - "MM/DD/YYYY"
 *  - "MM/DD/YY"
 *  - "YYYY-MM-DD"
 * Returns:
 *  - "YYYY-MM-DD" (normalized) or "" if empty
 */
function frm_emails_storage_normalize_date_in($val): string {
    $val = trim((string)$val);
    if ($val === '') return '';

    // Already ISO
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        return $val;
    }

    // US mm/dd/yyyy or mm/dd/yy
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2}|\d{4})$/', $val, $m)) {
        $mm = (int)$m[1];
        $dd = (int)$m[2];
        $yy = (int)$m[3];
        if ($yy < 100) { $yy += 2000; } // simple pivot
        if ($mm >= 1 && $mm <= 12 && $dd >= 1 && $dd <= 31 && $yy >= 1970 && $yy <= 2100) {
            return sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
        }
    }

    // Fallback: try strtotime
    $ts = strtotime($val);
    return $ts ? gmdate('Y-m-d', $ts) : $val;
}

/** Render tbody rows using service items */
function frm_emails_storage_render_tbody(array $rows): string {
    if (empty($rows)) {
        return '<tr><td colspan="8" style="color:#57606a;">No results.</td></tr>';
    }

    ob_start();
    foreach ($rows as $r) {
        $rid      = (int)($r['id'] ?? 0);
        $entryVal = isset($r['entry_id']) ? (int)$r['entry_id'] : 0;
        $subj     = (string)($r['subject'] ?? '');
        $from     = (string)($r['email_from'] ?? '');
        $to       = (string)($r['email_to'] ?? '');
        $status   = $r['status'] ?? '';
        $opened   = ! empty($r['is_opened']) ? 'Yes' : 'No';
        $dateRaw  = (string)($r['date_sent'] ?? '');
        $dateFmt  = $dateRaw ? date_i18n('Y-m-d H:i', strtotime($dateRaw)) : '';

        $html  = (string)($r['content_html'] ?? '');
        $plain = (string)($r['content_plain'] ?? '');

        $content = ($html !== '')
            ? wp_kses_post($html)
            : '<pre>' . esc_html($plain) . '</pre>';

        $tpl_id = 'frm-emails-content-' . $rid;
        ?>
        <tr>
            <td><?php echo $entryVal ? (int)$entryVal : ''; ?></td>
            <td><?php echo esc_html($subj); ?></td>
            <td>
                <a href="#" class="view-btn" data-tpl="<?php echo esc_attr($tpl_id); ?>" data-title="<?php echo esc_attr($subj); ?>">View</a>
                <div id="<?php echo esc_attr($tpl_id); ?>" style="display:none"><?php echo $content; ?></div>
            </td>
            <td><?php echo esc_html($from); ?></td>
            <td style="max-width:320px; word-break:break-word;"><?php echo esc_html($to); ?></td>
            <td>
                <?php echo do_shortcode('[frm-email-status status="' . esc_attr($status) . '"]'); ?>
            </td>
            <td><?php echo esc_html($opened); ?></td>
            <td class="col-date"><?php echo esc_html($dateFmt); ?></td>
        </tr>
        <?php
    }
    return ob_get_clean();
}

/** Render pager using service pagination */
function frm_emails_storage_render_pager(array $p): string {
    $current = isset($p['current_page']) ? max(1, (int)$p['current_page']) : 1;
    $last    = isset($p['last_page']) ? max(1, (int)$p['last_page']) : 1;
    $total   = isset($p['total_items']) ? (int)$p['total_items'] : 0;

    $prev = max(1, $current - 1);
    $next = min($last, $current + 1);

    $window = 3;
    $start  = max(1, $current - $window);
    $end    = min($last, $current + $window);

    ob_start();
    ?>
    <div class="frm-emails-pager">
        <div class="pages">
            <button type="button" class="<?php echo $current <= 1 ? 'disabled' : ''; ?>" data-page="<?php echo (int)$prev; ?>">Prev</button>

            <?php
            if ($start > 1) {
                echo '<button type="button" data-page="1">1</button>';
                if ($start > 2) echo '<span>…</span>';
            }

            for ($i = $start; $i <= $end; $i++) {
                $cls = ($i === $current) ? 'active' : '';
                echo '<button type="button" class="' . esc_attr($cls) . '" data-page="' . (int)$i . '">' . (int)$i . '</button>';
            }

            if ($end < $last) {
                if ($end < $last - 1) echo '<span>…</span>';
                echo '<button type="button" data-page="' . (int)$last . '">' . (int)$last . '</button>';
            }
            ?>

            <button type="button" class="<?php echo $current >= $last ? 'disabled' : ''; ?>" data-page="<?php echo (int)$next; ?>">Next</button>
        </div>

        <div>
            <span style="color:#57606a;">Showing page <?php echo (int)$current; ?> of <?php echo (int)$last; ?> (<?php echo (int)$total; ?> total)</span>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
