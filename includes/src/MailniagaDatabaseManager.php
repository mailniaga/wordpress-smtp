<?php

namespace Webimpian\MailniagaWPConnector;

class MailniagaDatabaseManager {
	public static function create_tables() {
		self::create_email_queue_table();
		self::create_failed_delivery_table();
	}

	public static function create_email_queue_table() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_email_queue';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            to_email varchar(255) NOT NULL,
            from_email varchar(255) NOT NULL,
            from_name varchar(255) NOT NULL,
            subject text NOT NULL,
            message longtext NOT NULL,
            headers text,
            attachments text,
            status varchar(20) NOT NULL DEFAULT 'queued',
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            error_message text,
            created_at datetime NOT NULL,
            updated_at datetime,
            PRIMARY KEY (id),
            KEY status_created (status, created_at),
            KEY status_updated (status, updated_at),
            KEY created_at (created_at)
        ) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}

	public static function create_failed_delivery_table() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_failed_deliveries';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            email_id varchar(255) NOT NULL,
            domain varchar(255) NOT NULL,
            to_email varchar(255) NOT NULL,
            address varchar(255) NOT NULL,
            user varchar(255) NOT NULL,
            interface varchar(50) NOT NULL,
            from_email varchar(255) NOT NULL,
            delivery_response text,
            ip varchar(45) NOT NULL,
            mx varchar(255) NOT NULL,
            created_at datetime NOT NULL,
            unsubscribed tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY email_id (email_id),
            KEY to_email (to_email)
        ) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}
}
