<?php

namespace Webimpian\MailniagaWPConnector;

/**
 * Caps how many delivery callbacks are recorded per minute, so a burst cannot
 * take the site down. Above the cap the callback is still answered, just not stored.
 */
class MailniagaWebhookLimiter {
	public const DEFAULT_LIMIT = 120;

	private const GROUP = 'mailniaga';
	private const PREFIX = 'mn_wh_';

	public static function bucket(int $timestamp): string {
		return gmdate('YmdHi', $timestamp);
	}

	public static function exceeded(int $count, int $limit): bool {
		return $count > $limit;
	}

	public static function limit(): int {
		return max(1, (int) apply_filters('mailniaga_webhook_limit', self::DEFAULT_LIMIT));
	}

	/** Counts this callback and reports whether it is still within the cap. */
	public function allow(?int $now = null): bool {
		$key = self::PREFIX . self::bucket($now ?? time());

		return !self::exceeded($this->increment($key), self::limit());
	}

	private function increment(string $key): int {
		if (wp_using_ext_object_cache()) {
			$count = (int) wp_cache_get($key, self::GROUP);
			wp_cache_set($key, ++$count, self::GROUP, 120);

			return $count;
		}

		$count = (int) get_transient($key);
		set_transient($key, ++$count, 120);

		return $count;
	}
}
