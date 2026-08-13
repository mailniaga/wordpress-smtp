<?php

namespace Webimpian\MailniagaWPConnector;

/**
 * Separates failures caused by this message from failures caused by the system,
 * so an outage or an empty credit balance never burns a message's attempts.
 */
class MailniagaRetryPolicy {
	public const MAX_ATTEMPTS = 3;

	public const SCOPE_SYSTEM = 'system';
	public const SCOPE_MESSAGE = 'message';

	public const KIND_TRANSPORT = 'transport';
	public const KIND_API = 'api';

	/** Seconds to wait, keyed by attempts already made. */
	private const BACKOFF = [
		1 => 300,
		2 => 900,
	];

	private const SYSTEM_STATUSES = [401, 402, 403, 408, 429];

	private const TRANSPORT_MARKERS = [
		'curl error',
		'could not resolve',
		'couldn\'t connect',
		'getaddrinfo',
		'ssl connection timeout',
		'operation timed out',
		'connection refused',
		'connection reset',
		'rejected promise',
	];

	private const ACCOUNT_MARKERS = [
		'invalid api key',
		'unauthorized',
		'insufficient credit',
		'no credit',
		'credit balance',
		'quota',
		'rate limit',
	];

	private const RECIPIENT_MARKERS = [
		'invalid recipient',
		'invalid email',
		'recipient blocked',
		'suppressed',
		'no such user',
	];

	public static function classify(string $kind, ?int $status = null, string $message = ''): string {
		if ($kind === self::KIND_TRANSPORT) {
			return self::SCOPE_SYSTEM;
		}

		if ($message !== '' && self::matches($message, self::ACCOUNT_MARKERS)) {
			return self::SCOPE_SYSTEM;
		}

		if ($status !== null && (in_array($status, self::SYSTEM_STATUSES, true) || $status >= 500)) {
			return self::SCOPE_SYSTEM;
		}

		return self::SCOPE_MESSAGE;
	}

	/** For rows written before scope was recorded. Unknown counts as message. */
	public static function classify_message(string $message): string {
		if (self::matches($message, self::RECIPIENT_MARKERS)) {
			return self::SCOPE_MESSAGE;
		}

		if (self::matches($message, self::TRANSPORT_MARKERS) || self::matches($message, self::ACCOUNT_MARKERS)) {
			return self::SCOPE_SYSTEM;
		}

		return self::SCOPE_MESSAGE;
	}

	public static function is_known_bad_recipient(string $message): bool {
		return self::matches($message, self::RECIPIENT_MARKERS);
	}

	public static function is_terminal(int $attempts): bool {
		return $attempts >= self::MAX_ATTEMPTS;
	}

	public static function next_attempts(int $attempts): int {
		return min($attempts + 1, self::MAX_ATTEMPTS);
	}

	public static function backoff_seconds(int $attempts): int {
		if (self::is_terminal($attempts)) {
			return 0;
		}

		return self::BACKOFF[$attempts] ?? 0;
	}

	/** Timestamps are Unix seconds. */
	public static function is_due(int $attempts, int $failed_at, int $now): bool {
		if (self::is_terminal($attempts)) {
			return false;
		}

		return ($failed_at + self::backoff_seconds($attempts)) <= $now;
	}

	private static function matches(string $message, array $markers): bool {
		$haystack = strtolower($message);

		foreach ($markers as $marker) {
			if (strpos($haystack, $marker) !== false) {
				return true;
			}
		}

		return false;
	}
}
