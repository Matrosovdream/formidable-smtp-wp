<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FrmSmtpEmailModel extends FrmSmptAbstractModel {
    /** @var wpdb */
    protected $db;

    /** @var string Fully-qualified table name incl. prefix */
    protected string $table;

    /** Whitelisted sortable columns */
    private const SORTABLE = [
        'id',
        'entry_id',
        'form_id',
        'subject',
        'date_sent',
    ];

    /**
     * Map numeric codes to human text.
     * Feel free to adjust. You can also override via the 'frm_smtp_status_map' filter.
     */
    private const STATUS_MAP = [
        0 => 'Sent',
        1 => 'Sent',
        2 => 'Waiting',
        3 => 'Confirmed',
    ];

    public function __construct() {
        global $wpdb;
        $this->db    = $wpdb;
        $this->table = $this->db->prefix . 'frm_emails_log';
    }

    /**
     * Convert numeric status to human-readable text.
     * If there's an error_text, we prefer "Failed".
     */
    public static function statusToText( ?int $code, ?string $errorText = null ): string {
        // Allow site owners to customize labels.
        $map = apply_filters( 'frm_smtp_status_map', self::STATUS_MAP );

        if ( ! empty( $errorText ) ) {
            return $map[1] ?? 'Failed';
        }

        // Default to "Sent" if null/unknown.
        return $map[ $code ?? 0 ] ?? ( $map[0] ?? 'Sent' );
    }

    /**
     * Base list query for frm_emails_log
     *
     * Supported $filter keys (all optional):
     *  - entry_id (int, equals)
     *  - subject (string, LIKE)
     *  - people (string, LIKE)
     *  - status (string|int) [you can still pass number; conversion happens after fetch]
     *  - date_from / date_to (Y-m-d or datetime, on date_sent)
     *  - email_from (string, equals)
     *  - email_to (string, LIKE)
     *  - initiator_name (string, equals)
     *
     * $opts:
     *  - order_by (self::SORTABLE) default 'date_sent'
     *  - order ('ASC'|'DESC') default 'DESC'
     *  - limit (int) default 50
     *  - offset (int) default 0 (or use page/per_page)
     *  - page, per_page (ints) — convenience
     */
    public function getList( array $filter = [], array $opts = [] ) {
        $where  = [];
        $params = [];

        if ( isset( $filter['entry_id'] ) && $filter['entry_id'] !== '' ) { $where[] = 'entry_id = %d'; $params[] = (int) $filter['entry_id']; }
        if ( ! empty( $filter['subject'] ) ) { $like = '%' . $this->db->esc_like( (string) $filter['subject'] ) . '%'; $where[] = 'subject LIKE %s'; $params[] = $like; }
        if ( ! empty( $filter['people'] ) ) { $like = '%' . $this->db->esc_like( (string) $filter['people'] ) . '%'; $where[] = 'people LIKE %s'; $params[] = $like; }
        if ( ! empty( $filter['email_from'] ) ) { $where[] = 'email_from = %s'; $params[] = (string) $filter['email_from']; }
        if ( ! empty( $filter['email_to'] ) ) { $like = '%' . $this->db->esc_like( (string) $filter['email_to'] ) . '%'; $where[] = 'email_to LIKE %s'; $params[] = $like; }
        if ( ! empty( $filter['initiator_name'] ) ) { $where[] = 'initiator_name = %s'; $params[] = (string) $filter['initiator_name']; }

        if ( ! empty( $filter['date_from'] ) ) { $where[] = 'date_sent >= %s'; $params[] = $this->dateToMysql( $filter['date_from'] ); }
        if ( ! empty( $filter['date_to'] ) )   { $where[] = 'date_sent <= %s'; $params[] = $this->dateToMysql( $filter['date_to'], true ); }

        // Optional: allow status filter from UI as either text or code (matches after conversion).
        if ( isset( $filter['status'] ) && $filter['status'] !== '' ) {
            // If numeric, match as code; if string, match by text later (post-filter).
            if ( is_numeric( $filter['status'] ) ) {
                $where[]  = 'status = %d';
                $params[] = (int) $filter['status'];
            } else {
                // We'll filter by text after fetch.
            }
        }

        $whereSql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

        $orderBy = isset( $opts['order_by'] ) && in_array( $opts['order_by'], self::SORTABLE, true ) ? $opts['order_by'] : 'date_sent';
        $order   = ( isset( $opts['order'] ) && strtoupper( (string) $opts['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';

        $limit  = isset( $opts['limit'] ) ? max( 1, (int) $opts['limit'] ) : 50;
        $offset = isset( $opts['offset'] ) ? max( 0, (int) $opts['offset'] ) : 0;
        if ( isset( $opts['page'] ) || isset( $opts['per_page'] ) ) {
            $pp     = isset( $opts['per_page'] ) ? max( 1, (int) $opts['per_page'] ) : 50;
            $page   = isset( $opts['page'] ) ? max( 1, (int) $opts['page'] ) : 1;
            $limit  = $pp;
            $offset = ( $page - 1 ) * $pp;
        }

        $sql  = "SELECT * FROM {$this->table} {$whereSql} ORDER BY {$orderBy} {$order} LIMIT %d OFFSET %d";
        $args = array_merge( $params, [ $limit, $offset ] );
        $prepared = $this->db->prepare( $sql, $args );
        if ( false === $prepared ) {
            return new WP_Error( 'db_prepare_failed', __( 'Failed to prepare query.', 'frm-smtp' ) );
        }

        $rows = $this->db->get_results( $prepared, ARRAY_A );
        if ( null === $rows ) {
            return new WP_Error( 'db_query_failed', __( 'Database query failed.', 'frm-smtp' ), [ 'last_error' => $this->db->last_error ] );
        }

        // Post-process: convert status code -> text, keep numeric in status_code
        foreach ( $rows as &$r ) {
            $code            = isset( $r['status'] ) ? (int) $r['status'] : null;
            $r['status_code'] = $code;
            $r['status']      = self::statusToText( $code, $r['error_text'] ?? null );
        }
        unset( $r );

        // If caller filtered status by text, apply here.
        if ( isset( $filter['status'] ) && ! is_numeric( $filter['status'] ) && $filter['status'] !== '' ) {
            $needle = mb_strtolower( (string) $filter['status'] );
            $rows = array_values( array_filter( $rows, function( $row ) use ( $needle ) {
                return mb_stripos( (string) $row['status'], $needle ) !== false;
            } ) );
        }

        return $rows;
    }

    /** Get emails by entry_id (all) */
    public function getAllByEntryId( int $entryId, array $opts = [] ) {
        return $this->getList( [ 'entry_id' => $entryId ], $opts );
    }

    /** Get one email by entry_id (first match) */
    public function getByEntryId( int $entryId ) {
        $rows = $this->getList( [ 'entry_id' => $entryId ], [ 'limit' => 1 ] );
        if ( is_wp_error( $rows ) ) { return $rows; }
        return $rows[0] ?? null;
    }

    /** Get all by email address (matches email_from exactly or email_to LIKE) */
    public function getAllByEmail( string $email, array $opts = [] ) {
        $filter = [
            'email_from' => $email,
            'email_to'   => $email, // we'll interpret as LIKE in getList if you prefer, but here we do post-filter for safety
        ];
        // Leverage getList and then refine "to" if needed
        $rows = $this->getList( $filter, $opts );
        if ( is_wp_error( $rows ) ) { return $rows; }

        // Narrow "email_to" to contain the address (if any were matched by from)
        $emailLower = mb_strtolower( $email );
        return array_values( array_filter( $rows, function( $r ) use ( $emailLower ) {
            $to = mb_strtolower( (string) ( $r['email_to'] ?? '' ) );
            $from = mb_strtolower( (string) ( $r['email_from'] ?? '' ) );
            return $from === $emailLower || ( $to !== '' && mb_stripos( $to, $emailLower ) !== false );
        } ) );
    }

    /** Get one by email address */
    public function getByEmail( string $email ) {
        $rows = $this->getAllByEmail( $email, [ 'limit' => 1 ] );
        if ( is_wp_error( $rows ) ) { return $rows; }
        return $rows[0] ?? null;
    }

    /**
     * Bulk upsert into frm_emails_log.
     * - Unique key: message_id
     * - Accepts rows with keys matching table columns below.
     * - Normalizes types (ints, dates), arrays (people/email_to), and status (text or int).
     */
    public function multipleUpdateCreate( array $rows ) {
        $cols = [
            'entry_id',
            'form_id',
            'subject',
            'message_id',
            'email_from',
            'email_to',
            'people',
            'headers',
            'error_text',
            'content_plain',
            'content_html',
            'status',
            'date_sent',
            'mailer',
            'attachments',
            'initiator_name',
            'initiator_file',
        ];

        $formats = [
            'entry_id'       => '%d',
            'form_id'        => '%d',
            'subject'        => '%s',
            'message_id'     => '%s',
            'email_from'     => '%s',
            'email_to'       => '%s',
            'people'         => '%s',
            'headers'        => '%s',
            'error_text'     => '%s',
            'content_plain'  => '%s',
            'content_html'   => '%s',
            'status'         => '%d',
            'date_sent'      => '%s',
            'mailer'         => '%s',
            'attachments'    => '%d',
            'initiator_name' => '%s',
            'initiator_file' => '%s',
        ];

        foreach ( $rows as &$r ) {
            // entry_id / form_id -> ints or NULL
            if ( array_key_exists( 'entry_id', $r ) ) {
                $r['entry_id'] = ($r['entry_id'] === '' || $r['entry_id'] === null) ? null : (int) $r['entry_id'];
            }
            if ( array_key_exists( 'form_id', $r ) ) {
                $r['form_id'] = ($r['form_id'] === '' || $r['form_id'] === null) ? null : (int) $r['form_id'];
            }

            // attachments -> int
            if ( array_key_exists( 'attachments', $r ) ) {
                $r['attachments'] = (int) $r['attachments'];
            }

            // email_to can arrive as array -> join by comma
            if ( array_key_exists( 'email_to', $r ) && is_array( $r['email_to'] ) ) {
                $r['email_to'] = implode( ',', array_filter( array_map( 'trim', $r['email_to'] ) ) );
            }

            // people can arrive as array -> json encode
            if ( array_key_exists( 'people', $r ) && is_array( $r['people'] ) ) {
                $r['people'] = wp_json_encode( $r['people'] );
            }

            // date_sent -> MySQL DATETIME
            if ( array_key_exists( 'date_sent', $r ) && $r['date_sent'] !== '' && $r['date_sent'] !== null ) {
                $r['date_sent'] = $this->dateToMysql( (string) $r['date_sent'] );
            }

            // Ensure message_id present (upsert key)
            if ( empty( $r['message_id'] ) ) {
                // Avoid breaking upsert: you may choose to skip or synthesize one.
                // Here we skip rows without message_id to avoid duplicates.
                $r['__skip__'] = true;
            }
        }
        unset( $r );

        // Drop rows marked to skip (no message_id)
        $rows = array_values( array_filter( $rows, static fn($x) => empty($x['__skip__']) ) );

        if ( empty( $rows ) ) {
            return 0; // nothing to do
        }

        return $this->multipleUpdateCreateAbstract( $rows, $cols, $formats, $uniqueKey = 'message_id' );
    }


}
