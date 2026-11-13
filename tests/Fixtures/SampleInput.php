<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Tests\Fixtures;

use ADT\BiometricSignature\Biometrics\BiometricsNormalizer;
use ADT\BiometricSignature\SignatureCapture;

/** Vstupy, jaké posílá assets/biometric-signature.js */
final class SampleInput
{
	/** PNG 1×1 px */
	public const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	/**
	 * Záznam ze dvou tahů perem s proměnlivým tlakem a náklonem.
	 *
	 * @param array<string, mixed> $overrides přepíše klíče v kořeni záznamu
	 * @return array<string, mixed>
	 */
	public static function biometrics(array $overrides = []): array
	{
		return array_replace([
			'version' => 1,
			'startedAt' => '2026-11-13T09:00:00.000Z',
			'canvas' => ['width' => 600, 'height' => 200, 'devicePixelRatio' => 2],
			'device' => [
				'userAgent' => 'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X)',
				'maxTouchPoints' => 5,
				'screen' => ['width' => 1024, 'height' => 1366],
			],
			'fields' => BiometricsNormalizer::FIELDS,
			'strokes' => [
				[
					'type' => 'pen',
					'points' => [
						[10.5, 20.25, 0, 0.31, 12, -4, 1.1, 0.5, 0, 1, 1],
						[11.75, 21, 4.17, 0.42, 13, -3, 1.08, 0.52, 0, 1, 1],
						[13, 23.5, 8.33, 0.45, 13, -3, 1.07, 0.53, 0, 1, 1],
					],
				],
				[
					'type' => 'pen',
					'points' => [
						[40, 30, 812.5, 0.2, 10, -2, null, null, 0, 1, 1],
						[42, 31, 1250, 0.25, 10, -2, null, null, 0, 1, 1],
					],
				],
			],
		], $overrides);
	}

	/** @param array<string, mixed> $overrides */
	public static function biometricsJson(array $overrides = []): string
	{
		return json_encode(self::biometrics($overrides), JSON_THROW_ON_ERROR);
	}

	public static function capture(): SignatureCapture
	{
		return SignatureCapture::fromInput(self::PNG, self::biometricsJson());
	}
}
