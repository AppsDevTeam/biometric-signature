<?php

declare(strict_types=1);

namespace ADT\BiometricSignature;

/**
 * Obrázek podpisu z plátna: PNG jako data URI (canvas.toDataURL('image/png')).
 */
final class PngDataUri
{
	public const PREFIX = 'data:image/png;base64,';

	/** Podpis z plátna má jednotky až desítky kB, 2 MB je bezpečný strop */
	public const DEFAULT_MAX_LENGTH = 2_000_000;

	private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

	/**
	 * Hodnota přichází z hidden inputu a dá se podvrhnout - kontroluje se
	 * i hlavička samotného PNG, ne jen prefix.
	 */
	public static function isValid(string $dataUri, int $maxLength = self::DEFAULT_MAX_LENGTH): bool
	{
		return strlen($dataUri) <= $maxLength && self::decode($dataUri) !== null;
	}

	/** Binární PNG, nebo null, když hodnota není PNG data URI */
	public static function decode(string $dataUri): ?string
	{
		if (!str_starts_with($dataUri, self::PREFIX)) {
			return null;
		}
		$binary = base64_decode(substr($dataUri, strlen(self::PREFIX)), true);

		return $binary !== false && str_starts_with($binary, self::PNG_SIGNATURE) ? $binary : null;
	}
}
