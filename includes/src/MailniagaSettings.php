<?php

namespace Webimpian\MailniagaWPConnector;

class MailniagaSettings {
	private $settings;

	public function __construct() {
		$this->settings = get_option('mailniaga_wp_connector_settings', []);
		add_action('wp_ajax_generate_mailniaga_webhook', [$this, 'generate_webhook']);
		add_action('admin_bar_menu', [$this, 'add_credit_balance_to_admin_bar'], 100);
		add_action('admin_head', [$this, 'add_credit_balance_styles']);
	}

	public function register() {
		add_action('admin_menu', [$this, 'add_admin_menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

		add_action('wp_ajax_verify_mailniaga_api', [$this, 'verify_api']);
	}

	public function add_admin_menu() {
		$icon_url = MAILNIAGA_WP_CONNECTOR['URL'] . 'assets/images/mailniaga.png';

		add_menu_page(
			__('Mail Niaga SMTP', 'mailniaga-smtp'),
			__('Mail Niaga SMTP', 'mailniaga-smtp'),
			'manage_options',
			'mailniaga-smtp',
			[$this, 'render_admin_page'],
			$icon_url,
			100
		);
	}

	public function enqueue_admin_scripts($hook) {
		if ($hook !== 'toplevel_page_mailniaga-smtp') {
			return;
		}

		$base = trailingslashit(MAILNIAGA_WP_CONNECTOR['PATH']) . 'includes/src/assets/';

		wp_enqueue_style(
			'mailniaga-settings-page',
			MAILNIAGA_WP_CONNECTOR['URL'] . 'includes/src/assets/css/settings-page.css',
			[],
			(string) filemtime($base . 'css/settings-page.css')
		);

		wp_enqueue_script(
			'mailniaga-settings-page',
			MAILNIAGA_WP_CONNECTOR['URL'] . 'includes/src/assets/js/settings-page.js',
			['jquery'],
			(string) filemtime($base . 'js/settings-page.js'),
			true
		);

		wp_localize_script(
			'mailniaga-settings-page',
			'mailniaga_settings',
			[
				'nonce' => wp_create_nonce('mailniaga_generate_webhook'),
				'verify_nonce' => wp_create_nonce('mailniaga_verify_api')
			]
		);
	}

	public function register_settings() {
		register_setting(
			'mailniaga_wp_connector_settings',
			'mailniaga_wp_connector_settings',
			[
				'sanitize_callback' => [$this, 'sanitize_settings']
			]
		);

		add_settings_section(
			'mailniaga_wp_connector_main',
			__('Main Settings', 'mailniaga-smtp'),
			null,
			'mailniaga-smtp'
		);

		add_settings_section(
			'mailniaga_wp_connector_performance',
			__('Performance Settings', 'mailniaga-smtp'),
			[$this, 'render_performance_section_description'],
			'mailniaga-smtp'
		);

		$this->add_settings_fields();
	}

	private function add_settings_fields() {
		$main_fields = [
			'api_key' => __('Mail Niaga API Key', 'mailniaga-smtp'),
			'from_email' => __('Default From Email', 'mailniaga-smtp'),
			'from_name' => __('Default From Name', 'mailniaga-smtp'),
			'webhook' => __('Webhook (Funnelkit Only)', 'mailniaga-smtp'),
		];

		$performance_fields = [
			'concurrency' => __('Concurrency', 'mailniaga-smtp'),
			'batch_size' => __('Batch Size', 'mailniaga-smtp'),
		];

		foreach ($main_fields as $key => $label) {
			add_settings_field(
				'mailniaga_' . $key,
				$label,
				[$this, $key . '_callback'],
				'mailniaga-smtp',
				'mailniaga_wp_connector_main'
			);
		}

		foreach ($performance_fields as $key => $label) {
			add_settings_field(
				'mailniaga_' . $key,
				$label,
				[$this, $key . '_callback'],
				'mailniaga-smtp',
				'mailniaga_wp_connector_performance'
			);
		}
	}

	public function render_performance_section_description() {
		echo '<div class="notice notice-warning inline">';
		echo '<p>' . __('These control how fast your queued emails are sent. The limit is usually not your server, but Gmail, Yahoo and others slowing down senders who go too fast. If mail is being delayed or your server is struggling, lower Concurrency.', 'mailniaga-smtp') . '</p>';
		echo '</div>';
	}

	public function api_key_callback() {
		echo '<div class="mn-input-group">';
		$this->render_text_field('api_key', '', 'mn-input mn-input-mono');
		echo '<button type="button" id="verify-api" class="button">' . esc_html__('Verify API', 'mailniaga-smtp') . '</button>';
		echo '</div>';
		echo '<p id="api-status" class="mn-status" style="display: none;" role="status"></p>';
		echo '<div id="api-verification-results" class="mn-account" style="display: none;">
                <div id="api-details"></div>
              </div>';
	}

	public function from_email_callback() {
		$this->render_text_field('from_email', get_option('admin_email'));
	}

	public function from_name_callback() {
		$this->render_text_field('from_name', get_option('blogname'));
	}

	public function webhook_callback() {
		$this->render_webhook_field();
	}

	public function concurrency_callback() {
		$value = $this->settings['concurrency'] ?? 5;
		echo '<div class="mn-field mn-field-speed">';
		echo '<div class="mn-speed-head">';
		echo '<label class="mn-label" for="mn-field-concurrency">' . esc_html__('Concurrency', 'mailniaga-smtp') . '</label>';
		echo "<input type='number' id='mn-field-concurrency' name='mailniaga_wp_connector_settings[concurrency]' value='" . esc_attr($value) . "' class='mn-input mn-input-num' min='1' max='25'>";
		echo '</div>';
		echo '<p class="mn-hint">' . esc_html__('How many emails to send at once. This controls your sending speed.', 'mailniaga-smtp') . '</p>';
		echo '<ul class="mn-ranges">';
		echo '<li><span class="mn-dot mn-dot-slow"></span><strong>' . esc_html__('Slow and safe', 'mailniaga-smtp') . '</strong><em>' . esc_html__('shared hosting, new domain', 'mailniaga-smtp') . '</em><code>1&ndash;5</code></li>';
		echo '<li><span class="mn-dot mn-dot-normal"></span><strong>' . esc_html__('Normal', 'mailniaga-smtp') . '</strong><em>' . esc_html__('VPS, established domain', 'mailniaga-smtp') . '</em><code>5&ndash;10</code></li>';
		echo '<li><span class="mn-dot mn-dot-fast"></span><strong>' . esc_html__('Fast', 'mailniaga-smtp') . '</strong><em>' . esc_html__('strong server, trusted domain', 'mailniaga-smtp') . '</em><code>10&ndash;25</code></li>';
		echo '</ul>';
		echo '<p class="mn-hint">' . esc_html__('If bounces mention "deferred" or "unexpected volume", use a lower number.', 'mailniaga-smtp') . '</p>';
		echo '</div>';
	}

	public function batch_size_callback() {
		$value = $this->settings['batch_size'] ?? 50;
		echo '<div class="mn-field mn-field-speed">';
		echo '<div class="mn-speed-head">';
		echo '<label class="mn-label" for="mn-field-batch_size">' . esc_html__('Batch size', 'mailniaga-smtp') . '</label>';
		echo "<input type='number' id='mn-field-batch_size' name='mailniaga_wp_connector_settings[batch_size]' value='" . esc_attr($value) . "' class='mn-input mn-input-num' min='1' max='250'>";
		echo '</div>';
		echo '<p class="mn-hint">' . esc_html__('How many emails to read from the queue at once. Affects memory, not speed. Use less if your emails carry big attachments.', 'mailniaga-smtp') . '</p>';
		echo '</div>';
	}

	private function render_text_field($key, $default = '', $class = 'mn-input') {
		$value = $this->settings[$key] ?? $default;
		echo "<input type='text' id='mn-field-$key' name='mailniaga_wp_connector_settings[$key]' value='" . esc_attr($value) . "' class='" . esc_attr($class) . "'>";
	}

	private function render_webhook_field() {
		$webhook = $this->settings['webhook'] ?? '';
		$button_style = !empty($webhook) ? " style='display:none;'" : '';
		$callback_url = !empty($webhook)
			? add_query_arg('webhook', $webhook, site_url('/' . MAILNIAGA_WP_CONNECTOR['SLUG'] . '/callback'))
			: '';

		echo '<div class="mn-input-group">';
		echo "<input type='text' id='mailniaga_webhook' value='" . esc_attr($callback_url) . "' class='mn-input mn-input-mono' readonly placeholder='" . esc_attr__('Generate to create your callback URL', 'mailniaga-smtp') . "'>";
		echo "<button type='button' id='generate_webhook' class='button'$button_style>" . esc_html__('Generate', 'mailniaga-smtp') . '</button>';

		if (!empty($webhook)) {
			echo "<button type='button' id='copy_webhook_url' class='button'>" . esc_html__('Copy URL', 'mailniaga-smtp') . '</button>';
		}
		echo '</div>';
	}

	public function generate_webhook() {
		check_ajax_referer('mailniaga_generate_webhook', 'nonce');

		$webhook = $this->generate_random_webhook();
		$this->settings['webhook'] = $webhook;
		update_option('mailniaga_wp_connector_settings', $this->settings);

		$callback_url = add_query_arg('webhook', $webhook, site_url('/' . MAILNIAGA_WP_CONNECTOR['SLUG'] . '/callback'));

		wp_send_json_success([
			'webhook' => $webhook,
			'callback_url' => $callback_url,
		]);
	}

	private function generate_random_webhook() {
		return wp_generate_password(32, false);
	}

	public function sanitize_settings($input) {
		// Start from stored settings so a partial save never drops other keys.
		$sanitized = (array) get_option('mailniaga_wp_connector_settings', []);

		if (isset($input['api_key'])) {
			$sanitized['api_key'] = sanitize_text_field($input['api_key']);
		}
		if (isset($input['from_email'])) {
			$sanitized['from_email'] = sanitize_email($input['from_email']);
		}
		if (isset($input['from_name'])) {
			$sanitized['from_name'] = sanitize_text_field($input['from_name']);
		}
		if (isset($input['webhook'])) {
			$sanitized['webhook'] = sanitize_text_field($input['webhook']);
		}

		// Caps must match MailniagaEmailSender.
		if (isset($input['concurrency'])) {
			$concurrency = intval($input['concurrency']);
			$sanitized['concurrency'] = min(max($concurrency, 1), 25);
		}
		if (isset($input['batch_size'])) {
			$batch_size = intval($input['batch_size']);
			$sanitized['batch_size'] = min(max($batch_size, 1), 250);
		}

		return $sanitized;
	}

	public function verify_api() {
		check_ajax_referer('mailniaga_verify_api', 'nonce');

		$api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';

		if (empty($api_key)) {
			wp_send_json_error(['message' => 'API key is missing']);
		}

		$response = wp_remote_get('https://api.mailniaga.mx/api/v0/user', [
			'headers' => [
				'Content-Type' => 'application/json',
				'X-API-Key' => $api_key
			]
		]);

		if (is_wp_error($response)) {
			wp_send_json_error(['message' => 'Failed to connect to the API']);
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (isset($data['error']) && $data['error'] === false) {
			if (isset($data['data']['credit_balance'])) {
				update_option('mailniaga_balance', $data['data']['credit_balance']);
			}
			wp_send_json_success($data['data']);
		} else {
			wp_send_json_error(['message' => 'API verification failed']);
		}
	}

	private function queue_counts(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'mailniaga_email_queue';
		$counts = ['queued' => 0, 'sent_today' => 0];

		$counts['queued'] = (int) $wpdb->get_var(
			$wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", 'queued')
		);
		$counts['sent_today'] = (int) $wpdb->get_var(
			$wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s AND updated_at >= %s", 'sent', gmdate('Y-m-d 00:00:00'))
		);

		return $counts;
	}

	private function format_balance($balance) {
		if ($balance === false) {
			return '&mdash;';
		}

		// Same threshold the Mail Niaga gateway treats as unlimited.
		if ((float) $balance >= 999999999) {
			return '&#8734;';
		}

		return esc_html(number_format((float) $balance));
	}

	public function render_admin_page() {
		$balance = get_option('mailniaga_balance', false);
		$counts = $this->queue_counts();
		$logo = MAILNIAGA_WP_CONNECTOR['URL'] . 'assets/images/mailniaga-logo.png';
		?>
		<div class="wrap mn-wrap">
			<h1 class="screen-reader-text"><?php echo esc_html(get_admin_page_title()); ?></h1>
			<?php settings_errors(); ?>

			<header class="mn-hero">
				<div class="mn-hero-brand">
					<span class="mn-hero-mark"><img src="<?php echo esc_url($logo); ?>" alt="" width="36" height="36"></span>
					<div>
						<p class="mn-hero-title"><?php esc_html_e('Mail Niaga', 'mailniaga-smtp'); ?> <span class="mn-gold-word">SMTP</span>
							<span class="mn-chip">v<?php echo esc_html(MAILNIAGA_WP_CONNECTOR['VERSION']); ?></span></p>
						<p class="mn-hero-sub"><?php esc_html_e('Every email your site sends, delivered through Mail Niaga.', 'mailniaga-smtp'); ?></p>
					</div>
				</div>
				<div class="mn-hero-stats">
					<div class="mn-stat">
						<span class="mn-stat-value mn-stat-gold"><?php echo $this->format_balance($balance); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<span class="mn-stat-label"><?php esc_html_e('Credits', 'mailniaga-smtp'); ?></span>
					</div>
					<div class="mn-stat">
						<span class="mn-stat-value"><?php echo esc_html(number_format($counts['queued'])); ?></span>
						<span class="mn-stat-label"><?php esc_html_e('In queue', 'mailniaga-smtp'); ?></span>
					</div>
					<div class="mn-stat">
						<span class="mn-stat-value"><?php echo esc_html(number_format($counts['sent_today'])); ?></span>
						<span class="mn-stat-label"><?php esc_html_e('Sent today', 'mailniaga-smtp'); ?></span>
					</div>
				</div>
			</header>

			<div class="mn-grid">
				<form action="options.php" method="post" class="mn-main">
					<?php settings_fields('mailniaga_wp_connector_settings'); ?>

					<div class="mn-tabbed">
					<div class="mn-tabstrip">
					<div class="mn-tabs" role="tablist" aria-label="<?php esc_attr_e('Settings sections', 'mailniaga-smtp'); ?>">
						<span class="mn-tab-thumb" aria-hidden="true"></span>
						<button type="button" class="mn-tab is-active" role="tab" aria-selected="true" data-panel="account"><?php esc_html_e('Account', 'mailniaga-smtp'); ?></button>
						<button type="button" class="mn-tab" role="tab" aria-selected="false" data-panel="sender"><?php esc_html_e('FunnelKit sync', 'mailniaga-smtp'); ?></button>
						<button type="button" class="mn-tab" role="tab" aria-selected="false" data-panel="speed"><?php esc_html_e('Sending speed', 'mailniaga-smtp'); ?></button>
					</div>
					</div>

					<div class="mn-panel is-active" data-panel="account" role="tabpanel">
					<section class="mn-card">
						<header class="mn-card-head">
							<h2 class="mn-card-title"><?php esc_html_e('Connection', 'mailniaga-smtp'); ?></h2>
						</header>
						<div class="mn-field">
							<label class="mn-label" for="mn-field-api_key"><?php esc_html_e('API key', 'mailniaga-smtp'); ?></label>
							<p class="mn-hint mn-hint-top"><?php
								printf(
									/* translators: %s: link to the Mail Niaga dashboard. */
									esc_html__('Find your key in the %s.', 'mailniaga-smtp'),
									'<a href="https://gateway.mailniaga.com" target="_blank" rel="noopener">' . esc_html__('Mail Niaga dashboard', 'mailniaga-smtp') . '</a>'
								);
							?></p>
							<?php $this->api_key_callback(); ?>
						</div>
					</section>

					<section class="mn-card">
						<header class="mn-card-head">
							<h2 class="mn-card-title"><?php esc_html_e('Sender identity', 'mailniaga-smtp'); ?></h2>
						</header>
						<div class="mn-field-row">
							<div class="mn-field">
								<label class="mn-label" for="mn-field-from_email"><?php esc_html_e('From email', 'mailniaga-smtp'); ?></label>
								<?php $this->from_email_callback(); ?>
							</div>
							<div class="mn-field">
								<label class="mn-label" for="mn-field-from_name"><?php esc_html_e('From name', 'mailniaga-smtp'); ?></label>
								<?php $this->from_name_callback(); ?>
							</div>
						</div>
						<p class="mn-hint"><?php esc_html_e('The name and email your emails are sent from. Plugins like WooCommerce may use their own sender instead.', 'mailniaga-smtp'); ?></p>
					</section>
					</div>

					<div class="mn-panel" data-panel="sender" role="tabpanel">
					<section class="mn-card">
						<header class="mn-card-head">
							<h2 class="mn-card-title"><?php esc_html_e('FunnelKit sync', 'mailniaga-smtp'); ?></h2>
							<p class="mn-card-note"><?php
								printf(
									/* translators: %s: the FunnelKit plugin name. */
									esc_html__('For %s users: undeliverable addresses are unsubscribed automatically. Failed emails are listed under Failed Deliveries.', 'mailniaga-smtp'),
									'<strong>FunnelKit</strong>'
								);
							?></p>
						</header>
						<?php $this->render_webhook_field(); ?>
						<div class="mn-tip">
							<strong><?php esc_html_e('Tip:', 'mailniaga-smtp'); ?></strong>
							<?php
							printf(
								/* translators: %s: link to the Mail Niaga dashboard. */
								esc_html__('Enable "Notify on failures only" in your %s. It keeps your site fast during large sends.', 'mailniaga-smtp'),
								'<a href="https://gateway.mailniaga.mx/smtp-accounts" class="mn-gateway-edit" target="_blank" rel="noopener">' . esc_html__('Mail Niaga account', 'mailniaga-smtp') . '</a>'
							);
							?>
						</div>
					</section>
					</div>

					<div class="mn-panel" data-panel="speed" role="tabpanel">
					<section class="mn-card">
						<header class="mn-card-head">
							<h2 class="mn-card-title"><?php esc_html_e('Sending speed', 'mailniaga-smtp'); ?></h2>
							<p class="mn-card-note"><?php esc_html_e('The usual limit is not your server: Gmail and Yahoo slow down senders who go too fast.', 'mailniaga-smtp'); ?></p>
						</header>
						<?php $this->concurrency_callback(); ?>
						<?php $this->batch_size_callback(); ?>
					</section>


					</div>
					</div>

					<footer class="mn-save">
						<?php submit_button(__('Save settings', 'mailniaga-smtp'), 'primary', 'submit', false); ?>
					</footer>
				</form>

				<aside class="mn-side">
					<section class="mn-card">
						<header class="mn-card-head">
							<h2 class="mn-card-title"><?php esc_html_e('Send a test email', 'mailniaga-smtp'); ?></h2>
						</header>
						<form id="mn-test-email-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
							<input type="hidden" name="action" value="mailniaga_send_test_email">
							<?php wp_nonce_field('mailniaga_test_email', 'mailniaga_test_email_nonce'); ?>
							<div class="mn-field">
								<label class="mn-label" for="test_email"><?php esc_html_e('Recipient', 'mailniaga-smtp'); ?></label>
								<input type="email" id="test_email" name="test_email" class="mn-input" value="<?php echo esc_attr(get_option('admin_email')); ?>" required>
							</div>
							<?php submit_button(__('Send test email', 'mailniaga-smtp'), 'secondary', 'send_test_email', false); ?>
							<p id="test-email-status" class="mn-status" style="display: none;" role="status"></p>
						</form>
					</section>


					<section class="mn-card">
						<header class="mn-card-head">
							<h2 class="mn-card-title"><?php esc_html_e('Reaching the inbox', 'mailniaga-smtp'); ?></h2>
						</header>
						<ul class="mn-tips">
							<li><?php esc_html_e('If you send too fast, Gmail and Yahoo will delay your mail. Delayed emails retry automatically.', 'mailniaga-smtp'); ?></li>
							<li><?php esc_html_e('Spread big campaigns over a few hours. They get delivered sooner than one big burst.', 'mailniaga-smtp'); ?></li>
							<li><?php esc_html_e('Set up SPF, DKIM and DMARC in the Mail Niaga dashboard. They prove your emails really come from you.', 'mailniaga-smtp'); ?></li>
							<li><?php esc_html_e('Remove addresses that keep failing. Sending to dead mailboxes makes you look like a spammer.', 'mailniaga-smtp'); ?></li>
							<li><?php esc_html_e('New domain? Start with small sends and grow slowly over the first weeks.', 'mailniaga-smtp'); ?></li>
						</ul>
					</section>

					<section class="mn-card mn-card-quiet">
						<header class="mn-card-head">
							<h2 class="mn-card-title"><?php esc_html_e('Resources', 'mailniaga-smtp'); ?></h2>
						</header>
						<ul class="mn-links">
							<li><a href="https://api.webimpian.support/mailniaga" target="_blank" rel="noopener"><?php esc_html_e('API documentation', 'mailniaga-smtp'); ?></a></li>
							<li><a href="https://gateway.mailniaga.com" target="_blank" rel="noopener"><?php esc_html_e('Manage your account', 'mailniaga-smtp'); ?></a></li>
							<li><a href="mailto:support@mailniaga.com">support@mailniaga.com</a></li>
						</ul>
					</section>
				</aside>
			</div>
		</div>
		<?php
	}

	public function add_credit_balance_to_admin_bar($admin_bar) {
		$credit_balance = get_option('mailniaga_balance', false);

		if ($credit_balance !== false) {
			$display = (float) $credit_balance >= 999999999
				? '∞'
				: number_format((float) $credit_balance);

			$admin_bar->add_menu([
				'id'    => 'mailniaga-credit-balance',
				'title' => 'Mail Niaga Balance: ' . $display,
				'href'  => admin_url('admin.php?page=mailniaga-smtp'),
				'meta'  => [
					'title' => __('Mail Niaga Credit Balance', 'mailniaga-smtp'),
					'class' => 'mailniaga-credit-balance-item'
				],
			]);
		}
	}

	public function add_credit_balance_styles() {
		echo '<style>
            #wpadminbar .mailniaga-credit-balance-item > .ab-item {
                background: #f5b70a !important;
                color: #1d2327 !important;
                font-weight: 600;
            }
            #wpadminbar .mailniaga-credit-balance-item:hover > .ab-item,
            #wpadminbar .mailniaga-credit-balance-item > .ab-item:focus {
                background: #ffc72c !important;
                color: #1d2327 !important;
            }
        </style>';
	}

	public function get_settings() {
		return $this->settings;
	}
}