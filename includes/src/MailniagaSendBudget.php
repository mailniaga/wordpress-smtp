<?php

namespace Webimpian\MailniagaWPConnector;

/**
 * Keeps a send run inside the time Action Scheduler allows it. A killed runner
 * never releases its claim, stranding every action claimed alongside it.
 */
class MailniagaSendBudget {
	/** Well inside Action Scheduler's 30-second limit. */
	public const DEFAULT_BUDGET = 15;

	private float $started_at;

	private int $budget_seconds;

	public function __construct(float $started_at, int $budget_seconds = self::DEFAULT_BUDGET) {
		$this->started_at     = $started_at;
		$this->budget_seconds = max(1, $budget_seconds);
	}

	public function elapsed(float $now): float {
		return max(0.0, $now - $this->started_at);
	}

	public function remaining(float $now): float {
		return max(0.0, $this->budget_seconds - $this->elapsed($now));
	}

	public function has_room(float $now): bool {
		return $this->remaining($now) > 0.0;
	}

	public function fits(float $now, float $wave_seconds): bool {
		return $wave_seconds <= $this->remaining($now);
	}
}
