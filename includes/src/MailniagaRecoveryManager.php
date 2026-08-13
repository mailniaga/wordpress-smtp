<?php

namespace Webimpian\MailniagaWPConnector;

class MailniagaRecoveryManager {
	/** Must exceed the longest a send run can take, or in-flight mail is resent. */
	private const STALE_MINUTES = 30;

	private const INTERVAL = 900;
	private const BATCH_SIZE = 500;

	public function register() {
		add_action('init', [$this, 'schedule_recovery']);
		add_action('mailniaga_recover_stale', [$this, 'recover_stale_processing_emails']);
	}

	public function schedule_recovery() {
		if (!as_next_scheduled_action('mailniaga_recover_stale')) {
			as_schedule_recurring_action(time() + self::INTERVAL, self::INTERVAL, 'mailniaga_recover_stale');
		}
	}

	public function recover_stale_processing_emails() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_email_queue';

		$minutes = max(1, (int) apply_filters('mailniaga_stale_minutes', self::STALE_MINUTES));

		$stale_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM $table_name
				 WHERE status = 'processing'
				 AND updated_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)
				 LIMIT %d",
				$minutes,
				self::BATCH_SIZE
			)
		);

		if (empty($stale_ids)) {
			return;
		}

		$ids = implode(',', array_map('intval', $stale_ids));

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table_name SET status = 'queued', updated_at = %s WHERE id IN ($ids)",
				current_time('mysql')
			)
		);
	}
}
