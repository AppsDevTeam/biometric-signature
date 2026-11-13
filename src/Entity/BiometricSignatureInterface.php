<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Entity;

use ADT\BiometricSignature\Biometrics\BiometricsSummary;

/**
 * Entita podpisu v projektu. Implementace je v BiometricSignatureTrait,
 * projekt dodá jen id, vazbu na podepisovaný záznam a getCreatedAt().
 */
interface BiometricSignatureInterface
{
	/** Kdy se podepisující podepsal = vznik záznamu (sloupec created_at projektu) */
	public function getCreatedAt(): \DateTimeImmutable;

	/** Token stránky s ověřením kódu (64 hex znaků) */
	public function getToken(): string;

	public function getImageDataUri(): string;

	public function getBiometricsJson(): string;

	public function getBiometricsSummary(): BiometricsSummary;

	/** Telefon, na který jde ověřovací kód (stav v okamžiku podpisu); '' = bez kódu */
	public function getPhone(): string;

	/** Telefon do PDF a na obrazovku - jen poslední 3 číslice */
	public function getMaskedPhone(): string;

	/** Podepsáno na zařízení provozovatele (např. tablet na pobočce), ne na vlastním */
	public function isOnSite(): bool;

	public function getIpAddress(): ?string;

	public function getUserAgent(): ?string;

	public function getCodeHash(): ?string;

	public function getCodeSentAt(): ?\DateTimeImmutable;

	public function getCodeSendCount(): int;

	/** Chybné pokusy pro aktuální kód */
	public function getCodeAttempts(): int;

	/** Nový kód odeslán - předchozí přestává platit a pokusy se nulují */
	public function codeSent(string $codeHash, \DateTimeImmutable $sentAt): void;

	public function codeFailed(): void;

	public function isVerified(): bool;

	public function getVerifiedAt(): ?\DateTimeImmutable;

	/** Podpis ověřen - uloží důkazní záznam a jeho otisk */
	public function markVerified(\DateTimeImmutable $verifiedAt, string $evidenceJson): void;

	public function getEvidenceJson(): ?string;

	/** SHA-256 z evidenceJson - tiskne se do PDF */
	public function getDocumentHash(): ?string;

	/** SHA-256 vygenerovaného PDF */
	public function getPdfHash(): ?string;

	/** Uloží otisk PDF, které projekt k podpisu vygeneroval */
	public function recordPdf(string $pdfContent): void;
}
