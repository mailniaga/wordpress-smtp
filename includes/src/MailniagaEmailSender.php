<?php
namespace Webimpian\MailniagaWPConnector;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Promise;

class MailniagaEmailSender {
	private $settings;
	private $client;
	private $concurrency;
	private $batch_size;

	public function __construct($settings) {
		$this->settings = $settings;
		$this->concurrency = intval($settings['concurrency'] ?? 100);
		$this->batch_size = intval($settings['batch_size'] ?? 100);

		// Ensure values are within acceptable ranges
		$this->concurrency = min(max($this->concurrency, 1), 500);
		$this->batch_size = min(max($this->batch_size, 1), 5000);

		$this->client = new Client([
			'base_uri' => 'https://api.mailniaga.mx/api/v0/',
			'timeout'  => 30,
		]);
	}

	public function register() {
		add_filter('pre_wp_mail', [$this, 'queue_mail'], 10, 2);
		add_action('init', [$this, 'schedule_actions']);
		add_action('mailniaga_process_queue', [$this, 'process_email_queue']);
		add_action('mailniaga_retry_failed', [$this, 'retry_failed_emails']);
	}

	public function schedule_actions() {
		if (!as_next_scheduled_action('mailniaga_process_queue')) {
			as_schedule_recurring_action(time(), 1, 'mailniaga_process_queue');
		}

		if (!as_next_scheduled_action('mailniaga_retry_failed')) {
			as_schedule_recurring_action(time(), 60, 'mailniaga_retry_failed');
		}
	}

	public function queue_mail($null, $atts): bool {
		global $wpdb;

		$to = is_array($atts['to']) ? implode(',', $atts['to']) : $atts['to'];
		$headers = $this->parse_headers($atts['headers']);

		$from_email = $this->get_from_email($headers);
		$from_name = $this->get_from_name($headers);

		$table_name = $wpdb->prefix . 'mailniaga_email_queue';
		$result = $wpdb->insert(
			$table_name,
			[
				'to_email' => $to,
				'from_email' => $from_email,
				'from_name' => $from_name,
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

	public function process_email_queue() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_email_queue';

		$emails = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name 
                WHERE status = 'queued' 
                ORDER BY created_at ASC 
                LIMIT %d",
				$this->batch_size
			)
		);

		if (empty($emails)) {
			return;
		}

		$email_ids = array_map(function($email) {
			return $email->id;
		}, $emails);

		$this->mark_emails_processing($email_ids);
		$this->process_emails_batch($emails);
	}

	public function retry_failed_emails() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_email_queue';

		$failed_emails = $wpdb->get_results(
			"SELECT * FROM $table_name 
            WHERE status = 'failed' 
            ORDER BY created_at ASC 
            LIMIT {$this->batch_size}"
		);

		if (empty($failed_emails)) {
			return;
		}

		$email_ids = array_map(function($email) {
			return $email->id;
		}, $failed_emails);

		$this->mark_emails_processing($email_ids);
		$this->process_emails_batch($failed_emails, true);
	}

	private function process_emails_batch($emails, $is_retry = false) {
		$requests = function() use ($emails) {
			foreach ($emails as $email) {
				$data = $this->prepare_email_data($email);
				yield function() use ($data) {
					return $this->client->requestAsync('POST', 'messages', [
						'headers' => [
							'Content-Type' => 'application/json',
							'X-API-Key' => $this->settings['api_key'],
							'Accept' => 'application/json',
						],
						'json' => $data,
						'timeout' => 30,
						'connect_timeout' => 10
					]);
				};
			}
		};

		$pool = new Pool($this->client, $requests(), [
			'concurrency' => $this->concurrency,
			'fulfilled' => function($response, $index) use ($emails) {
				try {
					$email = $emails[$index];
					$result = json_decode($response->getBody(), true);

					if ($result && isset($result['status_code']) && $result['status_code'] === 200) {
						$this->update_email_status($email->id, 'sent');
					} else {
						$error = $result['message'] ?? 'Unknown error';
						$this->update_email_status($email->id, 'failed', $error);
					}
				} catch (\Exception $e) {
					if (isset($email)) {
						$this->update_email_status($email->id, 'failed', $e->getMessage());
					}
				}
			},
			'rejected' => function($reason, $index) use ($emails) {
				try {
					$email = $emails[$index];
					$error_message = $reason instanceof \Exception ? $reason->getMessage() : 'Unknown error';
					$this->update_email_status($email->id, 'failed', $error_message);
				} catch (\Exception $e) {
					// Silently handle rejection processing errors
				}
			},
			'options' => [
				'timeout' => 30,
				'connect_timeout' => 10
			]
		]);

		try {
			$promise = $pool->promise();
			$promise->wait();
		} catch (\Exception $e) {
			// Silently handle pool promise errors
		}
	}

	private function mark_emails_processing($email_ids) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_email_queue';

		$ids = implode(',', array_map('intval', $email_ids));
		$wpdb->query("UPDATE $table_name 
            SET status = 'processing', 
                updated_at = '" . current_time('mysql') . "' 
            WHERE id IN ($ids)");
	}

	private function prepare_email_data($email): array {
		$to = explode(',', $email->to_email);
		$headers = unserialize($email->headers);

		$data = [
			'from' => sprintf('%s <%s>', $email->from_name, $email->from_email),
			'to' => $to,
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

	private function update_email_status($email_id, $status, $error_message = null) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_email_queue';

		$data = [
			'status' => $status,
			'updated_at' => current_time('mysql'),
		];

		if ($error_message !== null) {
			$data['error_message'] = $error_message;
		}

		$wpdb->update($table_name, $data, ['id' => $email_id]);
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
		$end_time = microtime(true);
		$time_taken = round($end_time - $start_time, 3);

		return [
			'success' => $result,
			'time_taken' => $time_taken,
			'error_message' => $result ? null : null,
		];
	}
}