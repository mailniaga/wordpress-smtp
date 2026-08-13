<?php

namespace Webimpian\MailniagaWPConnector;

class MailniagaEmailLogCleaner {
	private const DAYS_TO_KEEP = 7;

	private const BATCH_SIZE = 2000;

	private const MAX_BATCHES = 10;

	public function register() {
		add_action('mailniaga_clean_email_logs', [$this, 'clean_old_email_logs']);

		if (!wp_next_scheduled('mailniaga_clean_email_logs')) {
			wp_schedule_event(time(), 'hourly', 'mailniaga_clean_email_logs');
		}
	}

	public function clean_old_email_logs() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'mailniaga_email_queue';
		$days = max(1, (int) apply_filters('mailniaga_log_retention_days', self::DAYS_TO_KEEP));
		$cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

		for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM $table_name WHERE created_at < %s LIMIT %d",
					$cutoff,
					self::BATCH_SIZE
				)
			);

			if (!$deleted) {
				break;
			}
		}
	}

	public function unregister() {
		$timestamp = wp_next_scheduled('mailniaga_clean_email_logs');

		if ($timestamp) {
			wp_unschedule_event($timestamp, 'mailniaga_clean_email_logs');
		}
	}
}
