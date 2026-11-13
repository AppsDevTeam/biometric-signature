<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Biometrics;

use ADT\BiometricSignature\Json;

/**
 * Kontrola a normalizace biometrického záznamu, který poslal prohlížeč
 * (assets/biometric-signature.js, SignatureBiometrics::toJSON()).
 *
 * Hodnota přichází z hidden inputu, takže se dá podvrhnout. Proto se z ní
 * staví nový JSON jen z očekávaných klíčů a čísel - cokoli navíc se zahodí,
 * cokoli neplatného znamená neplatný záznam (null).
 */
class BiometricsNormalizer
{
	public const VERSION = 1;

	/**
	 * Pořadí hodnot v bodu tahu - musí sedět s BIOMETRICS_FIELDS
	 * v assets/biometric-signature.js (hlídá to JsContractTest).
	 *
	 * x, y = souřadnice v CSS px od levého horního rohu plátna, t = ms od prvního
	 * dotyku, p = tlak 0-1, tx/ty = náklon pera ve stupních, alt/az = výška
	 * a azimut pera v radiánech, tw = natočení pera, w/h = rozměr kontaktní plochy.
	 */
	public const FIELDS = ['x', 'y', 't', 'p', 'tx', 'ty', 'alt', 'az', 'tw', 'w', 'h'];

	public const POINTER_TYPES = ['pen', 'touch', 'mouse'];

	/** Typicky desítky až stovky kB, Apple Pencil sbírá až ~240 vzorků/s */
	public const DEFAULT_MAX_LENGTH = 5_000_000;

	/** Pero ~240 Hz × desítky sekund s rezervou */
	public const DEFAULT_MAX_POINTS = 100_000;

	public function __construct(
		private readonly int $maxLength = self::DEFAULT_MAX_LENGTH,
		private readonly int $maxPoints = self::DEFAULT_MAX_POINTS,
	) {
	}

	/**
	 * Vrátí znormalizovaný JSON, nebo null, když záznam chybí, je prázdný
	 * nebo neplatný.
	 */
	public function normalize(string $json): ?string
	{
		if ($json === '' || strlen($json) > $this->maxLength) {
			return null;
		}
		try {
			$data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		if (!is_array($data) || ($data['version'] ?? null) !== self::VERSION || ($data['fields'] ?? null) !== self::FIELDS) {
			return null;
		}
		if (!is_array($data['strokes'] ?? null) || $data['strokes'] === []) {
			return null;
		}

		$strokes = $this->normalizeStrokes($data['strokes']);
		if ($strokes === null) {
			return null;
		}

		$canvas = is_array($data['canvas'] ?? null) ? $data['canvas'] : [];
		$device = is_array($data['device'] ?? null) ? $data['device'] : [];
		$screen = is_array($device['screen'] ?? null) ? $device['screen'] : [];

		return Json::encode([
			'version' => self::VERSION,
			'startedAt' => is_string($data['startedAt'] ?? null) ? mb_substr($data['startedAt'], 0, 40) : null,
			'canvas' => [
				'width' => self::number($canvas['width'] ?? null),
				'height' => self::number($canvas['height'] ?? null),
				'devicePixelRatio' => self::number($canvas['devicePixelRatio'] ?? null),
			],
			'device' => [
				'userAgent' => is_string($device['userAgent'] ?? null) ? mb_substr($device['userAgent'], 0, 500) : null,
				'maxTouchPoints' => self::number($device['maxTouchPoints'] ?? null),
				'screen' => [
					'width' => self::number($screen['width'] ?? null),
					'height' => self::number($screen['height'] ?? null),
				],
			],
			'fields' => self::FIELDS,
			'strokes' => $strokes,
		]);
	}

	/**
	 * @param array<mixed> $input
	 * @return list<array{type: string, points: list<list<int|float|null>>}>|null
	 */
	private function normalizeStrokes(array $input): ?array
	{
		$fieldCount = count(self::FIELDS);
		$pointCount = 0;
		$strokes = [];
		foreach ($input as $stroke) {
			if (!is_array($stroke) || !is_array($stroke['points'] ?? null)) {
				return null;
			}
			$points = [];
			foreach ($stroke['points'] as $point) {
				if (!is_array($point) || count($point) !== $fieldCount || ++$pointCount > $this->maxPoints) {
					return null;
				}
				$normalized = [];
				foreach (array_values($point) as $value) {
					// json_decode z 1e999 udělá INF, který by json_encode odmítl
					if ($value !== null && !is_int($value) && !(is_float($value) && is_finite($value))) {
						return null;
					}
					$normalized[] = $value;
				}
				// x, y a čas jsou povinné - bez nich záznam nemá důkazní hodnotu
				if ($normalized[0] === null || $normalized[1] === null || $normalized[2] === null) {
					return null;
				}
				$points[] = $normalized;
			}
			if ($points !== []) {
				$strokes[] = [
					'type' => in_array($stroke['type'] ?? null, self::POINTER_TYPES, true) ? $stroke['type'] : 'unknown',
					'points' => $points,
				];
			}
		}

		return $strokes === [] ? null : $strokes;
	}

	private static function number(mixed $value): int|float|null
	{
		return is_int($value) || (is_float($value) && is_finite($value)) ? $value : null;
	}
}
