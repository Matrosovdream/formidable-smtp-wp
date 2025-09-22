# Frm SMTP Emails

A small WordPress add‑on that **imports and normalizes email logs from WP Mail SMTP Pro** into its own table and exposes simple UIs to browse them. It’s especially handy when you use **Formidable Forms** and want to see all emails related to a specific form entry.

---

## What this plugin is for

- Keep a clean, searchable **email log** separate from WP Mail SMTP’s internal table.
- See **all emails tied to a single Formidable form entry**.
- Quickly browse emails with filters, sorting, pagination, and a **sanitized “View content”** modal.
- Run **automatic imports** every 5 minutes to keep your custom log fresh.

---

## Requirements

- WordPress 6.0+
- PHP 7.4+ (8.x OK)
- WP Mail SMTP Pro (source of the original email logs)
- (Recommended) Formidable Forms — used to match `form_id` from the subject

---

## Installation (quick)

1. Upload the plugin folder to `wp-content/plugins/` and activate it.
2. Make sure these classes are loaded by your plugin:
   - `FrmSmtpMigrations` (creates/updates the `frm_emails_log` table)
   - `FrmSmtpEmailsCron` (schedules the import every 5 minutes)
   - `FrmSmtpEmailParser` (reads WP Mail SMTP Pro logs and prepares rows)
   - `FrmSmtpEmailModel` (query helper used by the shortcodes)

Typical bootstrap:
```php
add_action( 'plugins_loaded', [ 'FrmSmtpMigrations', 'maybe_upgrade' ] );
add_action( 'plugins_loaded', [ 'FrmSmtpEmailsCron', 'init' ] );
register_activation_hook( __FILE__, [ 'FrmSmtpEmailsCron', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'FrmSmtpEmailsCron', 'deactivate' ] );
```

That’s it—imports will start running every 5 minutes.

---

## How to use

### 1) Browse everything with filters
Add the shortcode to any page:

```
[frm-emails-list]
```

This renders:
- A filter bar (Entry ID, Subject, Email From/To, Date From/To)
- A table with: Entry ID • Subject • “View” (opens modal) • Email From • Email To • Status • Date
- Sorting by **Entry ID** (click the header)
- Pagination and “rows per page” selector  
_No AJAX; it reloads the page with query params._

### 2) See emails for one entry (pretty cards)
Add this on a page where you want to show all messages for a specific entry:

```
[frm-emails-entry entry="12345"]
```

This renders responsive **cards** (no header, no pagination) showing:
- Subject
- “View content” button (sanitized HTML modal)
- Email From / Email To
- Status (human text)
- Date

> **Note:** Entry ID is **hidden in the UI** for this view; it’s only used to fetch rows.

---

## What gets imported

From `wp_wpmailsmtp_emails_log` into your own `frm_emails_log` table, including:
- `subject`, `message_id`, `people` (parsed into `email_from` / `email_to`), `headers`, `error_text`  
- `content_plain`, `content_html`, `status` (shown as **text** in the model), `date_sent`, `mailer`, `attachments`
- `entry_id` — extracted from the subject (like `#14485`) or from content (like `order=14485`)
- `form_id` — matched by the **first part of the subject** (before ` - `) to a Formidable form title

Only rows with **`initiator_name = "Formidable Forms"`** are imported by default.

Upserts are done by **`message_id`** to avoid duplicates.

---

## Cron (automatic import)

- Hook: `frm_smtp_update_emails`
- Runs every **5 minutes** in chunks (default 100) with an overlap lock.
- Progress is tracked so the next run resumes where the last one stopped.

You can change the default chunk size or filter the initiator if needed:
```php
add_filter('frm_smtp_emails_cron_chunk', fn($n) => 250);
add_filter('frm_smtp_emails_cron_initiator', fn($v) => 'Formidable Forms');
```

---

## Status values

The database keeps a small integer, but **the model returns the text** label in the `status` field (no new field added). Defaults:
- `0` → Sent
- `1` → Failed (also used when `error_text` is present)
- `2` → Waiting
- `3` → Confirmed

You can override labels if you prefer:
```php
add_filter('frm_smtp_status_map', function($map){
  $map[0] = 'Sent';
  $map[1] = 'Failed';
  $map[2] = 'Pending';
  $map[3] = 'Delivered';
  return $map;
});
```

---

## Notes & Tips

- HTML content is sanitized with `wp_kses_post()` before display; plain text is escaped.
- The list view uses namespaced query vars (`fel_page`, `fel_per_page`, `fel_order`) to avoid conflicts with WordPress’s `page` var.
- Make sure only trusted users can access pages where the shortcodes are placed (they expose email contents).

---

## License

MIT (see LICENSE file if included).
