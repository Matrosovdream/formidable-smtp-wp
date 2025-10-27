<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FrmSmtpMigrations {

    public const DB_VERSION     = '1.0.5'; // bumped
    public const VERSION_OPTION = 'frm_smtp_db_version';

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $table           = $wpdb->prefix . 'frm_emails_log';

        $sql = "CREATE TABLE {$table} (
            id int(10) unsigned NOT NULL AUTO_INCREMENT,
            entry_id bigint(20) unsigned NULL,
            form_id bigint(20) unsigned NULL,
            subject varchar(191) NOT NULL,
            message_id varchar(191) NULL,
            email_from varchar(255) NULL,
            email_to varchar(255) NULL,
            people text NOT NULL,
            headers text NOT NULL,
            error_text text NULL,
            content_plain longtext NOT NULL,
            content_html longtext NOT NULL,
            status tinyint(3) unsigned NOT NULL DEFAULT 0,
            date_sent timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            mailer varchar(255) NOT NULL,
            attachments tinyint(3) unsigned NOT NULL DEFAULT 0,
            initiator_name varchar(255) NULL,
            initiator_file text NULL,
            original_log_id bigint(20) unsigned NULL,
            PRIMARY KEY  (id),
            KEY idx_entry_id (entry_id),
            KEY idx_form_id (form_id),
            KEY idx_status (status),
            KEY idx_date_sent (date_sent),
            UNIQUE KEY uniq_message_id (message_id),
            KEY idx_email_from (email_from),
            KEY idx_email_to (email_to)
        ) {$charset_collate};";

        dbDelta($sql);

        // Ensure schema is really unique on message_id even on older installs.
        self::enforce_unique_message_id($table);

        update_option(self::VERSION_OPTION, self::DB_VERSION);
    }

    public static function maybe_upgrade(): void {
        $installed = get_option(self::VERSION_OPTION);
        if ($installed !== self::DB_VERSION) {
            self::install();
        }
    }

    /**
     * Make sure message_id is unique & properly sized even for existing tables.
     * - backfill blanks,
     * - de-dupe,
     * - alter type to VARCHAR(191),
     * - add UNIQUE KEY if missing.
     */
    private static function enforce_unique_message_id(string $table): void {
        global $wpdb;

        // 1) Backfill NULL/empty message_id so unique index can be created
        //    Use a deterministic value per row to avoid collisions.
        $wpdb->query("UPDATE {$table} SET message_id = CONCAT('legacy:', id) WHERE message_id IS NULL OR message_id = ''");

        // 2) De-dupe existing duplicates, keep the *latest* row per message_id
        //    (adjust rule if you prefer earliest)
        $dupes = $wpdb->get_results("
            SELECT message_id
            FROM {$table}
            GROUP BY message_id
            HAVING COUNT(*) > 1
        ", ARRAY_A);

        if (!empty($dupes)) {
            foreach ($dupes as $d) {
                $mid    = $d['message_id'];
                // keep the latest by date_sent, delete the rest
                $keepers = $wpdb->get_var($wpdb->prepare("
                    SELECT id FROM {$table}
                    WHERE message_id = %s
                    ORDER BY date_sent DESC, id DESC
                    LIMIT 1
                ", $mid));

                // remove others with same message_id
                if ($keepers) {
                    $wpdb->query($wpdb->prepare("
                        DELETE FROM {$table}
                        WHERE message_id = %s AND id <> %d
                    ", $mid, $keepers));
                }
            }
        }

        // 3) Make sure column is VARCHAR(191)
        //    (Some MySQLs won’t auto-shorten via dbDelta)
        $wpdb->query("ALTER TABLE {$table} MODIFY message_id VARCHAR(191) NULL");

        // 4) Ensure UNIQUE KEY exists (create if missing)
        $has_unique = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(1)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = %s
              AND INDEX_NAME = 'uniq_message_id'
        ", $table));

        if (!$has_unique) {
            // Add unique constraint now that data is clean
            $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY uniq_message_id (message_id)");
        }
    }
}
