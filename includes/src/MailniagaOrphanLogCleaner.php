<?php

namespace Webimpian\MailniagaWPConnector;

/**
 * Clears Action Scheduler log rows left behind by the cleaner removed in 2.2.4,
 * which deleted actions without their logs. Offered, not forced.
 */
class MailniagaOrphanLogCleaner {
	private const HOOK            = 'mailniaga_purge_orphan_logs';
	private const DISMISS_OPTION  = 'mailniaga_orphan_notice_dismissed';
	private const RUNNING_OPTION  = 'mailniaga_orphan_purge_running';
	private const BATCH_SIZE      = 5000;
	private const MIN_ROWS        = 100000;
	private const MIN_RATIO       = 10;

	public function register(): void {
		add_action(self::HOOK, [$this, 'purge_batch']);
		add_action('admin_notices', [$this, 'render_notice']);
		add_action('admin_post_mailniaga_purge_orphans', [$this, 'handle_start']);
		add_action('admin_post_mailniaga_dismiss_orphans', [$this, 'handle_dismiss']);
	}

	public function render_notice(): void {
		if (!current_user_can('manage_options') || get_option(self::DISMISS_OPTION)) {
			return;
		}

		if (get_option(self::RUNNING_OPTION)) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html__('Mail Niaga is clearing orphaned scheduler logs in the background. This page will stop showing once it finishes.', 'mailniaga-smtp')
			);
			return;
		}

		if (!$this->needs_cleanup()) {
			return;
		}

		$message = sprintf(
			/* translators: %s: approximate number of database rows. */
			__('Mail Niaga found roughly %s orphaned scheduler log rows left by an earlier version. Clearing them will free database space and speed up queries.', 'mailniaga-smtp'),
			number_format_i18n($this->estimated_orphans())
		);

		echo '<div class="notice notice-warning"><p>' . esc_html($message) . '</p><p>';
		$this->render_button('mailniaga_purge_orphans', __('Clean up now', 'mailniaga-smtp'), 'button button-primary');
		echo ' ';
		$this->render_button('mailniaga_dismiss_orphans', __('Dismiss', 'mailniaga-smtp'), 'button');
		echo '</p></div>';
	}

	public function handle_start(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to do this.', 'mailniaga-smtp'));
		}

		check_admin_referer('mailniaga_purge_orphans');

		if (function_exists('as_schedule_single_action')) {
			update_option(self::RUNNING_OPTION, 1, false);
			as_schedule_single_action(time(), self::HOOK);
		}

		wp_safe_redirect(wp_get_referer() ?: admin_url());
		exit;
	}

	public function handle_dismiss(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to do this.', 'mailniaga-smtp'));
		}

		check_admin_referer('mailniaga_dismiss_orphans');

		update_option(self::DISMISS_OPTION, 1, false);

		wp_safe_redirect(wp_get_referer() ?: admin_url());
		exit;
	}

	public function purge_batch(): void {
		global $wpdb;

		$logs = $wpdb->prefix . 'actionscheduler_logs';
		$actions = $wpdb->prefix . 'actionscheduler_actions';

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE l FROM {$logs} l
				 LEFT JOIN {$actions} a ON a.action_id = l.action_id
				 WHERE a.action_id IS NULL
				 LIMIT %d",
				self::BATCH_SIZE
			)
		);

		if ($deleted > 0 && function_exists('as_schedule_single_action')) {
			as_schedule_single_action(time() + 10, self::HOOK);
			return;
		}

		delete_option(self::RUNNING_OPTION);
		update_option(self::DISMISS_OPTION, 1, false);
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

		$rows = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT table_rows FROM information_schema.tables
				 WHERE table_schema = DATABASE() AND table_name = %s",
				$wpdb->prefix . $table
			)
		);

		return (int) $rows;
	}

	private function render_button(string $action, string $label, string $class): void {
		printf(
			'<form method="post" action="%s" style="display:inline">%s<input type="hidden" name="action" value="%s"><button type="submit" class="%s">%s</button></form>',
			esc_url(admin_url('admin-post.php')),
			wp_nonce_field($action, '_wpnonce', true, false),
			esc_attr($action),
			esc_attr($class),
			esc_html($label)
		);
	}
}
