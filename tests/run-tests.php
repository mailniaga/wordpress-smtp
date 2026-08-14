<?php
/**
 * Standalone tests for the plugin's pure logic.
 *
 * No framework and no WordPress, so this stays out of the distributed vendor
 * directory. Run with: php tests/run-tests.php
 */

declare(strict_types=1);

require __DIR__ . '/../includes/src/MailniagaRetryPolicy.php';
require __DIR__ . '/../includes/src/MailniagaSendBudget.php';
require __DIR__ . '/../includes/src/MailniagaWebhookLimiter.php';
require __DIR__ . '/../includes/src/MailniagaOrphanLogCleaner.php';

use Webimpian\MailniagaWPConnector\MailniagaRetryPolicy as Policy;
use Webimpian\MailniagaWPConnector\MailniagaSendBudget as Budget;
use Webimpian\MailniagaWPConnector\MailniagaWebhookLimiter as Limiter;
use Webimpian\MailniagaWPConnector\MailniagaOrphanLogCleaner as Cleaner;

$passed = 0;
$failed = [];

function check(string $name, $actual, $expected): void {
	global $passed, $failed;

	if ($actual === $expected) {
		$passed++;
		return;
	}

	$failed[] = sprintf(
		"  %s\n      expected: %s\n      actual:   %s",
		$name,
		var_export($expected, true),
		var_export($actual, true)
	);
}

const SYSTEM  = Policy::SCOPE_SYSTEM;
const MESSAGE = Policy::SCOPE_MESSAGE;

// ------------------------------------------------------------------ scope --
// The whole point: a broken system must never cost a message its attempts.

check('transport failure is the system', Policy::classify(Policy::KIND_TRANSPORT), SYSTEM);
check('transport ignores any status', Policy::classify(Policy::KIND_TRANSPORT, 400), SYSTEM);

check('no credit is the system', Policy::classify(Policy::KIND_API, 402), SYSTEM);
check('bad key is the system', Policy::classify(Policy::KIND_API, 401), SYSTEM);
check('forbidden is the system', Policy::classify(Policy::KIND_API, 403), SYSTEM);
check('rate limiting is the system', Policy::classify(Policy::KIND_API, 429), SYSTEM);
check('api 500 is the system', Policy::classify(Policy::KIND_API, 500), SYSTEM);
check('api 503 is the system', Policy::classify(Policy::KIND_API, 503), SYSTEM);

check('a plain rejection is the message', Policy::classify(Policy::KIND_API, 400), MESSAGE);
check('unprocessable is the message', Policy::classify(Policy::KIND_API, 422), MESSAGE);
check('api without a status is the message', Policy::classify(Policy::KIND_API), MESSAGE);

// A 400 body can still describe an account problem, which outranks the code.
check(
	'credit wording overrides a 400',
	Policy::classify(Policy::KIND_API, 400, 'Insufficient credit balance'),
	SYSTEM
);
check(
	'api key wording overrides a 400',
	Policy::classify(Policy::KIND_API, 400, 'Invalid API key supplied'),
	SYSTEM
);
check(
	'a recipient rejection stays the message',
	Policy::classify(Policy::KIND_API, 400, 'Invalid recipient address'),
	MESSAGE
);

// --------------------------------------------------- classifying 2.2.3 rows --
// The real messages behind the 11,085 failures on 12 Aug 2026. Every one of
// them is the system, so an upgrade must requeue rather than retire them.

check(
	'cURL 28 SSL timeout',
	Policy::classify_message('cURL error 28: SSL connection timeout for https://api.mailniaga.mx'),
	SYSTEM
);
check(
	'cURL 7 connect failure',
	Policy::classify_message('cURL error 7: Couldn\'t connect to server'),
	SYSTEM
);
check(
	'cURL 6 resolve failure',
	Policy::classify_message('cURL error 6: getaddrinfo() thread failed to start'),
	SYSTEM
);
check(
	'guzzle pool rejection',
	Policy::classify_message('Cannot change a rejected promise to fulfilled'),
	SYSTEM
);
check('credit wording', Policy::classify_message('Insufficient credit balance'), SYSTEM);
check('bad recipient wording', Policy::classify_message('Invalid recipient address'), MESSAGE);
check('unknown wording is the message', Policy::classify_message('Something nobody has seen'), MESSAGE);
check('empty wording is the message', Policy::classify_message(''), MESSAGE);

check('a dead mailbox is a known bad recipient', Policy::is_known_bad_recipient('No such user here'), true);
check('a timeout is not a bad recipient', Policy::is_known_bad_recipient('cURL error 28'), false);

// ---------------------------------------------------------------- attempts --

check('fresh email is not terminal', Policy::is_terminal(0), false);
check('two failures is not terminal', Policy::is_terminal(2), false);
check('three failures is terminal', Policy::is_terminal(3), true);
check('over the cap stays terminal', Policy::is_terminal(9), true);

check('attempts increment', Policy::next_attempts(0), 1);
check('attempts increment again', Policy::next_attempts(1), 2);
check('attempts reach the cap', Policy::next_attempts(2), 3);
check('attempts saturate at the cap', Policy::next_attempts(7), 3);

// ----------------------------------------------------------------- backoff --

check('first retry waits 5 minutes', Policy::backoff_seconds(1), 300);
check('second retry waits 15 minutes', Policy::backoff_seconds(2), 900);
check('terminal has no wait', Policy::backoff_seconds(3), 0);
check('unknown attempt count has no wait', Policy::backoff_seconds(7), 0);

// --------------------------------------------------------------- due times --

$t = 1000000;

check('not due during the backoff window', Policy::is_due(1, $t, $t + 299), false);
check('due exactly on the boundary', Policy::is_due(1, $t, $t + 300), true);
check('due after the window', Policy::is_due(1, $t, $t + 301), true);
check('second window is longer', Policy::is_due(2, $t, $t + 899), false);
check('second window elapses', Policy::is_due(2, $t, $t + 900), true);
check('terminal is never due', Policy::is_due(3, $t, $t + 100000), false);

// ------------------------------------------------------------------ budget --

$budget = new Budget(100.0, 15);

check('nothing elapsed at the start', $budget->elapsed(100.0), 0.0);
check('elapsed tracks the clock', $budget->elapsed(104.5), 4.5);
check('elapsed never goes negative', $budget->elapsed(99.0), 0.0);

check('full budget at the start', $budget->remaining(100.0), 15.0);
check('budget drains', $budget->remaining(110.0), 5.0);
check('budget bottoms out at zero', $budget->remaining(200.0), 0.0);

check('room while budget remains', $budget->has_room(114.9), true);
check('no room once spent', $budget->has_room(115.0), false);
check('no room when overrun', $budget->has_room(500.0), false);

check('a short wave fits', $budget->fits(110.0, 4.0), true);
check('a wave fits exactly', $budget->fits(110.0, 5.0), true);
check('an overlong wave does not fit', $budget->fits(110.0, 5.1), false);

$tiny = new Budget(0.0, 0);
check('budget is never less than a second', $tiny->remaining(0.0), 1.0);

// --------------------------------------------------------- webhook limiter --

check('a fresh minute is under the cap', Limiter::exceeded(1, 120), false);
check('exactly at the cap is allowed', Limiter::exceeded(120, 120), false);
check('one past the cap is not', Limiter::exceeded(121, 120), true);
check('far past the cap is not', Limiter::exceeded(5000, 120), true);
check('a cap of one still works', Limiter::exceeded(2, 1), true);

// Buckets are per-minute, so a burst cannot borrow capacity from the last one.
$minute = 1000000 - (1000000 % 60);
check('start of the minute', Limiter::bucket($minute) === Limiter::bucket($minute), true);
check('same minute shares a bucket', Limiter::bucket($minute) === Limiter::bucket($minute + 59), true);
check('next minute gets its own', Limiter::bucket($minute) === Limiter::bucket($minute + 60), false);
check('bucket is a UTC minute stamp', strlen(Limiter::bucket($minute)), 12);

// ---------------------------------------------------------- purge load guard --

check('idle server may purge', Cleaner::load_ok([0.5, 0.4, 0.3], 4.0), true);
check('busy server may not', Cleaner::load_ok([6.2, 5.0, 4.0], 4.0), false);
check('exactly at the limit may not', Cleaner::load_ok([4.0, 1.0, 1.0], 4.0), false);
check('unknown load may purge', Cleaner::load_ok(null, 4.0), true);
check('malformed load may purge', Cleaner::load_ok([], 4.0), true);

// ------------------------------------------------------------------ report --

$total = $passed + count($failed);

if ($failed === []) {
	echo "OK — {$passed}/{$total} checks passed\n";
	exit(0);
}

echo "FAILED — " . count($failed) . " of {$total} checks\n\n";
echo implode("\n\n", $failed) . "\n";
exit(1);
