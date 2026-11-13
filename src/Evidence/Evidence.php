<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Evidence;

use ADT\BiometricSignature\Entity\BiometricSignatureInterface;
use ADT\BiometricSignature\Json;

/**
 * Důkazní záznam podpisu - co přesně se podepsalo, čím a jak to bylo ověřeno.
 *
 * Obrázek a biometrika jsou v záznamu zastoupené svými SHA-256 (data samotná
 * leží v entitě). Záznam se ukládá doslova (evidenceJson) a z tohoto řetězce
 * se počítá otisk (documentHash), který se tiskne do PDF. Otisk tak jde
 * kdykoli přepočítat a porovnat (EvidenceVerifier).
 *
 * Formát:
 *
 *     {
 *       "format": "adt/biometric-signature", "version": 1,
 *       "subject": {...},                 // co se podepisuje - dodá projekt
 *       "signature": {
 *         "imageSha256": "...",           // z řetězce data URI, jak je v DB
 *         "biometricsSha256": "...",      // z uloženého JSON biometriky
 *         "signedAt": "2026-11-13T10:15:00+01:00",
 *         "ipAddress": "...", "userAgent": "..."
 *       },
 *       "onSite": false,
 *       "codeVerification": {             // null = ověřil personál na místě
 *         "phone": "...", "codeSentAt": "...", "codeSendCount": 1, "failedAttempts": 0
 *       },
 *       "verifiedAt": "2026-11-13T10:16:30+01:00"
 *     }
 *
 * EvidenceVerifier ze záznamu čte jen "signature.imageSha256"
 * a "signature.biometricsSha256". Ověří tak i záznamy s jinou strukturou
 * (např. vzniklé před přechodem na knihovnu), pokud tyto dva otisky mají.
 */
final class Evidence
{
	public const FORMAT = 'adt/biometric-signature';

	public const VERSION = 1;

	/**
	 * @param array<mixed> $subject co se podepisuje (dokument, osoba, vyplněné hodnoty) - jen hodnoty serializovatelné do JSON
	 */
	public static function build(BiometricSignatureInterface $signature, array $subject, \DateTimeImmutable $verifiedAt): string
	{
		$codeSentAt = $signature->getCodeSentAt();

		return Json::encode([
			'format' => self::FORMAT,
			'version' => self::VERSION,
			'subject' => $subject,
			'signature' => [
				'imageSha256' => self::imageHash($signature),
				'biometricsSha256' => self::biometricsHash($signature),
				'signedAt' => $signature->getCreatedAt()->format(DATE_ATOM),
				'ipAddress' => $signature->getIpAddress(),
				'userAgent' => $signature->getUserAgent(),
			],
			'onSite' => $signature->isOnSite(),
			'codeVerification' => $codeSentAt === null ? null : [
				'phone' => $signature->getPhone(),
				'codeSentAt' => $codeSentAt->format(DATE_ATOM),
				'codeSendCount' => $signature->getCodeSendCount(),
				'failedAttempts' => $signature->getCodeAttempts(),
			],
			'verifiedAt' => $verifiedAt->format(DATE_ATOM),
		]);
	}

	public static function hash(string $evidenceJson): string
	{
		return hash('sha256', $evidenceJson);
	}

	public static function imageHash(BiometricSignatureInterface $signature): string
	{
		return hash('sha256', $signature->getImageDataUri());
	}

	public static function biometricsHash(BiometricSignatureInterface $signature): string
	{
		return hash('sha256', $signature->getBiometricsJson());
	}
}
