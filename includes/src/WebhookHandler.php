<?php
namespace Webimpian\MailniagaWPConnector;

class WebhookHandler {
	private MailniagaSettings $settings;
	private MailniagaWebhookLimiter $limiter;

	private const WEBHOOK_PATH = '/mailniaga-smtp/callback';

	public function __construct(MailniagaSettings $settings) {
		$this->settings = $settings;
		$this->limiter = new MailniagaWebhookLimiter();

		// Runs before the theme loads.
		add_action('setup_theme', [$this, 'handle_webhook_callback'], 0);
	}

	public function handle_webhook_callback() {
		if (($_SERVER['REQUEST_URI'] ?? '') === '' || !$this->is_callback_path()) {
			return;
		}

		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			$this->fail(405, 'Only POST requests are allowed');
		}

		if (empty($_GET['webhook'])) {
			$this->fail(400, 'Missing webhook parameter');
		}

		$expected = $this->settings->get_settings()['webhook'] ?? '';

		if ($expected === '' || !hash_equals($expected, (string) $_GET['webhook'])) {
			$this->fail(403, 'Invalid webhook');
		}

		$data = $this->read_payload();

		// Answer before touching the database: a slow write must not hold the
		// connection open, or the sender starts retrying on top of live traffic.
		$this->respond_ok();

		// Only bounces count towards the cap.
		if (isset($data['delivered']) && $data['delivered'] === 'false' && $this->limiter->allow()) {
			$this->store_failed_delivery($data);
		}

		exit;
	}

	private function is_callback_path(): bool {
		$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

		return $path === self::WEBHOOK_PATH;
	}

	private function read_payload(): array {
		$content_type = $_SERVER['CONTENT_TYPE'] ?? '';

		if (strpos($content_type, 'application/x-www-form-urlencoded') === false) {
			$this->fail(415, 'Unsupported content type');
		}

		return $_POST;
	}

	/** Closes the connection where the server allows it, then keeps working. */
	private function respond_ok(): void {
		if (!headers_sent()) {
			status_header(200);
			header('Content-Type: application/json; charset=utf-8');
			header('Connection: close');
		}

		echo wp_json_encode(['status' => 'success']);

		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
			return;
		}

		if (function_exists('litespeed_finish_request')) {
			litespeed_finish_request();
		}
	}

	private function fail(int $code, string $message): void {
		if (!headers_sent()) {
			status_header($code);
			header('Content-Type: application/json; charset=utf-8');
		}

		echo wp_json_encode(['status' => 'error', 'message' => $message]);
		exit;
	}

	private function store_failed_delivery(array $data): void {
		global $wpdb;

		$table = $wpdb->prefix . 'mailniaga_failed_deliveries';
		$email = $data['to'] ?? '';

		if ($email === '') {
			return;
		}

		$exists = $wpdb->get_var(
			$wpdb->prepare("SELECT id FROM $table WHERE to_email = %s", $email)
		);

		if ($exists) {
			return;
		}

		$wpdb->insert(
			$table,
			[
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
				'unsubscribed' => 0,
			],
			['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
		);
	}
}
