<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Tests;

use ADT\BiometricSignature\BiometricSigning;
use ADT\BiometricSignature\Evidence\EvidenceProblem;
use ADT\BiometricSignature\Evidence\EvidenceVerifier;
use ADT\BiometricSignature\SigningPolicy;
use ADT\BiometricSignature\Tests\Fixtures\FrozenClock;
use ADT\BiometricSignature\Tests\Fixtures\RecordingHandler;
use ADT\BiometricSignature\Tests\Fixtures\SampleInput;
use ADT\BiometricSignature\Tests\Fixtures\TestSignature;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class EvidenceVerifierTest extends TestCase
{
	private function verifiedSignature(): TestSignature
	{
		$clock = new FrozenClock();
		$signing = new BiometricSigning($this->createStub(EntityManagerInterface::class), new RecordingHandler(), new SigningPolicy(), $clock);
		$signature = new TestSignature(SampleInput::capture(), '+420000000456', $clock->now());
		$signature->setOnSite(true);
		$signing->completeOnSite($signature);

		return $signature;
	}

	public function testVerifiedSignatureIsValid(): void
	{
		$result = new EvidenceVerifier()->verify($this->verifiedSignature());

		self::assertTrue($result->isValid());
		self::assertFalse($result->pdfChecked);
	}

	public function testChecksPdf(): void
	{
		$signature = $this->verifiedSignature();
		$verifier = new EvidenceVerifier();

		$result = $verifier->verify($signature, RecordingHandler::PDF);
		self::assertTrue($result->isValid());
		self::assertTrue($result->pdfChecked);

		$result = $verifier->verify($signature, RecordingHandler::PDF . ' upraveno');
		self::assertSame([EvidenceProblem::PdfHashMismatch], $result->problems);
	}

	public function testUnverifiedSignature(): void
	{
		$signature = new TestSignature(SampleInput::capture(), '', new \DateTimeImmutable());

		self::assertSame([EvidenceProblem::NotVerified], new EvidenceVerifier()->verify($signature)->problems);
	}

	public function testDetectsChangedEvidence(): void
	{
		$signature = $this->verifiedSignature();
		$signature->tamperEvidence(str_replace('Jan Novák', 'Jana Nováková', (string) $signature->getEvidenceJson()));

		self::assertSame([EvidenceProblem::DocumentHashMismatch], new EvidenceVerifier()->verify($signature)->problems);
	}

	public function testDetectsChangedImage(): void
	{
		$signature = $this->verifiedSignature();
		$signature->tamperImage(SampleInput::PNG . '=');

		self::assertTrue(new EvidenceVerifier()->verify($signature)->hasProblem(EvidenceProblem::ImageHashMismatch));
	}

	public function testDetectsChangedBiometrics(): void
	{
		$signature = $this->verifiedSignature();
		$signature->tamperBiometrics(str_replace('10.5', '11.5', $signature->getBiometricsJson()));

		self::assertSame([EvidenceProblem::BiometricsHashMismatch], new EvidenceVerifier()->verify($signature)->problems);
	}

	public function testMissingPdfHash(): void
	{
		$signature = new TestSignature(SampleInput::capture(), '', new \DateTimeImmutable());
		$evidence = (string) json_encode(['signature' => [
			'imageSha256' => hash('sha256', $signature->getImageDataUri()),
			'biometricsSha256' => hash('sha256', $signature->getBiometricsJson()),
		]]);
		$signature->setLegacyEvidence(new \DateTimeImmutable(), $evidence, hash('sha256', $evidence));

		self::assertSame([EvidenceProblem::PdfHashMissing], new EvidenceVerifier()->verify($signature, '%PDF')->problems);
	}

	public function testUnreadableEvidence(): void
	{
		$signature = new TestSignature(SampleInput::capture(), '', new \DateTimeImmutable());
		$signature->setLegacyEvidence(new \DateTimeImmutable(), '{"signature":"x"}', hash('sha256', '{"signature":"x"}'));

		self::assertSame([EvidenceProblem::EvidenceUnreadable], new EvidenceVerifier()->verify($signature)->problems);
	}

	/**
	 * Záznam s jinou strukturou (data podepisovaného v kořeni, bez "format")
	 * se ověří, pokud má otisky obrázku a biometriky na stejném místě.
	 */
	public function testVerifiesEvidenceWithOtherStructure(): void
	{
		$signature = new TestSignature(SampleInput::capture(), '+420000000456', new \DateTimeImmutable('2026-11-01 09:00:00'));
		$evidence = (string) json_encode([
			'version' => 1,
			'document' => ['code' => 'smlouva', 'name' => 'Smlouva', 'submittedAt' => '2026-11-01T09:00:00+01:00'],
			'person' => ['id' => 1, 'name' => 'Jan Novák'],
			'signature' => [
				'imageSha256' => hash('sha256', $signature->getImageDataUri()),
				'biometricsSha256' => hash('sha256', $signature->getBiometricsJson()),
				'signedAt' => '2026-11-01T09:00:00+01:00',
				'ipAddress' => null,
				'userAgent' => null,
			],
			'onSite' => false,
			'smsVerification' => ['phone' => '+420000000456', 'codeSentAt' => '2026-11-01T09:00:00+01:00', 'codeSendCount' => 1, 'failedAttempts' => 0],
			'verifiedAt' => '2026-11-01T09:01:00+01:00',
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
		$signature->setLegacyEvidence(new \DateTimeImmutable('2026-11-01 09:01:00'), $evidence, hash('sha256', $evidence));

		self::assertTrue(new EvidenceVerifier()->verify($signature)->isValid());
	}

	public function testProblemsHaveDescriptions(): void
	{
		foreach (EvidenceProblem::cases() as $problem) {
			self::assertNotSame('', $problem->getDescription());
		}
	}
}
