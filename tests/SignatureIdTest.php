<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Tests;

use ADT\BiometricSignature\SignatureId;
use PHPUnit\Framework\TestCase;

/**
 * ID podpisu a obálky: UUID verze 7 - začíná časem vzniku, takže ID jdou po sobě.
 */
final class SignatureIdTest extends TestCase
{
	public function testGeneratesUuidVersion7(): void
	{
		$id = SignatureId::generate();

		self::assertMatchesRegularExpression('~^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$~', $id);
		self::assertTrue(SignatureId::isValid($id));
		self::assertNotSame($id, SignatureId::generate());
	}

	public function testStartsWithTimeOfCreation(): void
	{
		$earlier = SignatureId::generate(new \DateTimeImmutable('2026-11-24 17:28:37.123 UTC'));
		$later = SignatureId::generate(new \DateTimeImmutable('2026-11-24 17:28:41.456 UTC'));

		// prvních 48 bitů jsou milisekundy od 1. 1. 1970
		$milliseconds = hexdec(substr(str_replace('-', '', $earlier), 0, 12));
		self::assertSame((int) new \DateTimeImmutable('2026-11-24 17:28:37.123 UTC')->format('Uv'), $milliseconds);
		self::assertLessThan(0, strcmp($earlier, $later));
	}

	public function testRecognizesOtherStrings(): void
	{
		self::assertFalse(SignatureId::isValid('17'));
		self::assertFalse(SignatureId::isValid('123e4567-e89b-12d3-a456-426614174000')); // verze 1
		self::assertFalse(SignatureId::isValid('01A20E63-4833-7259-A560-54BE1FFA1689')); // velká písmena
	}
}
