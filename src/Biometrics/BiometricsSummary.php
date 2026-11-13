<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Biometrics;

use ADT\BiometricSignature\Json;

/**
 * Souhrn biometrického záznamu pro PDF a administraci: kolik tahů a bodů,
 * jak dlouho podpis trval a čím se psalo.
 */
final readonly class BiometricsSummary
{
	/**
	 * @param list<string> $pointerTypes typy vstupu v pořadí výskytu (pen, touch, mouse, unknown)
	 * @param bool $hasPressure tlak se během podpisu měnil (skutečné pero)
	 * @param bool $hasTilt pero hlásilo náklon (tiltX / tiltY)
	 */
	public function __construct(
		public int $strokes,
		public int $points,
		public float $durationMs,
		public array $pointerTypes,
		public bool $hasPressure,
		public bool $hasTilt,
	) {
	}

	/**
	 * @param string $biometricsJson znormalizovaný záznam (BiometricsNormalizer::normalize())
	 */
	public static function fromJson(string $biometricsJson): self
	{
		$data = Json::decodeArray($biometricsJson);
		if (!is_array($data['strokes'] ?? null)) {
			throw new \InvalidArgumentException('Biometrický záznam nemá tahy - čeká se výstup BiometricsNormalizer::normalize().');
		}

		$points = 0;
		$duration = 0.0;
		$types = [];
		$pressures = [];
		$hasTilt = false;
		foreach ($data['strokes'] as $stroke) {
			$types[(string) $stroke['type']] = true;
			foreach ($stroke['points'] as $point) {
				$points++;
				$duration = max($duration, (float) $point[2]);
				// Prst na iPadu i myš hlásí konstantní tlak (0.5 / 0) - skutečný
				// tlak poznáme podle toho, že se hodnota během podpisu mění
				if ($point[3] !== null && $point[3] != 0 && $point[3] != 0.5) {
					$pressures[(string) $point[3]] = true;
				}
				foreach ([4, 5] as $index) {
					if (($point[$index] ?? null) !== null && $point[$index] != 0) {
						$hasTilt = true;
					}
				}
			}
		}

		return new self(
			strokes: count($data['strokes']),
			points: $points,
			durationMs: $duration,
			pointerTypes: array_map(strval(...), array_keys($types)),
			hasPressure: count($pressures) > 1,
			hasTilt: $hasTilt,
		);
	}
}
