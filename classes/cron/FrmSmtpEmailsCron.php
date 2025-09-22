<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class FrmSmtpEmailsCron {
    /** Cron hook name */
    public const HOOK = 'frm_smtp_update_emails';

    /** Simple overlap lock (transient key) */
    private const LOCK_KEY = 'frm_smtp_emails_cron_lock';

    /** Options used to track progress */
    private const OPT_LAST_SYNC_TS = 'frm_smtp_last_emails_sync';
    private const OPT_LAST_ID      = 'frm_smtp_migrate_last_id';
    private const OPT_LAST_COUNT   = 'frm_smtp_last_emails_processed';

    /**
     * Register schedule + callback. Safe to call multiple times.
     */
    public static function init(): void {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_five_min_schedule' ] );
        add_action( self::HOOK, [ __CLASS__, 'run_emails_update' ] );

        // Ensure an event is queued (in case activation hook was missed on deploy)
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 60, 'five_minutes', self::HOOK );
        }
    }

    /**
     * Add a 5-minute recurrence to WP-Cron
     */
    public static function add_five_min_schedule( array $schedules ): array {
        if ( ! isset( $schedules['five_minutes'] ) ) {
            $schedules['five_minutes'] = [
                'interval' => 300, // 5 minutes
                'display'  => __( 'Every 5 Minutes', 'frm-smtp' ),
            ];
        }
        return $schedules;
    }

    /**
     * The job handler — runs the email parser/migrator in chunks.
     * - Respects a simple transient-based lock to avoid overlapping runs.
     * - Remembers last processed origin id to resume efficiently.
     * - Default chunk size is filterable via 'frm_smtp_emails_cron_chunk' (default 100).
     * - Initiator filter is 'Formidable Forms' (override with 'frm_smtp_emails_cron_initiator' filter).
     */
    public static function run_emails_update(): void {
        // Prevent overlaps (lock for ~4 minutes)
        if ( get_transient( self::LOCK_KEY ) ) {
            return;
        }
        set_transient( self::LOCK_KEY, 1, 4 * MINUTE_IN_SECONDS );

        try {
            if ( ! class_exists( 'FrmSmtpEmailParser' ) || ! class_exists( 'FrmSmtpEmailModel' ) ) {
                // Nothing to do if classes aren’t loaded
                return;
            }

            $chunk     = (int) apply_filters( 'frm_smtp_emails_cron_chunk', 100 );
            if ( $chunk <= 0 ) { $chunk = 100; }

            $initiator = (string) apply_filters( 'frm_smtp_emails_cron_initiator', 'Formidable Forms' );

            $lastId = get_option( self::OPT_LAST_ID );
            $lastId = is_numeric( $lastId ) ? (int) $lastId : null;

            $parser = new FrmSmtpEmailParser( new FrmSmtpEmailModel() );
            if ( method_exists( $parser, 'setInitiatorFilter' ) ) {
                $parser->setInitiatorFilter( $initiator );
            }

            $result = $parser->migrate( $chunk, $lastId ); // ['processed'=>int,'chunks'=>int,'last_id'=>?int]

            if ( is_array( $result ) ) {
                if ( array_key_exists( 'last_id', $result ) ) {
                    update_option( self::OPT_LAST_ID, $result['last_id'] );
                }
                if ( array_key_exists( 'processed', $result ) ) {
                    update_option( self::OPT_LAST_COUNT, (int) $result['processed'] );
                }
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
        // Ensure schedule exists before scheduling
        add_filter( 'cron_schedules', [ __CLASS__, 'add_five_min_schedule' ] );
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 60, 'five_minutes', self::HOOK );
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
}
