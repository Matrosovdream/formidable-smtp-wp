<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shortcode: [frm-emails-list]
 */
add_action('init', function () {
    add_shortcode('frm-emails-list', 'frm_emails_list_shortcode');
});

function frm_emails_list_shortcode($atts = []) {
    if ( ! class_exists('FrmSmtpEmailModel') ) {
        return '<div style="color:#b00">FrmSmtpEmailModel not found.</div>';
    }

    global $wpdb;

    $q = wp_unslash($_GET);

    // --- IMPORTANT: read raw, then validate ---
    $entry_id_raw = array_key_exists('entry_id', $q) ? trim((string)$q['entry_id']) : '';
    $entry_id     = ($entry_id_raw !== '' && ctype_digit($entry_id_raw) && (int)$entry_id_raw > 0) ? (int)$entry_id_raw : null;

    $subject     = isset($q['subject'])     ? trim((string)$q['subject']) : '';
    $email_from  = isset($q['email_from'])  ? trim((string)$q['email_from']) : '';
    $email_to    = isset($q['email_to'])    ? trim((string)$q['email_to']) : '';
    $date_from   = isset($q['date_from'])   ? trim((string)$q['date_from']) : '';
    $date_to     = isset($q['date_to'])     ? trim((string)$q['date_to']) : '';

    // paging/sort params (prefixed to avoid WP's 'page' canonical)
    $page      = isset($q['fel_page'])     ? max(1, (int)$q['fel_page']) : 1;
    $per_page  = isset($q['fel_per_page']) ? max(1, (int)$q['fel_per_page']) : 25;
    $order     = isset($q['fel_order'])    ? strtoupper((string)$q['fel_order']) : 'DESC';
    $order     = in_array($order, ['ASC','DESC'], true) ? $order : 'DESC';

    // Build filters
    $filter = [];
    if ($entry_id !== null) { $filter['entry_id'] = $entry_id; }              // only positive ints
    if ($subject !== '')    { $filter['subject'] = $subject; }
    if ($email_from !== '') { $filter['email_from'] = $email_from; }
    if ($email_to !== '')   { $filter['email_to'] = $email_to; }
    if ($date_from !== '')  { $filter['date_from'] = $date_from; }
    if ($date_to !== '')    { $filter['date_to'] = $date_to; }

    // Query via model
    $model = new FrmSmtpEmailModel();
    $rows = $model->getList($filter, [
        'page'      => $page,
        'per_page'  => $per_page,
        'order_by'  => 'entry_id',
        'order'     => $order,
    ]);
    if ( is_wp_error($rows) ) {
        return '<div style="color:#b00">DB error: ' . esc_html($rows->get_error_message()) . '</div>';
    }

    // Count total (must mirror filters)
    list($where_sql, $where_params) = frm_emails_build_where($filter, $wpdb);
    $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}frm_emails_log {$where_sql}";
    $prepared  = $where_params ? $wpdb->prepare($count_sql, $where_params) : $count_sql;
    $total     = (int) $wpdb->get_var($prepared);
    $total_pages = max(1, (int) ceil($total / $per_page));

    // Persist current filters when building links
    $persist = [
        // note: keep the RAW value in the UI; it won't show "0"
        'entry_id'     => $entry_id_raw !== '' ? $entry_id_raw : null,
        'subject'      => $subject ?: null,
        'email_from'   => $email_from ?: null,
        'email_to'     => $email_to ?: null,
        'date_from'    => $date_from ?: null,
        'date_to'      => $date_to ?: null,
        'fel_per_page' => $per_page,
        'fel_order'    => $order,
    ];
    $persist = array_filter($persist, fn($v) => $v !== null && $v !== '');

    $build_url = function(array $overrides = []) use ($persist) {
        $args = array_merge($persist, $overrides);
        return esc_url( add_query_arg($args, get_permalink()) );
    };

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
        .frm-emails-filters .reset-btn { padding:8px 12px; border:1px solid #d0d7de; background:#fff; color:#24292f; border-radius:6px; cursor:pointer; }
        .frm-emails-table { width:100%; border-collapse: collapse; }
        .frm-emails-table th, .frm-emails-table td { border-bottom:1px solid #eaeef2; padding:8px 10px; vertical-align: top; }
        .frm-emails-table th { text-align:left; background:#f6f8fa; }
        .frm-emails-table .view-btn { padding:6px 10px; border:1px solid #1f6feb; background:#1f6feb; color:#fff; border-radius:6px; cursor:pointer; text-decoration:none; display:inline-block; }
        .frm-emails-pager { display:flex; gap:8px; align-items:center; justify-content:space-between; margin-top:12px; }
        .frm-emails-pager a, .frm-emails-pager span { padding:6px 10px; border:1px solid #d0d7de; border-radius:6px; text-decoration:none; color:#24292f; }
        .frm-emails-pager .active { background:#1f6feb; color:#fff; border-color:#1f6feb; }
        .frm-emails-pager .disabled { opacity:.45; pointer-events:none; }
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
    </style>

    <div class="frm-emails-wrap">
        <?php $reset_url = esc_url( get_permalink() ); ?>
        <form method="get" class="frm-emails-filters" action="<?php echo esc_url( get_permalink() ); ?>">
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
                <input id="fe-from" type="text" name="email_from" value="<?php echo esc_attr($email_from); ?>" placeholder="exact...">
            </div>
            <div class="field">
                <label for="fe-to">Email To</label>
                <input id="fe-to" type="text" name="email_to" value="<?php echo esc_attr($email_to); ?>" placeholder="contains...">
            </div>
            <div class="field">
                <label for="fe-date-from">Date From</label>
                <input id="fe-date-from" type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>">
            </div>
            <div class="field">
                <label for="fe-date-to">Date To</label>
                <input id="fe-date-to" type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>">
            </div>

            <!-- carry current sort + per-page, and reset to page 1 on filter -->
            <input type="hidden" name="fel_order" value="<?php echo esc_attr($order); ?>">
            <input type="hidden" name="fel_per_page" value="<?php echo esc_attr($per_page); ?>">
            <input type="hidden" name="fel_page" value="1">

            <div class="field">
                <label>&nbsp;</label>
                <button class="submit-btn" type="submit">Filter</button>
            </div>
            <div class="field">
                <label>&nbsp;</label>
                <a class="reset-btn" href="<?php echo $reset_url; ?>">Reset</a>
            </div>
            <div class="field">
                <label for="fe-per-page">Rows</label>
                <select id="fe-per-page" name="fel_per_page" onchange="this.form.submit()">
                    <?php foreach ([10,25,50,100] as $opt): ?>
                        <option value="<?php echo (int)$opt; ?>" <?php selected($per_page, $opt); ?>><?php echo (int)$opt; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php
        $toggle_order = ($order === 'ASC') ? 'DESC' : 'ASC';
        $sort_url = $build_url(['fel_order' => $toggle_order, 'fel_page' => 1]);
        ?>

        <table class="frm-emails-table">
            <thead>
            <tr>
                <th><a href="<?php echo esc_url($sort_url); ?>">Entry ID <?php echo $order === 'ASC' ? '▲' : '▼'; ?></a></th>
                <th>Subject</th>
                <th>Content</th>
                <th>Email From</th>
                <th>Email To</th>
                <th>Status</th>
                <th>Date</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7" style="color:#57606a;">No results.</td></tr>
            <?php else: foreach ($rows as $r):
                $rid      = (int)($r['id'] ?? 0);
                $entryVal = isset($r['entry_id']) ? (int)$r['entry_id'] : 0;
                $subj     = (string)($r['subject'] ?? '');
                $from     = (string)($r['email_from'] ?? '');
                $to       = (string)($r['email_to'] ?? '');
                $status   = $r['status'];
                $dateRaw  = (string)($r['date_sent'] ?? '');
                $dateFmt  = $dateRaw ? date_i18n('Y-m-d H:i', strtotime($dateRaw)) : '';
                $html     = (string)($r['content_html'] ?? '');
                $plain    = (string)($r['content_plain'] ?? '');
                $content  = ($html !== '') ? wp_kses_post($html) : '<pre>' . esc_html($plain) . '</pre>';
                $tpl_id   = 'frm-emails-content-' . $rid;
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
                    <td><?php echo $status; ?></td>
                    <td><?php echo esc_html($dateFmt); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php
        $total_pages = max(1, $total_pages);
        $prev_page = max(1, $page - 1);
        $next_page = min($total_pages, $page + 1);
        $page_url = fn($n) => $build_url(['fel_page' => max(1,(int)$n)]);
        ?>

        <div class="frm-emails-pager">
            <div class="pages">
                <a class="<?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo $page_url($prev_page); ?>">Prev</a>
                <?php
                $window = 3;
                $start  = max(1, $page - $window);
                $end    = min($total_pages, $page + $window);
                if ($start > 1) {
                    echo '<a href="' . $page_url(1) . '">1</a>';
                    if ($start > 2) echo '<span>…</span>';
                }
                for ($i = $start; $i <= $end; $i++) {
                    $cls = $i === $page ? 'active' : '';
                    echo '<a class="' . $cls . '" href="' . $page_url($i) . '">' . $i . '</a>';
                }
                if ($end < $total_pages) {
                    if ($end < $total_pages - 1) echo '<span>…</span>';
                    echo '<a href="' . $page_url($total_pages) . '">' . $total_pages . '</a>';
                }
                ?>
                <a class="<?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="<?php echo $page_url($next_page); ?>">Next</a>
            </div>
            <div>
                <span style="color:#57606a;">Showing page <?php echo (int)$page; ?> of <?php echo (int)$total_pages; ?> (<?php echo (int)$total; ?> total)</span>
            </div>
        </div>
    </div>

    <!-- Modal (unchanged) -->
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
        const overlay = document.getElementById('frm-emails-modal-overlay');
        const modal   = document.getElementById('frm-emails-modal');
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
            const btn = e.target.closest('a.view-btn');
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

/** WHERE builder mirrors the same entry_id rule (positive only) */
function frm_emails_build_where(array $filter, wpdb $wpdb) {
    $where  = [];
    $params = [];

    if (isset($filter['entry_id']) && is_int($filter['entry_id']) && $filter['entry_id'] > 0) {
        $where[]  = 'entry_id = %d';
        $params[] = (int)$filter['entry_id'];
    }
    if (!empty($filter['subject'])) {
        $like     = '%' . $wpdb->esc_like((string)$filter['subject']) . '%';
        $where[]  = 'subject LIKE %s';
        $params[] = $like;
    }
    if (!empty($filter['email_from'])) {
        $where[]  = 'email_from = %s';
        $params[] = (string)$filter['email_from'];
    }
    if (!empty($filter['email_to'])) {
        $like     = '%' . $wpdb->esc_like((string)$filter['email_to']) . '%';
        $where[]  = 'email_to LIKE %s';
        $params[] = $like;
    }
    if (!empty($filter['date_from'])) {
        $where[]  = 'date_sent >= %s';
        $params[] = frm_emails_to_mysql_dt($filter['date_from']);
    }
    if (!empty($filter['date_to'])) {
        $where[]  = 'date_sent <= %s';
        $params[] = frm_emails_to_mysql_dt($filter['date_to'], true);
    }

    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    return [$where_sql, $params];
}

function frm_emails_to_mysql_dt($val, $endOfDay = false) {
    $val = trim((string)$val);
    if ($val === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        $val .= $endOfDay ? ' 23:59:59' : ' 00:00:00';
    }
    $ts = strtotime($val);
    return $ts ? gmdate('Y-m-d H:i:s', $ts) : $val;
}
