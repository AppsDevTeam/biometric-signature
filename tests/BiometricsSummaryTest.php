<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Tests;

use ADT\BiometricSignature\Biometrics\BiometricsSummary;
use ADT\BiometricSignature\Tests\Fixtures\SampleInput;
use PHPUnit\Framework\TestCase;

final class BiometricsSummaryTest extends TestCase
{
	public function testSummarizesPenRecord(): void
	{
		$summary = BiometricsSummary::fromJson(SampleInput::capture()->biometricsJson);

		self::assertSame(2, $summary->strokes);
		self::assertSame(5, $summary->points);
		self::assertSame(1250.0, $summary->durationMs);
		self::assertSame(['pen'], $summary->pointerTypes);
		self::assertTrue($summary->hasPressure);
		self::assertTrue($summary->hasTilt);
	}

	public function testConstantPressureIsNotRealPressure(): void
	{
		// Prst na iPadu hlásí konstantní tlak 0.5 a žádný náklon
		$finger = [0, 0, 0, 0.5, 0, 0, null, null, 0, 20, 20];
		$summary = BiometricsSummary::fromJson((string) json_encode([
			'strokes' => [
				['type' => 'touch', 'points' => [$finger, [1, 1, 5, 0.5, 0, 0, null, null, 0, 20, 20]]],
				['type' => 'mouse', 'points' => [[2, 2, 9, 0, 0, 0, null, null, 0, 1, 1]]],
			],
		]));

		self::assertSame(['touch', 'mouse'], $summary->pointerTypes);
		self::assertFalse($summary->hasPressure);
		self::assertFalse($summary->hasTilt);
		self::assertSame(9.0, $summary->durationMs);
	}

	public function testRejectsRecordWithoutStrokes(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		BiometricsSummary::fromJson('{"version":1}');
	}
}
