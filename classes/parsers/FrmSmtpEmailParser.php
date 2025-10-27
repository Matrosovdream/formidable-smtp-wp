<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Parse and migrate rows from wp_wpmailsmtp_emails_log into frm_emails_log.
 * Filters origin rows by initiator_name = 'Formidable Forms'.
 */
class FrmSmtpEmailParser {
    /** @var wpdb */
    protected $db;

    /** @var FrmSmtpEmailModel */
    protected $model;

    /** Cache for forms title => id */
    protected array $formsMap = [];

    /** Table names */
    protected string $originTable; // wpmailsmtp_emails_log
    protected string $formsTable;  // frm_forms

    /** Filter: initiator_name must equal this value (default: 'Formidable Forms') */
    protected string $initiatorFilter = 'Formidable Forms';

    public function __construct( ?FrmSmtpEmailModel $model = null ) {
        global $wpdb;
        $this->db          = $wpdb;
        $this->originTable = $this->db->prefix . 'wpmailsmtp_emails_log';
        $this->formsTable  = $this->db->prefix . 'frm_forms';
        $this->model       = $model ?: new FrmSmtpEmailModel();
    }

    /**
     * Optionally change the initiator filter (exact match).
     */
    public function setInitiatorFilter( string $value ): self {
        $this->initiatorFilter = $value;
        return $this;
    }

    /**
     * Public entry: migrate all rows in chunks (default 100).
     * Only rows with initiator_name = 'Formidable Forms' are processed.
     *
     * @param int      $chunkSize      Number of rows per DB batch (default 100)
     * @param int|null $startAfterId   If non-null, start strictly after this origin id
     * @return array{processed:int,chunks:int,last_id:int|null}
     */
    public function migrate( int $chunkSize = 100, ?int $startAfterId = null ): array {
        $this->ensureFormsMapLoaded();

        $processed = 0;
        $chunks    = 0;
        $lastId    = $startAfterId;

        while ( true ) {
            $batch = $this->fetchOriginBatch( $chunkSize, $lastId );
            if ( empty( $batch ) ) {
                break;
            }

            $rowsForUpsert = [];
            foreach ( $batch as $row ) {
                $rowsForUpsert[] = $this->transformOriginRow( $row );
                $lastId = (int) $row['id'];
            }

            if ( ! empty( $rowsForUpsert ) ) {
                $this->model->multipleUpdateCreate( $rowsForUpsert );
            }

            $processed += count( $batch );
            $chunks++;
        }

        return [
            'processed' => $processed,
            'chunks'    => $chunks,
            'last_id'   => $lastId,
        ];
    }

    /* ============================
     * Fetching (filtered by initiator_name)
     * ============================ */

    /**
     * Fetch a batch of rows from the origin table after a given id,
     * filtered by initiator_name = $this->initiatorFilter.
     *
     * @param int      $limit
     * @param int|null $afterId
     * @return array<int,array<string,mixed>>
     */
    protected function fetchOriginBatch( int $limit, ?int $afterId ): array {
        if ( $afterId !== null ) {
            $sql = $this->db->prepare(
                "SELECT * FROM {$this->originTable}
                 WHERE initiator_name = %s AND id > %d
                 ORDER BY id ASC
                 LIMIT %d",
                $this->initiatorFilter, $afterId, $limit
            );
        } else {
            $sql = $this->db->prepare(
                "SELECT * FROM {$this->originTable}
                 WHERE initiator_name = %s
                 ORDER BY id ASC
                 LIMIT %d",
                $this->initiatorFilter, $limit
            );
        }

        $rows = $this->db->get_results( $sql, ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Load all forms once: map normalized form title => id.
     */
    protected function ensureFormsMapLoaded(): void {
        if ( ! empty( $this->formsMap ) ) {
            return;
        }
        $sql   = "SELECT id, name FROM {$this->formsTable}";
        $forms = $this->db->get_results( $sql, ARRAY_A ) ?: [];
        foreach ( $forms as $f ) {
            $name = isset( $f['name'] ) ? (string) $f['name'] : '';
            $id   = (int) $f['id'];
            if ( $name !== '' && $id > 0 ) {
                $this->formsMap[ $this->normalizeTitle( $name ) ] = $id;
            }
        }
    }

    /* ============================
     * Transform
     * ============================ */

    /**
     * Transform one origin row to the destination row schema for multipleUpdateCreate.
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    protected function transformOriginRow( array $o ): array {
        $subject      = (string) ( $o['subject'] ?? '' );
        $peopleRaw    = (string) ( $o['people'] ?? '' );
        $messageId    = $this->resolveMessageId( $o );
        $contentHtml  = (string) ( $o['content_html'] ?? '' );
        $contentPlain = (string) ( $o['content_plain'] ?? '' );

        // 1) emails from JSON people
        [ $emailFrom, $emailTo ] = $this->parsePeopleEmails( $peopleRaw );

        // 2) subject parsing
        $formTitle = $this->extractFormTitleFromSubject( $subject );  // before " - "
        $entryId   = $this->extractEntryIdFromSubject( $subject );    // #12345 if present

        // 3) fallback entry id from content
        if ( ! $entryId ) {
            $entryId = $this->extractEntryIdFromContent( $contentHtml, $contentPlain );
        }

        // 4) resolve form_id by title
        $formId = $this->resolveFormIdByTitle( $formTitle );

        // Truncate to fit destination column sizes
        $emailFrom = $this->truncate( $emailFrom, 255 );
        $emailTo   = $this->truncate( $emailTo,   255 );
        $subject   = $this->truncate( $subject,   191 );

        // Build destination row for upsert
        return [
            'entry_id'       => $entryId ?: null,
            'form_id'        => $formId ?: null,
            'subject'        => $subject,
            'message_id'     => $messageId,
            'email_from'     => $emailFrom,
            'email_to'       => $emailTo,
            'people'         => (string) $peopleRaw,
            'headers'        => (string) ( $o['headers'] ?? '' ),
            'error_text'     => (string) ( $o['error_text'] ?? '' ),
            'content_plain'  => $contentPlain,
            'content_html'   => $contentHtml,
            'status'         => isset( $o['status'] ) ? (int) $o['status'] : 0,
            'date_sent'      => isset( $o['date_sent'] ) ? (string) $o['date_sent'] : null,
            'mailer'         => (string) ( $o['mailer'] ?? '' ),
            'attachments'    => isset( $o['attachments'] ) ? (int) $o['attachments'] : 0,
            'initiator_name' => isset( $o['initiator_name'] ) ? (string) $o['initiator_name'] : null,
            'initiator_file' => isset( $o['initiator_file'] ) ? (string) $o['initiator_file'] : null,
            'original_log_id' => isset( $o['id'] ) ? (int) $o['id'] : null,
        ];
    }

    /**
     * Guarantee a usable message_id for upsert:
     *  - use origin message_id if present
     *  - otherwise synthesize from origin id (e.g., "wpmailsmtp:12345")
     */
    protected function resolveMessageId( array $o ): string {
        $mid = (string) ( $o['message_id'] ?? '' );
        if ( $mid !== '' ) {
            return $mid;
        }
        $oid = isset( $o['id'] ) ? (int) $o['id'] : 0;
        return 'wpmailsmtp:' . $oid;
    }

    /* ============================
     * Subject / Content parsing
     * ============================ */

    protected function extractFormTitleFromSubject( string $subject ): string {
        // Normalize fancy dashes and spaces
        $normalized = str_replace(["\xE2\x80\x93", "\xE2\x80\x94"], '-', $subject); // en/em dashes
        $parts = preg_split('/\s*-\s*/', $normalized, 2);
        $first = trim( (string) ($parts[0] ?? '') );
        $first = preg_replace('/\s+/', ' ', $first);
        return $first ?: '';
    }

    protected function extractEntryIdFromSubject( string $subject ): ?int {
        if ( preg_match('/#\s*(\d{1,10})\b/', $subject, $m) ) {
            return (int) $m[1];
        }
        return null;
    }

    protected function extractEntryIdFromContent( string $html, string $plain ): ?int {
        if ( preg_match('/order\s*=\s*(\d{1,10})\b/i', $html, $m) ) {
            return (int) $m[1];
        }
        if ( preg_match('/order%3D(\d{1,10})\b/i', $html, $m) ) {
            return (int) $m[1];
        }
        if ( preg_match('/order\s*=\s*(\d{1,10})\b/i', $plain, $m) ) {
            return (int) $m[1];
        }
        if ( preg_match('/order%3D(\d{1,10})\b/i', $plain, $m) ) {
            return (int) $m[1];
        }
        return null;
    }

    /* ============================
     * People JSON parsing
     * ============================ */

    protected function parsePeopleEmails( string $peopleRaw ): array {
        $emailFrom = '';
        $emailTo   = '';

        $data = json_decode( $peopleRaw, true );
        if ( is_array( $data ) ) {
            if ( isset( $data['from'] ) ) {
                $emailFrom = is_array( $data['from'] )
                    ? trim( (string) reset( $data['from'] ) )
                    : trim( (string) $data['from'] );
            }
            if ( isset( $data['to'] ) ) {
                if ( is_array( $data['to'] ) ) {
                    $emails = array_filter( array_map( 'trim', array_map( 'strval', $data['to'] ) ) );
                    $emailTo = implode( ',', $emails );
                } else {
                    $emailTo = trim( (string) $data['to'] );
                }
            }
        }

        return [ $emailFrom, $emailTo ];
    }

    /* ============================
     * Form title -> form_id resolution
     * ============================ */

    protected function resolveFormIdByTitle( string $title ): ?int {
        if ( $title === '' ) {
            return null;
        }
        $key = $this->normalizeTitle( $title );
        return $this->formsMap[ $key ] ?? null;
    }

    protected function normalizeTitle( string $s ): string {
        $s = trim( $s );
        $s = preg_replace('/\s+/', ' ', $s);
        $s = mb_strtolower( $s );
        return $s;
    }

    /* ============================
     * Utils
     * ============================ */

    protected function truncate( string $s, int $max ): string {
        return ( strlen( $s ) > $max ) ? substr( $s, 0, $max ) : $s;
    }
}
