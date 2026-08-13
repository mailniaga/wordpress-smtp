<?php

namespace Webimpian\MailniagaWPConnector;

/**
 * Offers the optional must-use plugin that answers delivery callbacks before the
 * other plugins load. Offered rather than installed silently, since not every
 * host allows writing there.
 */
class MailniagaDropin {
	private const FILENAME = 'mailniaga-webhook.php';
	private const DISMISS_OPTION = 'mailniaga_dropin_dismissed';

	public function register(): void {
		add_action('admin_notices', [$this, 'render_notice']);
		add_action('admin_post_mailniaga_install_dropin', [$this, 'handle_install']);
		add_action('admin_post_mailniaga_dismiss_dropin', [$this, 'handle_dismiss']);

		if (defined('WP_CLI') && WP_CLI) {
			\WP_CLI::add_command('mailniaga dropin', [__CLASS__, 'cli']);
		}
	}

	/**
	 * Enable, disable or check the delivery handler.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : enable, disable or status.
	 *
	 * @param array $args Positional arguments.
	 */
	public static function cli(array $args): void {
		$action = $args[0] ?? 'status';

		if ($action === 'status') {
			\WP_CLI::log(self::is_current() ? 'enabled (current)' : (self::is_installed() ? 'enabled (outdated)' : 'not enabled'));
			return;
		}

		if ($action === 'disable') {
			self::uninstall()
				? \WP_CLI::success('Delivery handler removed.')
				: \WP_CLI::error('Could not remove the delivery handler.');
			return;
		}

		if ($action !== 'enable') {
			\WP_CLI::error('Use: enable, disable or status.');
		}

		$result = self::install();

		$result === true
			? \WP_CLI::success('Delivery handler enabled at ' . self::target())
			: \WP_CLI::error($result);
	}

	public static function target(): string {
		return WPMU_PLUGIN_DIR . '/' . self::FILENAME;
	}

	public static function source(): string {
		return MAILNIAGA_WP_CONNECTOR['PATH'] . '/includes/dropin/' . self::FILENAME;
	}

	public static function is_installed(): bool {
		return file_exists(self::target());
	}

	public static function is_current(): bool {
		if (!self::is_installed()) {
			return false;
		}

		return md5_file(self::target()) === md5_file(self::source());
	}

	/** @return true|string True on success, otherwise the reason it failed. */
	public static function install() {
		if (!file_exists(self::source())) {
			return __('The handler file is missing from the plugin.', 'mailniaga-smtp');
		}

		if (!is_dir(WPMU_PLUGIN_DIR) && !wp_mkdir_p(WPMU_PLUGIN_DIR)) {
			return __('The mu-plugins folder could not be created.', 'mailniaga-smtp');
		}

		if (!is_writable(WPMU_PLUGIN_DIR)) {
			return __('The mu-plugins folder is not writable on this host.', 'mailniaga-smtp');
		}

		if (!copy(self::source(), self::target())) {
			return __('The handler file could not be copied.', 'mailniaga-smtp');
		}

		return true;
	}

	public static function uninstall(): bool {
		return self::is_installed() ? unlink(self::target()) : true;
	}

	public function render_notice(): void {
		if (!current_user_can('manage_options') || self::is_current() || get_option(self::DISMISS_OPTION)) {
			return;
		}

		$updating = self::is_installed();

		$title = $updating
			? __('Update available for faster delivery reports', 'mailniaga-smtp')
			: __('Speed up delivery reports', 'mailniaga-smtp');

		$text = $updating
			? __('A newer version of the delivery report handler is ready to install.', 'mailniaga-smtp')
			: __('Mail Niaga sends a report back each time an email is delivered. Handling these before your other plugins load keeps your site responsive during large sends.', 'mailniaga-smtp');

		MailniagaNotice::render($title, $text, function () use ($updating) {
			MailniagaNotice::button('mailniaga_install_dropin', $updating ? __('Update now', 'mailniaga-smtp') : __('Enable', 'mailniaga-smtp'), 'button button-primary');
			MailniagaNotice::button('mailniaga_dismiss_dropin', __('No thanks', 'mailniaga-smtp'), 'button');
		});
	}


	public function handle_install(): void {
		$this->guard('mailniaga_install_dropin');

		$result = self::install();

		wp_safe_redirect(add_query_arg(
			'mailniaga_dropin',
			$result === true ? 'installed' : rawurlencode($result),
			wp_get_referer() ?: admin_url()
		));
		exit;
	}

	public function handle_dismiss(): void {
		$this->guard('mailniaga_dismiss_dropin');

		update_option(self::DISMISS_OPTION, 1, false);

		wp_safe_redirect(wp_get_referer() ?: admin_url());
		exit;
	}

	private function guard(string $action): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to do this.', 'mailniaga-smtp'));
		}

		check_admin_referer($action);
	}

}
