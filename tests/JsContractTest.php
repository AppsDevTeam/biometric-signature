<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Tests;

use ADT\BiometricSignature\Biometrics\BiometricsNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Prohlížeč a server sdílí formát záznamu - kdyby se rozešly, server by
 * odmítl každý podpis. Hlídá to tenhle test bez JS nástrojů.
 */
final class JsContractTest extends TestCase
{
	private static function script(): string
	{
		return (string) file_get_contents(__DIR__ . '/../assets/biometric-signature.js');
	}

	public function testFieldsMatch(): void
	{
		if (preg_match('~export const BIOMETRICS_FIELDS = \[([^\]]*)\];~', self::script(), $match) !== 1) {
			self::fail('V assets/biometric-signature.js chybí BIOMETRICS_FIELDS.');
		}
		preg_match_all("~'([^']*)'~", $match[1], $fields);

		self::assertSame(BiometricsNormalizer::FIELDS, $fields[1]);
	}

	public function testVersionMatches(): void
	{
		if (preg_match('~export const BIOMETRICS_VERSION = (\d+);~', self::script(), $match) !== 1) {
			self::fail('V assets/biometric-signature.js chybí BIOMETRICS_VERSION.');
		}

		self::assertSame(BiometricsNormalizer::VERSION, (int) $match[1]);
	}

	public function testPointerTypesMatchBrowserValues(): void
	{
		// PointerEvent.pointerType - cokoli jiného server uloží jako 'unknown'
		self::assertSame(['pen', 'touch', 'mouse'], BiometricsNormalizer::POINTER_TYPES);
	}
}
