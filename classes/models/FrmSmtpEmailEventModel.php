<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FrmSmtpEmailEventModel {

    /** @var wpdb */
    protected $db;

    /** @var string Fully-qualified table name incl. prefix */
    protected string $table;

    public function __construct() {
        global $wpdb;
        $this->db    = $wpdb;
        $this->table = $this->db->prefix . 'wpmailsmtp_email_tracking_events';
    }

    public function getEventsByLogId( int $log_id ): array {

        $sql = $this->db->prepare( "SELECT * FROM {$this->table} WHERE email_log_id = %d", $log_id );
        $raw = $this->db->get_results( $sql , ARRAY_A );

        $results = [];
        foreach( $raw as $values ) {
            $results[] = $this->prepareRawEventData( $values );
        }

        $results = array_unique( $results );

        return $results ?: [];

    }

    protected function prepareRawEventData( array $data ) {
    
        return $data['event_type'];

    }

}