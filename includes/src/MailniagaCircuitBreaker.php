<?php

namespace Webimpian\MailniagaWPConnector;

/**
 * Pauses the queue while the API or the account is the problem, so mail waits
 * instead of failing. Resumes on its own once sending works again.
 */
class MailniagaCircuitBreaker {
	private const OPTION = 'mailniaga_circuit';

	private const STEPS = [60, 300, 900, 1800, 3600];

	public function register(): void {
		add_action('admin_notices', [$this, 'render_notice']);
	}

	public function is_open(?int $now = null): bool {
		$state = $this->state();

		if ($state === null) {
			return false;
		}

		return $state['until'] > ($now ?? time());
	}

	public function trip(string $reason, ?int $now = null): void {
		$now = $now ?? time();
		$state = $this->state();

		$failures = $state === null ? 1 : (int) $state['failures'] + 1;
		$step = self::STEPS[min($failures, count(self::STEPS)) - 1];

		update_option(self::OPTION, [
			'reason' => $reason,
			'failures' => $failures,
			'until' => $now + $step,
		], false);
	}

	public function reset(): void {
		if ($this->state() !== null) {
			delete_option(self::OPTION);
		}
	}

	public function reason(): string {
		$state = $this->state();

		return $state === null ? '' : (string) $state['reason'];
	}

	public function resumes_at(): int {
		$state = $this->state();

		return $state === null ? 0 : (int) $state['until'];
	}

	public function render_notice(): void {
		if (!current_user_can('manage_options') || !$this->is_open()) {
			return;
		}

		$message = sprintf(
			/* translators: 1: reason sending failed, 2: human readable time. */
			__('Mail Niaga has paused the email queue: %1$s. Sending resumes automatically in %2$s. No email has been lost.', 'mailniaga-smtp'),
			$this->reason(),
			human_time_diff(time(), $this->resumes_at())
		);

		MailniagaNotice::render(__('Email sending is paused', 'mailniaga-smtp'), $message, null, 'warning');
	}

	private function state(): ?array {
		$state = get_option(self::OPTION);

		return is_array($state) && isset($state['until'], $state['failures']) ? $state : null;
	}
}
