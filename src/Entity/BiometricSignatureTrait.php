<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Entity;

use ADT\BiometricSignature\Biometrics\BiometricsSummary;
use ADT\BiometricSignature\SignatureCapture;
use ADT\DoctrineAnonymization\AnonymizationType;
use ADT\DoctrineAnonymization\Attributes\Anonymize;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sloupce a stav podpisu pro entitu projektu (implementace
 * BiometricSignatureInterface).
 *
 * Podpis vzniká ve dvou krocích: podepisující se podepíše na dotykovém
 * zařízení (PNG obrázek + dynamický biometrický záznam průběhu psaní)
 * a potvrdí to kódem z SMS, nebo ho na místě ověří personál (onSite).
 * Teprve pak je podpis platný (verifiedAt) a má důkazní záznam s otiskem.
 * Neověřený záznam zůstává jako stopa pokusu.
 *
 * Osobní údaje jsou označené atributem #[Anonymize] z adt/doctrine-anonymization.
 * Balíček je volitelný: bez něj Doctrine cizí atributy přeskočí.
 *
 * Entitu nelogujte do change logu - biometrika má stovky kB.
 *
 * @phpstan-require-implements BiometricSignatureInterface
 */
trait BiometricSignatureTrait
{
	/** Token stránky s ověřením kódu (64 hex znaků z random_bytes) */
	#[ORM\Column(length: 64, unique: true)]
	#[Anonymize(AnonymizationType::SECRET)]
	protected string $token;

	/** Obrázek podpisu z plátna (PNG data URI) */
	#[ORM\Column(type: 'text', length: 4294967295)]
	#[Anonymize(BiometricAnonymizationType::BIOMETRIC)]
	protected string $imageDataUri;

	/** Dynamický biometrický záznam (výstup BiometricsNormalizer::normalize()) */
	#[ORM\Column(type: 'text', length: 4294967295)]
	#[Anonymize(BiometricAnonymizationType::BIOMETRIC)]
	protected string $biometricsJson;

	/** Telefon, na který jde ověřovací kód (stav v okamžiku podpisu) */
	#[ORM\Column(length: 50)]
	#[Anonymize(AnonymizationType::PHONE)]
	protected string $phone;

	/**
	 * Podepsáno na zařízení provozovatele. Bez kódu z SMS ověřuje totožnost
	 * personál a podpis platí rovnou (BiometricSigning::completeOnSite()).
	 */
	#[ORM\Column(type: 'boolean', options: ['default' => false])]
	protected bool $onSite = false;

	#[ORM\Column(length: 45, nullable: true)]
	#[Anonymize(AnonymizationType::IP_ADDRESS)]
	protected ?string $ipAddress = null;

	#[ORM\Column(length: 500, nullable: true)]
	#[Anonymize(AnonymizationType::USER_AGENT)]
	protected ?string $userAgent = null;

	/** Hash aktuálního kódu (password_hash) - kód samotný se neukládá */
	#[ORM\Column(nullable: true)]
	#[Anonymize(AnonymizationType::SECRET)]
	protected ?string $codeHash = null;

	#[ORM\Column(type: 'datetime_immutable', nullable: true)]
	protected ?\DateTimeImmutable $codeSentAt = null;

	#[ORM\Column(type: 'integer', options: ['default' => 0])]
	protected int $codeSendCount = 0;

	/** Chybné pokusy pro aktuální kód (nový kód počítadlo nuluje) */
	#[ORM\Column(type: 'integer', options: ['default' => 0])]
	protected int $codeAttempts = 0;

	/** Kdy byl podpis ověřen - od té chvíle platí */
	#[ORM\Column(type: 'datetime_immutable', nullable: true)]
	protected ?\DateTimeImmutable $verifiedAt = null;

	/** Doslovný JSON důkazního záznamu, ze kterého se počítá documentHash */
	#[ORM\Column(type: 'text', nullable: true)]
	#[Anonymize(AnonymizationType::FREE_TEXT)]
	protected ?string $evidenceJson = null;

	/** SHA-256 z evidenceJson - tiskne se do PDF */
	#[ORM\Column(length: 64, nullable: true)]
	protected ?string $documentHash = null;

	/** SHA-256 vygenerovaného PDF - pozdější úprava souboru jde odhalit */
	#[ORM\Column(length: 64, nullable: true)]
	protected ?string $pdfHash = null;

	/**
	 * Volá konstruktor entity. Capture je už zkontrolovaný
	 * (SignatureCapture::fromInput()).
	 *
	 * @param string $phone kam půjde ověřovací kód; '' u podpisu na místě bez kódu
	 */
	protected function initializeBiometricSignature(SignatureCapture $capture, string $phone): void
	{
		$this->imageDataUri = $capture->imageDataUri;
		$this->biometricsJson = $capture->biometricsJson;
		$this->phone = $phone;
		$this->token = bin2hex(random_bytes(32));
	}

	public function getToken(): string
	{
		return $this->token;
	}

	public function getImageDataUri(): string
	{
		return $this->imageDataUri;
	}

	public function getBiometricsJson(): string
	{
		return $this->biometricsJson;
	}

	public function getBiometricsSummary(): BiometricsSummary
	{
		return BiometricsSummary::fromJson($this->biometricsJson);
	}

	public function getPhone(): string
	{
		return $this->phone;
	}

	public function getMaskedPhone(): string
	{
		$digits = preg_replace('~\D~', '', $this->phone) ?? '';

		return str_repeat('•', max(0, strlen($digits) - 3)) . substr($digits, -3);
	}

	public function isOnSite(): bool
	{
		return $this->onSite;
	}

	public function setOnSite(bool $onSite): static
	{
		$this->onSite = $onSite;
		return $this;
	}

	public function getIpAddress(): ?string
	{
		return $this->ipAddress;
	}

	public function getUserAgent(): ?string
	{
		return $this->userAgent;
	}

	public function setRequestContext(?string $ipAddress, ?string $userAgent): static
	{
		$this->ipAddress = $ipAddress !== null ? mb_substr($ipAddress, 0, 45) : null;
		$this->userAgent = $userAgent !== null ? mb_substr($userAgent, 0, 500) : null;
		return $this;
	}

	public function getCodeHash(): ?string
	{
		return $this->codeHash;
	}

	public function getCodeSentAt(): ?\DateTimeImmutable
	{
		return $this->codeSentAt;
	}

	public function getCodeSendCount(): int
	{
		return $this->codeSendCount;
	}

	public function getCodeAttempts(): int
	{
		return $this->codeAttempts;
	}

	public function codeSent(string $codeHash, \DateTimeImmutable $sentAt): void
	{
		$this->codeHash = $codeHash;
		$this->codeSentAt = $sentAt;
		$this->codeSendCount++;
		$this->codeAttempts = 0;
	}

	public function codeFailed(): void
	{
		$this->codeAttempts++;
	}

	public function isVerified(): bool
	{
		return $this->verifiedAt !== null;
	}

	public function getVerifiedAt(): ?\DateTimeImmutable
	{
		return $this->verifiedAt;
	}

	public function markVerified(\DateTimeImmutable $verifiedAt, string $evidenceJson): void
	{
		if ($this->verifiedAt !== null) {
			throw new \LogicException('Podpis už je ověřený, důkazní záznam se nepřepisuje.');
		}
		$this->verifiedAt = $verifiedAt;
		$this->evidenceJson = $evidenceJson;
		$this->documentHash = hash('sha256', $evidenceJson);
		$this->codeHash = null; // spotřebovaný kód nemá smysl držet
	}

	public function getEvidenceJson(): ?string
	{
		return $this->evidenceJson;
	}

	public function getDocumentHash(): ?string
	{
		return $this->documentHash;
	}

	public function getPdfHash(): ?string
	{
		return $this->pdfHash;
	}

	public function recordPdf(string $pdfContent): void
	{
		$this->pdfHash = hash('sha256', $pdfContent);
	}
}
