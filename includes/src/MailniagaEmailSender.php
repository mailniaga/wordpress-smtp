<?php
namespace Webimpian\MailniagaWPConnector;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Exception\RequestException;

class MailniagaEmailSender {
	private const MAX_CONCURRENCY = 25;
	private const MAX_BATCH_SIZE  = 250;
	private const MIN_INTERVAL    = 60;
	private const RETRY_INTERVAL  = 300;

	private $settings;
	private $client;
	private $concurrency;
	private $batch_size;
	private $breaker;

	private $sent_count = 0;
	private $system_failures = 0;
	private $system_reason = '';
	private $requeue = [];

	public function __construct($settings) {
		$this->settings = $settings;
		$this->breaker = new MailniagaCircuitBreaker();
		$this->concurrency = intval($settings['concurrency'] ?? 5);
		$this->batch_size = intval($settings['batch_size'] ?? 50);

		$this->concurrency = min(max($this->concurrency, 1), self::MAX_CONCURRENCY);
		$this->batch_size = min(max($this->batch_size, 1), self::MAX_BATCH_SIZE);

		$this->client = new Client([
			'base_uri' => 'https://api.mailniaga.mx/api/v0/',
			'timeout'  => 10,
		]);
	}

	public function register() {
		add_filter('pre_wp_mail', [$this, 'queue_mail'], 10, 2);
		add_action('init', [$this, 'schedule_actions']);
		add_action('mailniaga_process_queue', [$this, 'process_email_queue']);
		add_action('mailniaga_retry_failed', [$this, 'retry_failed_emails']);
	}

	public function schedule_actions() {
		$interval = max(self::MIN_INTERVAL, (int) apply_filters('mailniaga_queue_interval', self::MIN_INTERVAL));
		if (!as_next_scheduled_action('mailniaga_process_queue')) {
			as_schedule_recurring_action(time() + $interval, $interval, 'mailniaga_process_queue');
		}

		$retry = max(self::RETRY_INTERVAL, (int) apply_filters('mailniaga_retry_interval', self::RETRY_INTERVAL));
		if (!as_next_scheduled_action('mailniaga_retry_failed')) {
			as_schedule_recurring_action(time() + $retry, $retry, 'mailniaga_retry_failed');
		}
	}

	public function queue_mail($null, $atts): bool {
		global $wpdb;

		$to = is_array($atts['to']) ? implode(',', $atts['to']) : $atts['to'];
		$headers = $this->parse_headers($atts['headers']);

		$result = $wpdb->insert(
			$this->table(),
			[
				'to_email' => $to,
				'from_email' => $this->get_from_email($headers),
				'from_name' => $this->get_from_name($headers),
				'subject' => $atts['subject'],
				'message' => $atts['message'],
				'headers' => serialize($headers),
				'attachments' => serialize($atts['attachments'] ?? []),
				'status' => 'queued',
				'created_at' => current_time('mysql'),
			]
		);

		return $result !== false;
	}

	/** Throughput is capped by the run's time budget, not by batch size. */
	public function process_email_queue() {
		if ($this->breaker->is_open()) {
			return;
		}

		$this->begin_run();

		$budget = new MailniagaSendBudget(microtime(true), $this->budget_seconds());

		while ($budget->has_room(microtime(true))) {
			$emails = $this->claim('queued', $this->batch_size);
			if (empty($emails)) {
				break;
			}

			$unsent = $this->send_in_waves($emails, $budget);
			$this->requeue = array_merge($this->requeue, $unsent);

			if (!empty($unsent) || $this->system_failures > 0) {
				break;
			}
		}

		$this->finish_run();
	}

	public function retry_failed_emails() {
		if ($this->breaker->is_open()) {
			return;
		}

		$due = $this->due_for_retry($this->batch_size);
		if (empty($due)) {
			return;
		}

		$this->mark_processing(wp_list_pluck($due, 'id'));
		$this->run($due);
	}

	private function run(array $emails): void {
		$this->begin_run();

		$budget = new MailniagaSendBudget(microtime(true), $this->budget_seconds());
		$this->requeue = array_merge($this->requeue, $this->send_in_waves($emails, $budget));

		$this->finish_run();
	}

	private function begin_run(): void {
		$this->sent_count = 0;
		$this->system_failures = 0;
		$this->system_reason = '';
		$this->requeue = [];
	}

	/** The breaker only trips when nothing at all got through. */
	private function finish_run(): void {
		$this->release($this->requeue);

		if ($this->sent_count > 0) {
			$this->breaker->reset();
			return;
		}

		if ($this->system_failures > 0) {
			$this->breaker->trip($this->system_reason);
			$this->log('Queue paused: ' . $this->system_reason);
		}
	}

	/** Returns the emails that were never attempted. */
	private function send_in_waves(array $emails, MailniagaSendBudget $budget): array {
		$waves = array_chunk($emails, $this->concurrency);
		$unsent = [];

		foreach ($waves as $index => $wave) {
			if (!$budget->has_room(microtime(true))) {
				foreach (array_slice($waves, $index) as $remaining) {
					$unsent = array_merge($unsent, $remaining);
				}
				break;
			}

			$this->send_wave($wave);
		}

		return $unsent;
	}

	private function send_wave(array $wave): void {
		$requests = function () use ($wave) {
			foreach ($wave as $email) {
				$data = $this->prepare_email_data($email);
				yield function () use ($data) {
					return $this->client->requestAsync('POST', 'messages', [
						'headers' => [
							'Content-Type' => 'application/json',
							'X-API-Key' => $this->settings['api_key'],
							'Accept' => 'application/json',
						],
						'json' => $data,
						'timeout' => 10,
						'connect_timeout' => 5,
					]);
				};
			}
		};

		$pool = new Pool($this->client, $requests(), [
			'concurrency' => count($wave),
			'fulfilled' => function ($response, $index) use ($wave) {
				$this->handle_response($wave[$index], $response);
			},
			'rejected' => function ($reason, $index) use ($wave) {
				$this->handle_rejection($wave[$index], $reason);
			},
		]);

		try {
			$pool->promise()->wait();
		} catch (\Throwable $e) {
			$this->log('Pool failed: ' . $e->getMessage());
		}
	}

	private function handle_response($email, $response): void {
		try {
			$result = json_decode((string) $response->getBody(), true);

			if ($result && isset($result['status_code']) && $result['status_code'] === 200) {
				$this->mark_sent($email->id);
				return;
			}

			$this->record_failure(
				$email,
				MailniagaRetryPolicy::KIND_API,
				$response->getStatusCode(),
				$result['message'] ?? 'Unknown error'
			);
		} catch (\Throwable $e) {
			$this->record_failure($email, MailniagaRetryPolicy::KIND_TRANSPORT, null, $e->getMessage());
		}
	}

	/** A rejection with a response is the API refusing us; without one it never arrived. */
	private function handle_rejection($email, $reason): void {
		$kind = MailniagaRetryPolicy::KIND_TRANSPORT;
		$status = null;

		if ($reason instanceof RequestException && $reason->hasResponse()) {
			$kind = MailniagaRetryPolicy::KIND_API;
			$status = $reason->getResponse()->getStatusCode();
		}

		$message = $reason instanceof \Throwable ? $reason->getMessage() : 'Unknown error';

		$this->record_failure($email, $kind, $status, $message);
	}

	/** A system-scope failure costs the message nothing and is queued again. */
	private function record_failure($email, string $kind, ?int $status, string $message): void {
		global $wpdb;

		if (MailniagaRetryPolicy::classify($kind, $status, $message) === MailniagaRetryPolicy::SCOPE_SYSTEM) {
			$this->system_failures++;
			$this->system_reason = $message;
			$this->requeue[] = $email;
			return;
		}

		$wpdb->update(
			$this->table(),
			[
				'status' => 'failed',
				'attempts' => MailniagaRetryPolicy::next_attempts((int) ($email->attempts ?? 0)),
				'error_message' => $message,
				'updated_at' => current_time('mysql'),
			],
			['id' => $email->id]
		);
	}

	private function mark_sent($email_id): void {
		global $wpdb;

		$this->sent_count++;

		$wpdb->update(
			$this->table(),
			['status' => 'sent', 'updated_at' => current_time('mysql')],
			['id' => $email_id]
		);
	}

	private function claim(string $status, int $limit): array {
		global $wpdb;

		$emails = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE status = %s ORDER BY created_at ASC LIMIT %d",
				$status,
				$limit
			)
		);

		if (empty($emails)) {
			return [];
		}

		$this->mark_processing(wp_list_pluck($emails, 'id'));

		return $emails;
	}

	/** Rows left by 2.2.3 carry no scope, so their stored error decides. */
	private function due_for_retry(int $limit): array {
		global $wpdb;

		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()}
				 WHERE status = 'failed' AND attempts < %d
				 ORDER BY updated_at ASC LIMIT %d",
				MailniagaRetryPolicy::MAX_ATTEMPTS,
				$limit
			)
		);

		$now = time();
		$due = [];
		$requeue = [];

		foreach ($candidates as $email) {
			$attempts = (int) $email->attempts;
			$error = (string) $email->error_message;

			if ($attempts === 0) {
				if (MailniagaRetryPolicy::is_known_bad_recipient($error)) {
					$this->retire($email->id);
					continue;
				}

				if (MailniagaRetryPolicy::classify_message($error) === MailniagaRetryPolicy::SCOPE_SYSTEM) {
					$requeue[] = $email;
					continue;
				}
			}

			$failed_at = strtotime($email->updated_at ?: $email->created_at);

			if (MailniagaRetryPolicy::is_due($attempts, $failed_at, $now)) {
				$due[] = $email;
			}
		}

		$this->release($requeue);

		return $due;
	}

	private function retire($email_id): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			['attempts' => MailniagaRetryPolicy::MAX_ATTEMPTS],
			['id' => $email_id]
		);
	}

	private function mark_processing(array $email_ids): void {
		global $wpdb;

		if (empty($email_ids)) {
			return;
		}

		$ids = implode(',', array_map('intval', $email_ids));

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET status = 'processing', updated_at = %s WHERE id IN ({$ids})",
				current_time('mysql')
			)
		);
	}

	private function release(array $emails): void {
		global $wpdb;

		if (empty($emails)) {
			return;
		}

		$ids = implode(',', array_map('intval', wp_list_pluck($emails, 'id')));

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET status = 'queued', updated_at = %s WHERE id IN ({$ids})",
				current_time('mysql')
			)
		);
	}

	private function budget_seconds(): int {
		return max(1, (int) apply_filters('mailniaga_send_budget', MailniagaSendBudget::DEFAULT_BUDGET));
	}

	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'mailniaga_email_queue';
	}

	private function log(string $message): void {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('[MailNiaga] ' . $message);
		}
	}

	private function prepare_email_data($email): array {
		$headers = unserialize($email->headers);

		$data = [
			'from' => sprintf('%s <%s>', $email->from_name, $email->from_email),
			'to' => explode(',', $email->to_email),
			'subject' => $email->subject,
			'as_html' => 1,
			'content' => $email->message,
		];

		if (!empty($headers['reply-to'])) {
			$data['reply_to'] = $headers['reply-to'];
		}

		if (!empty($headers['unsubscribe'])) {
			$data['unsubscribe_link'] = $headers['unsubscribe'];
		}

		if (!empty($headers['content-type']) && strpos($headers['content-type'], 'text/plain') !== false) {
			$data['content_plain'] = $email->message;
			unset($data['content']);
		}

		return $data;
	}

	private function get_from_email($headers): string {
		if (!empty($headers['from'])) {
			$from = $this->extract_email($headers['from']);
			if ($from) {
				return $from;
			}
		}
		return $this->settings['from_email'] ?? get_option('admin_email');
	}

	private function get_from_name($headers): string {
		if (!empty($headers['from'])) {
			$name = $this->extract_name($headers['from']);
			if ($name) {
				return $name;
			}
		}
		return $this->settings['from_name'] ?? get_option('blogname');
	}

	private function extract_email($from): ?string {
		if (preg_match('/<(.+)>/', $from, $matches)) {
			return $matches[1];
		}
		if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
			return $from;
		}
		return null;
	}

	private function extract_name($from): ?string {
		if (preg_match('/^(.+?)\s*</', $from, $matches)) {
			return trim($matches[1], ' "');
		}
		return null;
	}

	private function parse_headers($headers): array {
		$parsed = [];
		if (is_string($headers)) {
			$headers = explode("\n", $headers);
		}
		foreach ($headers as $header) {
			if (is_string($header)) {
				$parts = explode(':', $header, 2);
				if (count($parts) == 2) {
					$parsed[strtolower(trim($parts[0]))] = trim($parts[1]);
				}
			} elseif (is_array($header) && count($header) == 2) {
				$parsed[strtolower(trim($header[0]))] = trim($header[1]);
			}
		}
		return $parsed;
	}

	public function send_test_email($to): array {
		$subject = __('Mailniaga WP Connector Test Email', 'mailniaga-smtp');
		$message = __('This is a test email sent from the Mailniaga WP Connector plugin.', 'mailniaga-smtp');

		$start_time = microtime(true);
		$result = wp_mail($to, $subject, $message);
		$time_taken = round(microtime(true) - $start_time, 3);

		return [
			'success' => $result,
			'time_taken' => $time_taken,
			'error_message' => null,
		];
	}
}
