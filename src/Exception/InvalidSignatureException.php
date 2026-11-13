<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Exception;

/**
 * Podpis z formuláře nejde přijmout. Uživateli stačí říct, ať podpis smaže
 * a podepíše se znovu - důvod (obrázek / biometrika) je jen pro log.
 */
final class InvalidSignatureException extends \InvalidArgumentException
{
	public static function invalidImage(): self
	{
		return new self('Neplatný obrázek podpisu - očekává se PNG data URI.');
	}

	public static function invalidBiometrics(): self
	{
		return new self('Chybí nebo je neplatný biometrický záznam podpisu.');
	}
}
