<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class FrmSmtpEmailsCron {
    /** Cron hook name */
    public const HOOK = 'frm_smtp_update_emails';

    /** Simple overlap lock (transient key) */
    private const LOCK_KEY = 'frm_smtp_emails_cron_lock';

    /** Options used to track progress (no last-id anymore) */
    private const OPT_LAST_SYNC_TS = 'frm_smtp_last_emails_sync';
    private const OPT_LAST_COUNT   = 'frm_smtp_last_emails_processed';

    /**
     * Register schedule + callback. Safe to call multiple times.
     */
    public static function init(): void {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_minute_schedule' ] );
        add_action( self::HOOK, [ __CLASS__, 'run_emails_update' ] );

        // Ensure an event is queued (in case activation hook was missed on deploy)
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 60, 'one_minute', self::HOOK );
        }
    }

    /**
     * Add a 1-minute recurrence to WP-Cron
     */
    public static function add_minute_schedule( array $schedules ): array {
        if ( ! isset( $schedules['one_minute'] ) ) {
            $schedules['one_minute'] = [
                'interval' => 300, // 1 minute
                'display'  => __( 'Every Minute', 'frm-smtp' ),
            ];
        }
        return $schedules;
    }

    /**
     * The job handler — runs the email parser/migrator in chunks.
     * - Transient-based lock to avoid overlapping runs.
     * - Default chunk size filter: 'frm_smtp_emails_cron_chunk' (default 100).
     * - Initiator filter: 'Formidable Forms' (override with 'frm_smtp_emails_cron_initiator').
     * - No last-id tracking — parser should figure out what's next (e.g., by unique keys).
     */
    public static function run_emails_update(): void {
        // Prevent overlaps (lock for ~50 seconds)
        if ( get_transient( self::LOCK_KEY ) ) {
            return;
        }
        set_transient( self::LOCK_KEY, 1, 50 );

        try {
            if ( ! class_exists( 'FrmSmtpEmailParser' ) || ! class_exists( 'FrmSmtpEmailModel' ) ) {
                return;
            }

            $chunk = (int) apply_filters( 'frm_smtp_emails_cron_chunk', 100 );
            if ( $chunk <= 0 ) { $chunk = 100; }

            $initiator = (string) apply_filters( 'frm_smtp_emails_cron_initiator', 'Formidable Forms' );

            $parser = new FrmSmtpEmailParser( new FrmSmtpEmailModel() );
            if ( method_exists( $parser, 'setInitiatorFilter' ) ) {
                $parser->setInitiatorFilter( $initiator );
            }

            // No last-id — let the parser decide the "next" set based on its own logic.
            $result = $parser->migrate( $chunk, null ); // returns e.g. ['processed'=>int, ...] (last_id ignored)

            if ( is_array( $result ) && array_key_exists( 'processed', $result ) ) {
                update_option( self::OPT_LAST_COUNT, (int) $result['processed'] );
            }
            update_option( self::OPT_LAST_SYNC_TS, time() );

        } catch ( \Throwable $e ) {
            error_log( '[Frm SMTP] Cron exception: ' . $e->getMessage() );
        } finally {
            delete_transient( self::LOCK_KEY );
        }
    }

    /**
     * Called on plugin activation
     */
    public static function activate(): void {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_minute_schedule' ] );
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 60, 'one_minute', self::HOOK );
        }
    }

    /**
     * Called on plugin deactivation
     */
    public static function deactivate(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }

    /**
     * Delete cron/runtime options (keep DB version option intact).
     */
    public static function delete_runtime_options(): void {
        delete_option( self::OPT_LAST_SYNC_TS ); // frm_smtp_last_emails_sync
        delete_option( self::OPT_LAST_COUNT );   // frm_smtp_last_emails_processed
        delete_transient( self::LOCK_KEY );      // frm_smtp_emails_cron_lock
        // Do NOT touch FrmSmtpMigrations::VERSION_OPTION ('frm_smtp_db_version')
    }
}
