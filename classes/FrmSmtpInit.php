<?php
class FrmSmtpInit {

    public function __construct() {

        // Shortcodes
        $this->include_shortcodes();

        // Migrations
        $this->include_migrations();

        // Models
        $this->include_models();

        // Parsers
        $this->include_parsers();

        // CRON
        $this->include_cron();

    }

    private function include_parsers() {

        // Abstract Parser
        //require_once FRM_SMTP_BASE_URL.'/classes/parsers/FrmSmtpAbstractParser.php';

        // Email Parser
        require_once FRM_SMTP_BASE_URL.'/classes/parsers/FrmSmtpEmailParser.php';

    }

    private function include_migrations() {

        // Entries cleaner extra tables
        require_once FRM_SMTP_BASE_URL.'/classes//migrations/FrmSmtpMigrations.php';

        // Run migrations
        FrmSmtpMigrations::maybe_upgrade();

    }

    private function include_models() {

        // Abstract model
        require_once FRM_SMTP_BASE_URL.'/classes/models/FrmSmtpAbstractModel.php';

        // Shipment model
        require_once FRM_SMTP_BASE_URL.'/classes/models/FrmSmtpEmailModel.php';

    }

    private function include_cron() {

        // Shipments cron
        require_once FRM_SMTP_BASE_URL.'/classes/cron/FrmSmtpEmailsCron.php';
        FrmSmtpEmailsCron::init();

    }

    private function include_shortcodes() {

        // Emails list --- admin
        require_once FRM_SMTP_BASE_URL.'/shortcodes/admin/frm-emails-list.php';

        // Emails for an entry --- admin
        require_once FRM_SMTP_BASE_URL.'/shortcodes/admin/frm-emails-entry.php';

    }

}

new FrmSmtpInit();