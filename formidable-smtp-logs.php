<?php
/*
Plugin Name: Formidable SMTP Logs Extension
Description: 
Version: 1.0
Plugin URI: 
Author URI: 
Author: Stanislav Matrosov
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Variables
define('FRM_SMTP_BASE_URL', __DIR__);

// Initialize core

require_once 'classes/FrmSmtpInit.php';


add_action('init', 'FrmSmtpInit');
function FrmSmtpInit() {
    
    if( isset( $_GET['lgg'] ) ) {

        // Somewhere in an admin/CLI context:
        $parser = new FrmSmtpEmailParser();
        $result = $parser->migrate(50); // chunk size 100 (default)

        echo '<pre>'; 
        print_r($result); 
        echo '</pre>';
        die();

    }

}














































