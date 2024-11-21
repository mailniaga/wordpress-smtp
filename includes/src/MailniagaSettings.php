<?php

namespace Webimpian\MailniagaWPConnector;

class MailniagaSettings {
	private $settings;

	public function __construct() {
		$this->settings = get_option('mailniaga_wp_connector_settings', []);
		add_action('wp_ajax_generate_mailniaga_webhook', [$this, 'generate_webhook']);
		add_action('admin_bar_menu', [$this, 'add_credit_balance_to_admin_bar'], 100);
		add_action('admin_head', [$this, 'add_credit_balance_styles']);
		add_action('admin_head', [$this, 'add_admin_styles']);
	}

	public function register() {
		add_action('admin_menu', [$this, 'add_admin_menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

		add_action('wp_ajax_verify_mailniaga_api', [$this, 'verify_api']);
	}

	public function add_admin_menu() {
		$icon_url = 'https://demo.dev-aplikasiniaga.com/wp-content/plugins/elementor-mailniaga/mailniaga.png';

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

		wp_enqueue_style(
			'mailniaga-settings-page',
			MAILNIAGA_WP_CONNECTOR['URL'] . 'includes/src/assets/css/settings-page.css',
			[],
			MAILNIAGA_WP_CONNECTOR['VERSION']
		);

		wp_enqueue_script(
			'mailniaga-settings-page',
			MAILNIAGA_WP_CONNECTOR['URL'] . 'includes/src/assets/js/settings-page.js',
			['jquery'],
			MAILNIAGA_WP_CONNECTOR['VERSION'],
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
		echo '<p>' . __('These settings control how emails are processed and sent. Higher values can speed up email sending but may use more server resources. If you experience server issues, try lowering these values.', 'mailniaga-smtp') . '</p>';
		echo '</div>';
	}

	public function api_key_callback() {
		$this->render_text_field('api_key');
		echo '<button id="verify-api" class="button button-secondary">' . __('Verify', 'mailniaga-smtp') . '</button>';
		echo '<div id="api-verification-results" style="display: none;">
                <h4>' . __('Mail Niaga Account Details', 'mailniaga-smtp') . '</h4>
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
		$value = $this->settings['concurrency'] ?? 100;
		echo "<input type='number' name='mailniaga_wp_connector_settings[concurrency]' value='" . esc_attr($value) . "' class='small-text' min='1' max='500'>";
		echo "<div class='description mailniaga-setting-description'>";
		echo "<p class='setting-intro'>" . __('How many emails to send at the same time. Higher values send emails faster but use more server resources:', 'mailniaga-smtp') . "</p>";
		echo "<div class='mailniaga-recommendations'>";
		echo "<div class='recommendation-item recommendation-low'>";
		echo "<strong>" . __('Low Server Resources', 'mailniaga-smtp') . "</strong>";
		echo "<span class='server-type'>" . __('(Shared Hosting)', 'mailniaga-smtp') . "</span>";
		echo "<span class='recommended-value'>" . __('Use 20-50', 'mailniaga-smtp') . "</span>";
		echo "</div>";
		echo "<div class='recommendation-item recommendation-medium'>";
		echo "<strong>" . __('Medium Server Resources', 'mailniaga-smtp') . "</strong>";
		echo "<span class='server-type'>" . __('(VPS)', 'mailniaga-smtp') . "</span>";
		echo "<span class='recommended-value'>" . __('Use 50-200', 'mailniaga-smtp') . "</span>";
		echo "</div>";
		echo "<div class='recommendation-item recommendation-high'>";
		echo "<strong>" . __('High Server Resources', 'mailniaga-smtp') . "</strong>";
		echo "<span class='server-type'>" . __('(Dedicated)', 'mailniaga-smtp') . "</span>";
		echo "<span class='recommended-value'>" . __('Use 200-500', 'mailniaga-smtp') . "</span>";
		echo "</div>";
		echo "</div>";
		echo "<p class='setting-note'>" . __('Default: 100. If unsure, start low and increase gradually while monitoring server performance.', 'mailniaga-smtp') . "</p>";
		echo "</div>";
	}

	public function batch_size_callback() {
		$value = $this->settings['batch_size'] ?? 100;
		echo "<input type='number' name='mailniaga_wp_connector_settings[batch_size]' value='" . esc_attr($value) . "' class='small-text' min='1' max='5000'>";
		echo "<div class='description mailniaga-setting-description'>";
		echo "<p class='setting-intro'>" . __('How many emails to pull from the queue at once. Higher values mean fewer database queries but more memory usage:', 'mailniaga-smtp') . "</p>";
		echo "<div class='mailniaga-recommendations'>";
		echo "<div class='recommendation-item recommendation-low'>";
		echo "<strong>" . __('Low Memory', 'mailniaga-smtp') . "</strong>";
		echo "<span class='server-type'>" . __('(< 2GB)', 'mailniaga-smtp') . "</span>";
		echo "<span class='recommended-value'>" . __('Use 50-100', 'mailniaga-smtp') . "</span>";
		echo "</div>";
		echo "<div class='recommendation-item recommendation-medium'>";
		echo "<strong>" . __('Medium Memory', 'mailniaga-smtp') . "</strong>";
		echo "<span class='server-type'>" . __('(5GB-20GB)', 'mailniaga-smtp') . "</span>";
		echo "<span class='recommended-value'>" . __('Use 100-1000', 'mailniaga-smtp') . "</span>";
		echo "</div>";
		echo "<div class='recommendation-item recommendation-high'>";
		echo "<strong>" . __('High Memory', 'mailniaga-smtp') . "</strong>";
		echo "<span class='server-type'>" . __('(> 20GB)', 'mailniaga-smtp') . "</span>";
		echo "<span class='recommended-value'>" . __('Use 1000-5000', 'mailniaga-smtp') . "</span>";
		echo "</div>";
		echo "</div>";
		echo "<p class='setting-note'>" . __('Default: 100. If you see memory errors, reduce this value.', 'mailniaga-smtp') . "</p>";
		echo "</div>";
	}

	public function add_admin_styles() {
		echo "<style>
        .mailniaga-setting-description {
            margin-top: 8px;
            max-width: 800px;
        }
        .setting-intro {
            margin-bottom: 12px!important;
            color: #1e1e1e;
            font-size: 13px;
        }
        .mailniaga-recommendations {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            padding: 12px;
            margin-bottom: 12px;
        }
        .recommendation-item {
            display: flex;
            align-items: center;
            padding: 8px;
            margin: 4px 0;
            border-radius: 3px;
            background: #f0f0f1;
        }
        .recommendation-item strong {
            flex: 0 0 auto;
            margin-right: 8px;
            color: #1e1e1e;
        }
        .recommendation-item .server-type {
            color: #646970;
            font-size: 12px;
            margin-right: 12px;
        }
        .recommendation-item .recommended-value {
            margin-left: auto;
            background: #2271b1;
            color: #fff;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 12px;
        }
        .recommendation-low .recommended-value {
            background: #674399;
        }
        .recommendation-medium .recommended-value {
            background: #2271b1;
        }
        .recommendation-high .recommended-value {
            background: #008a20;
        }
        .setting-note {
            color: #646970;
            font-style: italic;
            margin-top: 8px;
            font-size: 12px;
        }
    </style>";
	}

	private function render_text_field($key, $default = '') {
		$value = $this->settings[$key] ?? $default;
		echo "<input type='text' name='mailniaga_wp_connector_settings[$key]' value='" . esc_attr($value) . "' class='regular-text'>";
	}

	private function render_webhook_field() {
		$webhook = $this->settings['webhook'] ?? '';
		$readonly = !empty($webhook) ? ' readonly' : '';
		$button_style = !empty($webhook) ? ' style="display:none;"' : '';

		echo "<input type='text' id='mailniaga_webhook' name='mailniaga_wp_connector_settings[webhook]' value='" . esc_attr($webhook) . "' class='regular-text'$readonly>";
		echo "<button type='button' id='generate_webhook' class='button button-secondary'$button_style>" . __('Generate Webhook', 'mailniaga-smtp') . "</button>";

		if (!empty($webhook)) {
			$callback_url = add_query_arg('webhook', $webhook, site_url('/' . MAILNIAGA_WP_CONNECTOR['SLUG'] . '/callback'));
			echo "<button type='button' id='copy_webhook_url' class='button button-secondary' style='margin-left: 10px;'>" . __('Copy URL', 'mailniaga-smtp') . "</button>";
			echo "<p style='margin-top: 13px'><strong>" . __('Callback URL:', 'mailniaga-smtp') . "</strong> <code id='webhook_callback_url'>$callback_url</code></p>";
			echo "<p style='margin-top: 5px; font-style: italic;'>" . __('For FunnelKit: This callback URL will be used to automatically unsubscribe emails that are not delivered.', 'mailniaga-smtp') . "</p>";
		}
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
		$sanitized = [];

		// Sanitize main fields
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

		// Sanitize performance fields
		if (isset($input['concurrency'])) {
			$concurrency = intval($input['concurrency']);
			$sanitized['concurrency'] = min(max($concurrency, 1), 500);
		}
		if (isset($input['batch_size'])) {
			$batch_size = intval($input['batch_size']);
			$sanitized['batch_size'] = min(max($batch_size, 1), 5000);
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

	public function render_admin_page() {
		?>
        <div class="wrap mailniaga-settings-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post" class="mailniaga-settings-form">
				<?php
				settings_fields('mailniaga_wp_connector_settings');
				?>
                <div class="mailniaga-settings-section">
					<?php do_settings_sections('mailniaga-smtp'); ?>
                </div>
				<?php submit_button('Save Settings', 'mailniaga-submit-button'); ?>
            </form>
            <div class="mailniaga-test-email-section">
                <h2><?php _e('Test Email', 'mailniaga-smtp'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mailniaga-settings-form">
                    <input type="hidden" name="action" value="mailniaga_send_test_email">
					<?php wp_nonce_field('mailniaga_test_email', 'mailniaga_test_email_nonce'); ?>
                    <div class="mailniaga-settings-field">
                        <label for="test_email"><?php _e('Recipient Email', 'mailniaga-smtp'); ?></label>
                        <input type="email" id="test_email" name="test_email" value="<?php echo esc_attr(get_option('admin_email')); ?>" required>
                    </div>
					<?php submit_button('Send Test Email', 'secondary mailniaga-submit-button', 'send_test_email'); ?>
                </form>
            </div>
        </div>
		<?php
	}

	public function add_credit_balance_to_admin_bar($admin_bar) {
		$credit_balance = get_option('mailniaga_balance', false);

		if ($credit_balance !== false) {
			$admin_bar->add_menu([
				'id'    => 'mailniaga-credit-balance',
				'title' => 'Mail Niaga Balance: ' . number_format($credit_balance),
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
            #wpadminbar .mailniaga-credit-balance-item {
                background-color: #ffd700;
                color: white;
            }
            #wpadminbar .mailniaga-credit-balance-item:hover,
            #wpadminbar .mailniaga-credit-balance-item .ab-item:hover,
            #wpadminbar:not(.mobile) .mailniaga-credit-balance-item:hover > .ab-item,
            #wpadminbar:not(.mobile) .ab-top-menu > li.mailniaga-credit-balance-item:hover > .ab-item,
            #wpadminbar.nojq .quicklinks .ab-top-menu > li.mailniaga-credit-balance-item > .ab-item:focus,
            #wpadminbar:not(.mobile) .ab-top-menu > li.mailniaga-credit-balance-item > .ab-item:focus,
            #wpadminbar .ab-top-menu > li.mailniaga-credit-balance-item.hover > .ab-item {
                background-color: #45a049 !important;
                color: white !important;
            }
            #wpadminbar .mailniaga-credit-balance-item .ab-item {
                color: black !important;
            }
        </style>';
	}

	/**
	 * Get the plugin settings
	 *
	 * @return array
	 */
	public function get_settings() {
		return $this->settings;
	}
}