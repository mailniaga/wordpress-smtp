<?php

namespace Webimpian\MailniagaWPConnector;

class MailniagaConnector {
	private static ?MailniagaConnector $instance = null;
	private MailniagaSettings $settings;
	private MailniagaEmailSender $email_sender;
	private MailniagaEmailLog $email_log;
	private WebhookHandler $webhook_handler;
	private MailniagaUnsubscribeFunnelKit $unsubscribe_cron;
	private MailniagaFailedDeliveriesLog $failed_deliveries_log;
	private MailniagaEmailLogCleaner $email_log_cleaner;
	private MailniagaCheckBalance $check_balance;
	private MailniagaActionSchedulerCleaner $action_scheduler_cleaner;



	private function __construct() {
		$this->settings = new MailniagaSettings();
		$this->email_sender = new MailniagaEmailSender($this->settings->get_settings());
		$this->email_log = new MailniagaEmailLog();
		$this->webhook_handler = new WebhookHandler($this->settings);
		$this->unsubscribe_cron = new MailniagaUnsubscribeFunnelKit();
		$this->failed_deliveries_log = new MailniagaFailedDeliveriesLog();
		$this->email_log_cleaner = new MailniagaEmailLogCleaner();
		$this->check_balance = new MailniagaCheckBalance($this->settings);
		$this->action_scheduler_cleaner = new MailniagaActionSchedulerCleaner();
	}

	public static function get_instance(): ?MailniagaConnector {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		add_action('init', [$this, 'init']);

		$this->settings->register();
		$this->email_sender->register();
		$this->email_log->register();
		$this->unsubscribe_cron->register();
		$this->failed_deliveries_log->register();
		$this->email_log_cleaner->register();
		$this->check_balance->register();
		$this->action_scheduler_cleaner->register();

		add_action('admin_post_mailniaga_send_test_email', [$this, 'handle_test_email']);
		add_action('admin_notices', [$this, 'display_test_email_result']);

		// Add AJAX action for email details
		add_action('wp_ajax_mailniaga_get_email_details', [$this->email_log, 'get_email_details']);
	}

	public function init() {
		load_plugin_textdomain('mailniaga-smtp', false, dirname(MAILNIAGA_WP_CONNECTOR['HOOK']) . '/languages/');
	}

	public function handle_test_email() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}

		check_admin_referer('mailniaga_test_email', 'mailniaga_test_email_nonce');

		$to = sanitize_email($_POST['test_email']);
		$result = $this->email_sender->send_test_email($to);

		$this->save_test_email_result($result);

		wp_redirect(add_query_arg('mailniaga_test_email', '1', admin_url('admin.php?page=mailniaga-smtp')));
		exit;
	}

	private function save_test_email_result($result) {
		$status_class = $result['success'] ? 'notice-success' : 'notice-error';
		$status_message = $result['success'] ? __('Test email sent successfully!', 'mailniaga-smtp') : __('Failed to send test email.', 'mailniaga-smtp');

		set_transient('mailniaga_test_email_result', [
			'status_class' => $status_class,
			'status_message' => $status_message,
			'time_taken' => $result['time_taken'],
			'error_message' => $result['error_message'],
		], 60);
	}

	public function display_test_email_result() {
		if (!isset($_GET['page']) || $_GET['page'] !== 'mailniaga-smtp' || !isset($_GET['mailniaga_test_email'])) {
			return;
		}

		$result = get_transient('mailniaga_test_email_result');
		if (!$result) {
			return;
		}

		delete_transient('mailniaga_test_email_result');

		?>
        <div class="notice <?php echo esc_attr($result['status_class']); ?> is-dismissible">
            <p><strong><?php echo esc_html($result['status_message']); ?></strong></p>
            <p><?php echo esc_html(sprintf(__('Time taken: %s seconds', 'mailniaga-smtp'), $result['time_taken'])); ?></p>
			<?php if (!empty($result['error_message'])): ?>
                <p><?php echo esc_html(__('Error details:', 'mailniaga-smtp') . ' ' . $result['error_message']); ?></p>
			<?php endif; ?>
        </div>
		<?php
	}
}