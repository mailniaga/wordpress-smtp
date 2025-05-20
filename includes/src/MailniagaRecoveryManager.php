<?php

namespace Webimpian\MailniagaWPConnector;

class MailniagaRecoveryManager {
	public function register() {
		add_action('init', [$this, 'schedule_recovery']);
		add_action('mailniaga_recover_stale', [$this, 'recover_stale_processing_emails']);
	}

	public function schedule_recovery() {
		if (!as_next_scheduled_action('mailniaga_recover_stale')) {
			as_schedule_recurring_action(time(), 10, 'mailniaga_recover_stale');
			error_log('Scheduled mailniaga_recover_stale');
		}
	}

	public function recover_stale_processing_emails() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_email_queue';

		$stale_threshold_minutes = 10;

		$stale_emails = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM $table_name 
                WHERE status = 'processing' 
                AND updated_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)",
				$stale_threshold_minutes
			)
		);

		if (empty($stale_emails)) {
			return;
		}

		$email_ids = array_map(function($email) {
			return $email->id;
		}, $stale_emails);

		$ids = implode(',', array_map('intval', $email_ids));
		$wpdb->query("UPDATE $table_name 
            SET status = 'queued', 
                updated_at = '" . current_time('mysql') . "' 
            WHERE id IN ($ids)");

		error_log("MailNiaga: Recovered " . count($email_ids) . " stale processing emails");
	}
}