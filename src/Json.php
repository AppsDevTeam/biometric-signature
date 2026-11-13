<?php

declare(strict_types=1);

namespace ADT\BiometricSignature;

/**
 * @internal
 */
final class Json
{
	/**
	 * Stejné příznaky jako Nette\Utils\Json::encode(), takže výstup se shoduje
	 * se záznamy, které vznikly před knihovnou. Otisk se ale vždy počítá
	 * z uloženého řetězce, ne z nového zakódování.
	 */
	private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

	public static function encode(mixed $value): string
	{
		return json_encode($value, self::FLAGS);
	}

	/**
	 * @return array<mixed>|null null = neplatný JSON nebo nejde o objekt/pole
	 */
	public static function decodeArray(string $json): ?array
	{
		try {
			$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		return is_array($data) ? $data : null;
	}
}
