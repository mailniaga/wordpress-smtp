<?php

namespace Webimpian\MailniagaWPConnector;

/**
 * Brings an existing install up to the current schema and schedule.
 * One step per request, so a slow ALTER resumes rather than breaks.
 */
class MailniagaUpgrader {
	private const VERSION_OPTION  = 'mailniaga_db_version';
	private const DONE_OPTION     = 'mailniaga_completed_upgrades';
	private const PREPARED_OPTION = 'mailniaga_prepared';
	private const LOCK_TRANSIENT  = 'mailniaga_upgrading';
	private const LOCK_SECONDS    = 60;
	private const UPGRADE_HOOK    = 'mailniaga_run_upgrade';

	public function register(): void {
		// After Action Scheduler's own init at priority 1, before schedule_actions().
		add_action('init', [$this, 'maybe_prepare'], 2);
		add_action('admin_init', [$this, 'maybe_upgrade']);
		add_action(self::UPGRADE_HOOK, [$this, 'run_scheduled_upgrade']);
	}

	/** The two changes sending depends on, so they cannot wait for an admin. */
	public function maybe_prepare(): void {
		global $wpdb;

		if (get_option(self::PREPARED_OPTION) === MAILNIAGA_WP_CONNECTOR['VERSION']) {
			return;
		}

		$this->add_column($wpdb->prefix . 'mailniaga_email_queue', 'attempts', 'tinyint(3) unsigned NOT NULL DEFAULT 0');

		// Unscheduling does nothing before the data store is ready, so retry later.
		if (!class_exists('ActionScheduler') || !\ActionScheduler::is_initialized()) {
			return;
		}

		$this->reset_schedules();
		$this->queue_upgrade();

		update_option(self::PREPARED_OPTION, MAILNIAGA_WP_CONNECTOR['VERSION'], false);
	}

	/** An admin browsing wp-admin moves the upgrade along faster. */
	public function maybe_upgrade(): void {
		if (wp_doing_ajax() || wp_doing_cron() || !current_user_can('activate_plugins')) {
			return;
		}

		$this->advance();
	}

	/** Runs without wp-admin, so the upgrade finishes even if nobody signs in. */
	public function run_scheduled_upgrade(): void {
		$this->advance();
		$this->queue_upgrade();
	}

	private function queue_upgrade(): void {
		if (get_option(self::VERSION_OPTION) === MAILNIAGA_WP_CONNECTOR['VERSION']) {
			return;
		}

		if (!function_exists('as_schedule_single_action') || as_next_scheduled_action(self::UPGRADE_HOOK)) {
			return;
		}

		as_schedule_single_action(time() + 60, self::UPGRADE_HOOK);
	}

	private function advance(): void {
		if (get_option(self::VERSION_OPTION) === MAILNIAGA_WP_CONNECTOR['VERSION']) {
			return;
		}

		if (get_transient(self::LOCK_TRANSIENT)) {
			return;
		}

		set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_SECONDS);

		try {
			$this->run_next_step();
		} finally {
			delete_transient(self::LOCK_TRANSIENT);
		}
	}

	private function run_next_step(): void {
		$done = (array) get_option(self::DONE_OPTION, []);

		foreach ($this->steps() as $name => $step) {
			if (in_array($name, $done, true)) {
				continue;
			}

			$step();

			$done[] = $name;
			update_option(self::DONE_OPTION, $done, false);

			return;
		}

		update_option(self::VERSION_OPTION, MAILNIAGA_WP_CONNECTOR['VERSION'], false);
	}

	/** @return callable[] Keys must never be reused once shipped. */
	private function steps(): array {
		global $wpdb;

		$queue  = $wpdb->prefix . 'mailniaga_email_queue';
		$failed = $wpdb->prefix . 'mailniaga_failed_deliveries';

		// Indexes only; the attempts column is in maybe_prepare().
		return [
			'224_queue_status_created' => function () use ($queue) {
				$this->add_index($queue, 'status_created', '(status, created_at)');
			},
			'224_queue_status_updated' => function () use ($queue) {
				$this->add_index($queue, 'status_updated', '(status, updated_at)');
			},
			'224_queue_created_at' => function () use ($queue) {
				$this->add_index($queue, 'created_at', '(created_at)');
			},
			'224_failed_to_email' => function () use ($failed) {
				$this->add_index($failed, 'to_email', '(to_email)');
			},
			'224_scale_concurrency' => function () {
				$this->scale_concurrency();
			},
		];
	}

	/** Old recurring actions must go, or schedule_actions() keeps using them. */
	private function reset_schedules(): void {
		if (!function_exists('as_unschedule_all_actions')) {
			return;
		}

		foreach (['mailniaga_process_queue', 'mailniaga_recover_stale', 'mailniaga_retry_failed'] as $hook) {
			as_unschedule_all_actions($hook);
		}

		// Removed in 2.2.4; its raw DELETE orphaned Action Scheduler logs.
		$timestamp = wp_next_scheduled('mailniaga_cleanup_action_scheduler');
		if ($timestamp) {
			wp_unschedule_event($timestamp, 'mailniaga_cleanup_action_scheduler');
		}
	}

	/** The same number now sends more per minute, so bring older values down. */
	private function scale_concurrency(): void {
		global $wpdb;

		$option = 'mailniaga_wp_connector_settings';

		$settings = maybe_unserialize($wpdb->get_var(
			$wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option)
		));

		if (!is_array($settings) || !isset($settings['concurrency'])) {
			return;
		}

		if ((int) $settings['concurrency'] <= 10) {
			return;
		}

		$settings['concurrency'] = 10;

		// Written directly so a filter on the option cannot mask the change.
		$wpdb->update(
			$wpdb->options,
			['option_value' => maybe_serialize($settings)],
			['option_name' => $option]
		);

		wp_cache_delete($option, 'options');
		wp_cache_delete('alloptions', 'options');
	}

	private function add_column(string $table, string $column, string $definition): void {
		if ($this->has_column($table, $column)) {
			return;
		}

		$this->alter($table, "ADD COLUMN `{$column}` {$definition}");
	}

	private function add_index(string $table, string $name, string $columns): void {
		if ($this->has_index($table, $name)) {
			return;
		}

		$this->alter($table, "ADD INDEX `{$name}` {$columns}");
	}

	private function alter(string $table, string $change): void {
		global $wpdb;

		$wpdb->query("ALTER TABLE `{$table}` {$change}, ALGORITHM=INPLACE, LOCK=NONE");

		if ($wpdb->last_error) {
			$wpdb->query("ALTER TABLE `{$table}` {$change}");
		}
	}

	private function has_column(string $table, string $column): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column)
		);
	}

	private function has_index(string $table, string $name): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $name)
		);
	}
}
