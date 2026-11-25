<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Tests;

use ADT\BiometricSignature\UserAgentSummary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Čitelný popis prohlížeče a zařízení pro auditní stopu.
 */
final class UserAgentSummaryTest extends TestCase
{
	#[DataProvider('userAgents')]
	public function testDescribesBrowserAndDevice(?string $userAgent, ?string $expected): void
	{
		self::assertSame($expected, UserAgentSummary::describe($userAgent));
	}

	/** @return iterable<string, array{string|null, string|null}> */
	public static function userAgents(): iterable
	{
		yield 'Chrome na Macu' => [
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/158.0.0.0 Safari/537.36',
			'Chrome 158.0.0.0 (Macintosh)',
		];
		yield 'Safari na iPhonu' => [
			'Mozilla/5.0 (iPhone; CPU iPhone OS 26_6_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.6.1 Mobile/15E148 Safari/604.1',
			'Safari 26.6.1 (iPhone)',
		];
		yield 'Safari na iPadu' => [
			'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
			'Safari 17.5 (iPad)',
		];
		yield 'Chrome na tabletu s Androidem' => [
			'Mozilla/5.0 (Linux; Android 14; SM-X210) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
			'Chrome 126.0.0.0 (Android)',
		];
		yield 'Samsung Internet se hlásí i jako Chrome' => [
			'Mozilla/5.0 (Linux; Android 14; SM-X210) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Safari/537.36',
			'Samsung Internet 25.0 (Android)',
		];
		yield 'Edge se hlásí i jako Chrome' => [
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.2592.87',
			'Edge 126.0.2592.87 (Windows)',
		];
		yield 'Firefox na Linuxu' => [
			'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0',
			'Firefox 128.0 (Linux)',
		];
		yield 'Chrome na iPhonu' => [
			'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/126.0.6478.153 Mobile/15E148 Safari/604.1',
			'Chrome 126.0.6478.153 (iPhone)',
		];
		yield 'neznámý nástroj' => ['curl/8.5.0', 'curl/8.5.0'];
		yield 'jen zařízení' => ['SomeApp (Linux; Android 14)', 'Android'];
		yield 'chybí' => [null, null];
		yield 'prázdná' => ['  ', null];
	}
}
