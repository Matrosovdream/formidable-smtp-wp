<?php

if ( ! defined( 'ABSPATH' ) ) { exit; } 

abstract class FrmSmptAbstractModel {

    /** @var wpdb */
    protected $db;

    /** @var string Fully-qualified table name incl. prefix */
    protected string $table;

    public function __construct() {

        global $wpdb;
        $this->db = $wpdb;

    }


    protected function multipleUpdateCreateAbstract( array $rows, array $cols, array $formats, string $uniqueKey ) {

        if ( empty( $rows ) ) {
            return [ 'processed' => 0, 'chunks' => 0, 'rows_affected' => 0, 'errors' => [] ];
        }

        $processed = 0; $chunks = 0; $affected = 0; $errors = [];
        $batch = [];
        
        foreach ( $rows as $idx => $row ) {
            if ( ! is_array( $row ) ) { continue; }
            if ( empty( $row[ $uniqueKey ] ) ) {
            $errors[] = "Row {$idx}: missing $uniqueKey";
            continue;
            }
            $batch[] = $this->normalizeOrderRow( $row );
            
            
            if ( count( $batch ) >= 100 ) {
            $res = $this->upsertChunk( $batch, $cols, $formats, $uniqueKey );
            if ( is_wp_error( $res ) ) { $errors[] = $res->get_error_message(); }
            else { $affected += (int) $res; $processed += count( $batch ); $chunks++; }
            $batch = [];
            }
        }
        
        if ( $batch ) {
            $res = $this->upsertChunk( $batch, $cols, $formats, $uniqueKey );
            if ( is_wp_error( $res ) ) { $errors[] = $res->get_error_message(); }
            else { $affected += (int) $res; $processed += count( $batch ); $chunks++; }
        }
        
        return [ 'processed' => $processed, 'chunks' => $chunks, 'rows_affected' => $affected, 'errors' => $errors ];

    }   

    protected function upsertChunk( array $batch, array $cols, array $formats, string $uniqueKey ) {

        $valuesSql = [];
        foreach ( $batch as $row ) {
            $vals = [];
            foreach ( $cols as $c ) {
                if ( isset( $formats[$c] ) ) {
                    $vals[] = $this->escapeValueForSql( $formats[$c], $row[$c] ?? null );
                } else {
                    $vals[] = $row[$c] ?? null;
                }
            }
            $valuesSql[] = '(' . implode( ',', $vals ) . ')';
        }
        $colList = implode( ',', $cols );
        
        // Update all fields except the unique key
        $updateCols = array_diff( $cols, [ $uniqueKey ] );
        $updates = array_map( fn($c) => "$c=VALUES($c)", $updateCols );
        
        $sql = "INSERT INTO {$this->table} ({$colList}) VALUES "
        . implode( ',', $valuesSql )
        . " ON DUPLICATE KEY UPDATE " . implode( ',', $updates );
        
        $res = $this->db->query( $sql );
        if ( $res === false ) {
            return new WP_Error( 'db_upsert_failed', __( 'Bulk upsert failed.', 'shipstation-wp' ), [ 'last_error' => $this->db->last_error ] );
        }
        return $res; // rows affected

    }

    /** Get by entry_id */
    public function getByEntryId( int $entryId ) {
        $rows = $this->getList( [ 'entry_id' => $entryId ], [ 'limit' => 1 ] );
        if ( is_wp_error( $rows ) ) { return $rows; }
        return $rows[0] ?? null;
    }

    /** All shipments by WP entry id */
    public function getAllByEntryId( int $entryId, array $opts = [] ) {
        return $this->getList( [ 'entry_id' => $entryId ], $opts );
    }

    /** Escape a single value into SQL literal, respecting NULL and type */
    private function escapeValueForSql( string $format, $value ): string {
        if ( $value === null || $value === '' ) {
            return 'NULL';
        }
        switch ( $format ) {
            case '%d':
            return (string) (int) $value;
            case '%f':
            return (string) (float) $value;
            case '%s':
            default:
            return $this->db->prepare( '%s', (string) $value );
        }
    }

    protected function normalizeOrderRow( array $row ): array {
        return $row;
    }

    protected function dateToMysql( string $in ): string {
        $t = strtotime( $in );
        if ( ! $t ) { return $in; }
        return gmdate( 'Y-m-d H:i:s', $t );
    }

}