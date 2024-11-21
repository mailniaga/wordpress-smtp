<?php
namespace Webimpian\MailniagaWPConnector;

class WebhookHandler {
	private MailniagaSettings $settings;
	private const WEBHOOK_PATH = '/mailniaga-smtp/callback';

	public function __construct(MailniagaSettings $settings) {
		$this->settings = $settings;
		add_action('init', [$this, 'handle_webhook_callback']);
	}

	public function handle_webhook_callback() {
		$current_url = $_SERVER['REQUEST_URI'];
		$parsed_url = parse_url($current_url);
		$path = $parsed_url['path'] ?? '';


		if ($path !== self::WEBHOOK_PATH) {
			//$this->log_message("Incorrect path: $path. Expected: " . self::WEBHOOK_PATH);
			return;
		}

		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			//$this->log_message("Invalid request method: {$_SERVER['REQUEST_METHOD']}");
			wp_send_json(['status' => 'error', 'message' => 'Only POST requests are allowed'], 405);
			exit;
		}

		if (empty($_GET['webhook'])) {
			//$this->log_message("Missing webhook parameter");
			wp_send_json(['status' => 'error', 'message' => 'Missing webhook parameter'], 400);
			exit;
		}

		$stored_webhook = $this->settings->get_settings()['webhook'] ?? '';
		if ($_GET['webhook'] !== $stored_webhook) {
			//$this->log_message("Invalid webhook parameter");
			wp_send_json(['status' => 'error', 'message' => 'Invalid webhook'], 403);
			exit;
		}

		$this->process_webhook();
		wp_send_json(['status' => 'success', 'message' => 'Webhook processed']);
		exit;
	}

	private function process_webhook() {
		$content_type = $_SERVER['CONTENT_TYPE'] ?? '';

		//$this->log_message("Processing webhook. Content-Type: $content_type");

		if (strpos($content_type, 'application/x-www-form-urlencoded') === false) {
			//$this->log_message("Unsupported content type: $content_type");
			wp_send_json(['status' => 'error', 'message' => 'Unsupported content type'], 415);
			exit;
		}

		$data = $_POST;

		//$this->log_message("Webhook data received: " . print_r($data, true));

		if (isset($data['delivered']) && $data['delivered'] === 'false') {
			$this->handle_failed_delivery($data);
		} else {
			$this->log_message("Delivery was successful or status not provided. No action taken.");
		}
	}

	private function handle_failed_delivery($data) {
		//$this->log_message("Processing failed delivery: " . print_r($data, true));

		$this->store_failed_delivery($data);
	}

	private function store_failed_delivery($data) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_failed_deliveries';

		$email = $data['to'] ?? '';

		// Check if the email already exists
		$existing_entry = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $table_name WHERE to_email = %s",
				$email
			)
		);

		if ($existing_entry) {
			$this->log_message("Email $email already exists in failed deliveries. Skipping insertion.");
			return;
		}

		$insert_data = [
			'email_id' => $data['id'] ?? '',
			'domain' => $data['domain'] ?? '',
			'to_email' => $email,
			'address' => $data['address'] ?? '',
			'user' => $data['user'] ?? '',
			'interface' => $data['interface'] ?? '',
			'from_email' => $data['from'] ?? '',
			'delivery_response' => $data['delivery_response'] ?? '',
			'ip' => $data['ip'] ?? '',
			'mx' => $data['mx'] ?? '',
			'created_at' => current_time('mysql', 1),
			'unsubscribed' => 0
		];

		$this->log_message("Storing failed delivery: " . print_r($insert_data, true));

		$result = $wpdb->insert(
			$table_name,
			$insert_data,
			['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
		);

		if ($result === false) {
			$this->log_message("Failed to store failed delivery: " . $wpdb->last_error);
		} else {
			$this->log_message("Failed delivery stored successfully. ID: " . $wpdb->insert_id);
		}
	}

	private function log_message($message) {
		error_log("[MailNiaga Webhook] " . $message);
	}
}