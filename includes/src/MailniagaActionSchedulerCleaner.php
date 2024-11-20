<?php

namespace Webimpian\MailniagaWPConnector;

class MailniagaActionSchedulerCleaner {
	private const BATCH_SIZE = 1000;
	private const HOOK_NAME = 'mailniaga_cleanup_action_scheduler';

	/**
	 * Register hooks and initialize the cleaner
	 *
	 * @return void
	 */
	public function register(): void {
		if (!wp_next_scheduled(self::HOOK_NAME)) {
			wp_schedule_event(time(), 'bwf_every_minute', self::HOOK_NAME);
		}

		add_action(self::HOOK_NAME, [$this, 'cleanup_actions']);
		add_filter('cron_schedules', [$this, 'add_minute_interval']);
		register_deactivation_hook(MAILNIAGA_WP_CONNECTOR['FILE'], [$this, 'deactivate']);
	}

	/**
	 * Add minute interval to WordPress cron schedules
	 *
	 * @param array $schedules Existing cron schedules
	 * @return array Modified cron schedules
	 */
	public function add_minute_interval(array $schedules): array {
		$schedules['minute'] = [
			'interval' => 60,
			'display'  => __('Every Minute', 'mailniaga-smtp')
		];
		return $schedules;
	}

	/**
	 * Clean up completed and failed actions from the action scheduler table
	 *
	 * @return void
	 */
	public function cleanup_actions(): void {
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}actionscheduler_actions 
            WHERE status IN (%s, %s) 
            LIMIT %d",
			'complete',
			'failed',
			self::BATCH_SIZE
		));
	}

	/**
	 * Cleanup on deactivation
	 *
	 * @return void
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook(self::HOOK_NAME);
	}
}