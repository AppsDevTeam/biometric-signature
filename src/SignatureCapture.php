<?php

declare(strict_types=1);

namespace ADT\BiometricSignature;

use ADT\BiometricSignature\Biometrics\BiometricsNormalizer;
use ADT\BiometricSignature\Exception\InvalidSignatureException;

/**
 * Podpis, jak přišel z formuláře: obrázek a biometrický záznam. Instance
 * vzniká jen přes fromInput(), takže obsah je vždy zkontrolovaný - entita
 * (BiometricSignatureTrait::initializeBiometricSignature()) ho bere bez
 * dalších kontrol.
 */
final readonly class SignatureCapture
{
	private function __construct(
		public string $imageDataUri,
		public string $biometricsJson,
	) {
	}

	/**
	 * @param string $imageDataUri hodnota inputu s obrázkem (PNG data URI)
	 * @param string $biometricsJson hodnota inputu s biometrikou (JSON z prohlížeče)
	 * @throws InvalidSignatureException obrázek nebo biometrika chybí nebo jsou neplatné
	 */
	public static function fromInput(
		string $imageDataUri,
		string $biometricsJson,
		BiometricsNormalizer $normalizer = new BiometricsNormalizer(),
		int $maxImageLength = PngDataUri::DEFAULT_MAX_LENGTH,
	): self {
		if (!PngDataUri::isValid($imageDataUri, $maxImageLength)) {
			throw InvalidSignatureException::invalidImage();
		}
		$biometrics = $normalizer->normalize($biometricsJson);
		if ($biometrics === null) {
			throw InvalidSignatureException::invalidBiometrics();
		}

		return new self($imageDataUri, $biometrics);
	}
}
