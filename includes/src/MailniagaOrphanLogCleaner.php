<?php

namespace Webimpian\MailniagaWPConnector;

/** Clears leftover scheduler log rows daily, in the background, when the server is idle. */
class MailniagaOrphanLogCleaner {
	private const CHECK_HOOK     = 'mailniaga_orphan_check';
	private const PURGE_HOOK     = 'mailniaga_purge_orphan_logs';
	private const DISMISS_OPTION = 'mailniaga_orphan_notice_dismissed';
	private const RUNNING_OPTION = 'mailniaga_orphan_purge_running';
	private const BATCH_SIZE     = 5000;
	private const BATCH_DELAY    = 60;
	private const BUSY_DELAY     = 300;
	private const MIN_ROWS       = 100000;
	private const MIN_RATIO      = 10;

	public function register(): void {
		add_action(self::CHECK_HOOK, [$this, 'check']);
		add_action(self::PURGE_HOOK, [$this, 'purge_batch']);
		add_action('init', [$this, 'schedule_check']);
		add_action('admin_notices', [$this, 'render_notice']);
		add_action('admin_post_mailniaga_purge_orphans', [$this, 'handle_start']);
		add_action('admin_post_mailniaga_dismiss_orphans', [$this, 'handle_dismiss']);

		if (defined('WP_CLI') && WP_CLI) {
			\WP_CLI::add_command('mailniaga cleanup', [$this, 'cli']);
		}
	}

	public function schedule_check(): void {
		if (!function_exists('as_next_scheduled_action')) {
			return;
		}

		if (!as_next_scheduled_action(self::CHECK_HOOK)) {
			as_schedule_recurring_action(time() + 300, DAY_IN_SECONDS, self::CHECK_HOOK);
		}
	}

	public function check(): void {
		if (!$this->needs_cleanup()) {
			delete_option(self::RUNNING_OPTION);
			return;
		}

		$this->start();
	}

	public function purge_batch(): void {
		// Busy server: come back later without deleting anything.
		if (!self::load_ok(function_exists('sys_getloadavg') ? sys_getloadavg() : null, $this->load_limit())) {
			as_schedule_single_action(time() + self::BUSY_DELAY, self::PURGE_HOOK);
			return;
		}

		if ($this->delete_batch() > 0) {
			as_schedule_single_action(time() + self::BATCH_DELAY, self::PURGE_HOOK);
			return;
		}

		delete_option(self::RUNNING_OPTION);
	}

	/** True when the 1-minute load average is under the limit, or load is unknown. */
	public static function load_ok(?array $load, float $limit): bool {
		if (!is_array($load) || !isset($load[0])) {
			return true;
		}

		return (float) $load[0] < $limit;
	}

	/**
	 * Check or clear old scheduler records.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : run or status.
	 *
	 * @param array $args Positional arguments.
	 */
	public function cli(array $args): void {
		$action = $args[0] ?? 'status';

		if ($action === 'status') {
			\WP_CLI::log(sprintf('orphaned rows (estimate): %s', number_format($this->estimated_orphans())));
			\WP_CLI::log('background purge: ' . (get_option(self::RUNNING_OPTION) ? 'running' : 'not running'));
			return;
		}

		if ($action !== 'run') {
			\WP_CLI::error('Use: run or status.');
		}

		$total = 0;

		while (true) {
			if (!self::load_ok(function_exists('sys_getloadavg') ? sys_getloadavg() : null, $this->load_limit())) {
				\WP_CLI::warning('Server busy, waiting 30s...');
				sleep(30);
				continue;
			}

			$deleted = $this->delete_batch();

			if (!$deleted) {
				break;
			}

			$total += $deleted;

			if ($total % 50000 === 0) {
				\WP_CLI::log(number_format($total) . ' rows removed...');
			}

			sleep(1);
		}

		delete_option(self::RUNNING_OPTION);
		\WP_CLI::success(number_format($total) . ' orphaned rows removed.');
	}

	public function render_notice(): void {
		if (!current_user_can('manage_options')) {
			return;
		}

		if (get_option(self::RUNNING_OPTION)) {
			MailniagaNotice::render(
				__('Cleaning up in the background', 'mailniaga-smtp'),
				__('Mail Niaga is clearing old scheduler records. This message disappears once it finishes.', 'mailniaga-smtp'),
				null,
				'busy'
			);
			return;
		}

		if (get_option(self::DISMISS_OPTION) || !$this->needs_cleanup()) {
			return;
		}

		$message = sprintf(
			/* translators: %s: approximate number of database rows. */
			__('Mail Niaga found roughly %s old scheduler records. Cleanup starts automatically within a day, or you can start it now.', 'mailniaga-smtp'),
			number_format_i18n($this->estimated_orphans())
		);

		MailniagaNotice::render(
			__('Free up database space', 'mailniaga-smtp'),
			$message,
			function () {
				MailniagaNotice::button('mailniaga_purge_orphans', __('Clean up now', 'mailniaga-smtp'), 'button button-primary');
				MailniagaNotice::button('mailniaga_dismiss_orphans', __('No thanks', 'mailniaga-smtp'), 'button');
			}
		);
	}

	public function handle_start(): void {
		$this->guard('mailniaga_purge_orphans');
		$this->start();
		wp_safe_redirect(wp_get_referer() ?: admin_url());
		exit;
	}

	public function handle_dismiss(): void {
		$this->guard('mailniaga_dismiss_orphans');
		update_option(self::DISMISS_OPTION, 1, false);
		wp_safe_redirect(wp_get_referer() ?: admin_url());
		exit;
	}

	private function start(): void {
		if (!function_exists('as_schedule_single_action') || !function_exists('as_get_scheduled_actions')) {
			return;
		}

		// Pending only; the running batch must not block its successor.
		$pending = as_get_scheduled_actions([
			'hook' => self::PURGE_HOOK,
			'status' => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 1,
		], 'ids');

		if (!empty($pending)) {
			return;
		}

		update_option(self::RUNNING_OPTION, 1, false);
		as_schedule_single_action(time(), self::PURGE_HOOK);
	}

	private function delete_batch(): int {
		global $wpdb;

		return (int) $wpdb->query($wpdb->prepare(
			"DELETE l FROM {$wpdb->prefix}actionscheduler_logs l
			 LEFT JOIN {$wpdb->prefix}actionscheduler_actions a ON a.action_id = l.action_id
			 WHERE a.action_id IS NULL
			 LIMIT %d",
			self::BATCH_SIZE
		));
	}

	private function load_limit(): float {
		return (float) apply_filters('mailniaga_purge_load_limit', 4.0);
	}

	/** Compares table sizes rather than counting, which would itself be costly. */
	private function needs_cleanup(): bool {
		$logs = $this->table_rows('actionscheduler_logs');
		$actions = $this->table_rows('actionscheduler_actions');

		if ($logs < self::MIN_ROWS) {
			return false;
		}

		return $logs > max(1, $actions) * self::MIN_RATIO;
	}

	private function estimated_orphans(): int {
		return max(0, $this->table_rows('actionscheduler_logs') - $this->table_rows('actionscheduler_actions'));
	}

	private function table_rows(string $table): int {
		global $wpdb;

		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT table_rows FROM information_schema.tables
			 WHERE table_schema = DATABASE() AND table_name = %s",
			$wpdb->prefix . $table
		));
	}

	private function guard(string $action): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to do this.', 'mailniaga-smtp'));
		}

		check_admin_referer($action);
	}
}
