<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Tests;

use ADT\BiometricSignature\Biometrics\BiometricsNormalizer;
use ADT\BiometricSignature\Tests\Fixtures\SampleInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BiometricsNormalizerTest extends TestCase
{
	public function testNormalizesValidRecordAndDropsUnknownKeys(): void
	{
		$input = SampleInput::biometrics(['extra' => 'zahodit']);
		$input['strokes'][0]['extra'] = 'zahodit';
		$input['device']['extra'] = 'zahodit';

		$normalized = new BiometricsNormalizer()->normalize(json_encode($input, JSON_THROW_ON_ERROR));

		self::assertNotNull($normalized);
		$data = json_decode($normalized, true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(['version', 'startedAt', 'canvas', 'device', 'fields', 'strokes'], array_keys($data));
		self::assertSame(['userAgent', 'maxTouchPoints', 'screen'], array_keys($data['device']));
		self::assertSame(['type', 'points'], array_keys($data['strokes'][0]));
		self::assertSame(BiometricsNormalizer::FIELDS, $data['fields']);
		self::assertSame(SampleInput::biometrics()['strokes'], $data['strokes']);
	}

	public function testIsIdempotent(): void
	{
		$normalizer = new BiometricsNormalizer();
		$once = $normalizer->normalize(SampleInput::biometricsJson());

		self::assertNotNull($once);
		self::assertSame($once, $normalizer->normalize($once));
	}

	public function testKeepsZeroFractionAndUnicode(): void
	{
		$input = SampleInput::biometrics(['device' => ['userAgent' => 'Prohlížeč/1.0', 'maxTouchPoints' => 5, 'screen' => []]]);
		$input['strokes'][0]['points'][0][0] = 10.0;

		$normalized = (string) new BiometricsNormalizer()->normalize(json_encode($input, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

		self::assertStringContainsString('[10.0,', $normalized);
		self::assertStringContainsString('Prohlížeč/1.0', $normalized);
	}

	public function testUnknownPointerTypeBecomesUnknown(): void
	{
		$input = SampleInput::biometrics();
		$input['strokes'][0]['type'] = '<script>';

		$data = json_decode((string) new BiometricsNormalizer()->normalize(json_encode($input, JSON_THROW_ON_ERROR)), true);

		self::assertSame('unknown', $data['strokes'][0]['type']);
	}

	public function testDropsStrokesWithoutPoints(): void
	{
		$input = SampleInput::biometrics();
		$input['strokes'][] = ['type' => 'pen', 'points' => []];

		$data = json_decode((string) new BiometricsNormalizer()->normalize(json_encode($input, JSON_THROW_ON_ERROR)), true);

		self::assertCount(2, $data['strokes']);
	}

	public function testTruncatesLongStrings(): void
	{
		$input = SampleInput::biometrics(['startedAt' => str_repeat('x', 100)]);
		$input['device']['userAgent'] = str_repeat('y', 1000);

		$data = json_decode((string) new BiometricsNormalizer()->normalize(json_encode($input, JSON_THROW_ON_ERROR)), true);

		self::assertSame(40, mb_strlen($data['startedAt']));
		self::assertSame(500, mb_strlen($data['device']['userAgent']));
	}

	public function testNonNumericMetadataBecomesNull(): void
	{
		$input = SampleInput::biometrics(['canvas' => ['width' => '600', 'height' => [1], 'devicePixelRatio' => 2]]);

		$data = json_decode((string) new BiometricsNormalizer()->normalize(json_encode($input, JSON_THROW_ON_ERROR)), true);

		self::assertSame(['width' => null, 'height' => null, 'devicePixelRatio' => 2], $data['canvas']);
	}

	#[DataProvider('invalidRecords')]
	public function testRejectsInvalidRecord(string $json): void
	{
		self::assertNull(new BiometricsNormalizer()->normalize($json));
	}

	/** @return iterable<string, array{string}> */
	public static function invalidRecords(): iterable
	{
		$withPoint = static function (array $point): string {
			$input = SampleInput::biometrics();
			$input['strokes'][0]['points'][0] = $point;
			return json_encode($input, JSON_THROW_ON_ERROR);
		};

		yield 'prázdný řetězec' => [''];
		yield 'neplatný JSON' => ['{"version":1,'];
		yield 'skalár' => ['1'];
		yield 'jiná verze' => [SampleInput::biometricsJson(['version' => 2])];
		yield 'verze jako řetězec' => [SampleInput::biometricsJson(['version' => '1'])];
		yield 'jiné pořadí polí' => [SampleInput::biometricsJson(['fields' => array_reverse(BiometricsNormalizer::FIELDS)])];
		yield 'bez tahů' => [SampleInput::biometricsJson(['strokes' => []])];
		yield 'tahy nejsou pole' => [SampleInput::biometricsJson(['strokes' => 'x'])];
		yield 'tah bez bodů' => [SampleInput::biometricsJson(['strokes' => [['type' => 'pen']]])];
		yield 'jen prázdné tahy' => [SampleInput::biometricsJson(['strokes' => [['type' => 'pen', 'points' => []]]])];
		yield 'bod s chybějící hodnotou' => [$withPoint([1, 2, 3])];
		yield 'bod s řetězcem' => [$withPoint([1, 2, 3, '0.5', 0, 0, null, null, 0, 1, 1])];
		yield 'bod bez x' => [$withPoint([null, 2, 3, 0.5, 0, 0, null, null, 0, 1, 1])];
		yield 'bod bez času' => [$withPoint([1, 2, null, 0.5, 0, 0, null, null, 0, 1, 1])];
		yield 'nekonečno' => [str_replace('[10.5,', '[1e999,', SampleInput::biometricsJson())];
	}

	public function testRejectsTooLongRecord(): void
	{
		$json = SampleInput::biometricsJson();

		self::assertNull(new BiometricsNormalizer(maxLength: strlen($json) - 1)->normalize($json));
		self::assertNotNull(new BiometricsNormalizer(maxLength: strlen($json))->normalize($json));
	}

	public function testRejectsTooManyPoints(): void
	{
		$json = SampleInput::biometricsJson();

		self::assertNull(new BiometricsNormalizer(maxPoints: 4)->normalize($json));
		self::assertNotNull(new BiometricsNormalizer(maxPoints: 5)->normalize($json));
	}
}
