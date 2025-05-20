<?php

namespace Webimpian\MailniagaWPConnector;

class MailniagaCheckBalance {
	private MailniagaSettings $settings;

	public function __construct(MailniagaSettings $settings) {
		$this->settings = $settings;
	}

	public function register() {
		add_action('init', [$this, 'register_cron_interval'], 20);
		add_action('init', [$this, 'schedule_event'], 30);
		add_action('mailniaga_check_balance_hook', [$this, 'check_balance_task']);
	}

	public function register_cron_interval() {
		add_filter('cron_schedules', [$this, 'add_cron_interval']);
	}

	public function add_cron_interval($schedules) {
		$schedules['mailniaga_every_minute'] = array(
			'interval' => 60,
			'display'  => __('Every Minute', 'mailniaga-smtp')
		);
		return $schedules;
	}

	public function schedule_event() {
		if (!wp_next_scheduled('mailniaga_check_balance_hook')) {
			wp_schedule_event(time(), 'mailniaga_every_minute', 'mailniaga_check_balance_hook');
		}
	}

	public function check_balance_task() {
		$balance = $this->get_balance_from_api();
		if ($balance !== false) {
			$this->update_balance($balance);
			//error_log('MailniagaCheckBalance task executed at ' . date('Y-m-d H:i:s') . '. Current balance: ' . $balance);
		}
	}

	private function get_balance_from_api() {
		$settings = $this->settings->get_settings();
		$api_key = $settings['api_key'] ?? '';
		if (empty($api_key)) {
			return false;
		}

		$response = wp_remote_get('https://api.mailniaga.mx/api/v0/user', [
			'headers' => [
				'Content-Type' => 'application/json',
				'X-API-Key' => $api_key
			]
		]);

		if (is_wp_error($response)) {
			return false;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (isset($data['error']) && $data['error'] === false && isset($data['data']['credit_balance'])) {
			return (float) $data['data']['credit_balance'];
		}

		return false;
	}

	private function update_balance(float $balance) {
		update_option('mailniaga_balance', $balance, false);
	}
}