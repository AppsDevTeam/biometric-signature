<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Tests\Fixtures;

use ADT\BiometricSignature\Entity\BiometricSignatureInterface;
use ADT\BiometricSignature\Entity\BiometricSignatureTrait;
use ADT\BiometricSignature\SignatureCapture;
use Doctrine\ORM\Mapping as ORM;

/** Entita projektu tak, jak ji knihovna čeká: id, createdAt a trait */
#[ORM\Entity]
#[ORM\Table(name: 'test_signature')]
class TestSignature implements BiometricSignatureInterface
{
	use BiometricSignatureTrait;

	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	protected ?int $id = null;

	#[ORM\Column(type: 'datetime_immutable')]
	protected \DateTimeImmutable $createdAt;

	public function __construct(SignatureCapture $capture, string $phone, \DateTimeImmutable $createdAt)
	{
		$this->initializeBiometricSignature($capture, $phone);
		$this->createdAt = $createdAt;
	}

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}

	/** Pro testy úprav v DB (podvržený obrázek) */
	public function tamperImage(string $imageDataUri): void
	{
		$this->imageDataUri = $imageDataUri;
	}

	public function tamperBiometrics(string $biometricsJson): void
	{
		$this->biometricsJson = $biometricsJson;
	}

	public function tamperEvidence(string $evidenceJson): void
	{
		$this->evidenceJson = $evidenceJson;
	}

	/** Důkazní záznam uložený přímo (jiná struktura než Evidence::build()) */
	public function setLegacyEvidence(\DateTimeImmutable $verifiedAt, string $evidenceJson, string $documentHash): void
	{
		$this->verifiedAt = $verifiedAt;
		$this->evidenceJson = $evidenceJson;
		$this->documentHash = $documentHash;
	}
}
