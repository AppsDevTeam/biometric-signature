<?php

declare(strict_types=1);

namespace ADT\BiometricSignature;

/**
 * ID podpisu, obálky a dalších záznamů auditní stopy: UUID verze 7 (RFC 9562). Začíná časem
 * vzniku v milisekundách, takže ID vzniklá po sobě jdou po sobě i abecedně, zbytek je náhodný.
 *
 * ID se tiskne pod podpis do PDF (Pdf\SignatureStamp) a stejné stojí v auditní stopě.
 */
final class SignatureId
{
	public static function generate(?\DateTimeInterface $now = null): string
	{
		$milliseconds = (int) ($now ?? new \DateTimeImmutable())->format('Uv');
		$bytes = substr(pack('J', $milliseconds), 2) . random_bytes(10);

		// verze 7 a varianta podle RFC 9562
		$bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
		$bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

		$hex = bin2hex($bytes);

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 12, 4),
			substr($hex, 16, 4),
			substr($hex, 20, 12),
		);
	}

	public static function isValid(string $id): bool
	{
		return preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$~', $id) === 1;
	}
}
