<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Tests;

use ADT\BiometricSignature\BiometricSigning;
use ADT\BiometricSignature\Evidence\Evidence;
use ADT\BiometricSignature\SendCodeResult;
use ADT\BiometricSignature\SigningPolicy;
use ADT\BiometricSignature\Tests\Fixtures\FrozenClock;
use ADT\BiometricSignature\Tests\Fixtures\RecordingHandler;
use ADT\BiometricSignature\Tests\Fixtures\SampleInput;
use ADT\BiometricSignature\Tests\Fixtures\TestSignature;
use ADT\BiometricSignature\VerifyCodeResult;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class BiometricSigningTest extends TestCase
{
	private FrozenClock $clock;

	private RecordingHandler $handler;

	private int $flushes = 0;

	private BiometricSigning $signing;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock();
		$this->handler = new RecordingHandler();
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$entityManager->method('flush')->willReturnCallback(function (): void {
			$this->flushes++;
		});
		$this->signing = new BiometricSigning($entityManager, $this->handler, new SigningPolicy(), $this->clock);
	}

	private function createSignature(bool $onSite = false): TestSignature
	{
		$signature = new TestSignature(SampleInput::capture(), '+420 000 000 456', $this->clock->now());
		$signature->setOnSite($onSite);
		$signature->setRequestContext('192.0.2.10', 'Mozilla/5.0 (iPad)');

		return $signature;
	}

	public function testSendsCodeAndStoresOnlyItsHash(): void
	{
		$signature = $this->createSignature();

		self::assertSame(SendCodeResult::Sent, $this->signing->sendCode($signature));

		$code = $this->handler->lastCode();
		self::assertMatchesRegularExpression('~^\d{6}$~', $code);
		self::assertNotSame($code, $signature->getCodeHash());
		self::assertTrue(password_verify($code, (string) $signature->getCodeHash()));
		self::assertSame(1, $signature->getCodeSendCount());
		self::assertSame(1, $this->flushes);
	}

	public function testCorrectCodeVerifiesSignatureWithEvidence(): void
	{
		$signature = $this->createSignature();
		$this->signing->sendCode($signature);
		$this->clock->advance('+2 minutes');

		self::assertSame(VerifyCodeResult::Verified, $this->signing->verifyCode($signature, $this->handler->lastCode()));

		self::assertTrue($signature->isVerified());
		self::assertEquals($this->clock->now(), $signature->getVerifiedAt());
		self::assertNull($signature->getCodeHash());
		self::assertSame(1, $this->handler->verifiedCount);
		self::assertSame(hash('sha256', RecordingHandler::PDF), $signature->getPdfHash());

		$evidenceJson = (string) $signature->getEvidenceJson();
		self::assertSame(hash('sha256', $evidenceJson), $signature->getDocumentHash());
		self::assertSame([
			'format' => Evidence::FORMAT,
			'version' => Evidence::VERSION,
			'subject' => $this->handler->subject,
			'signature' => [
				'imageSha256' => hash('sha256', SampleInput::PNG),
				'biometricsSha256' => hash('sha256', $signature->getBiometricsJson()),
				'signedAt' => '2026-11-13T10:00:00+01:00',
				'ipAddress' => '192.0.2.10',
				'userAgent' => 'Mozilla/5.0 (iPad)',
			],
			'onSite' => false,
			'codeVerification' => [
				'phone' => '+420 000 000 456',
				'codeSentAt' => '2026-11-13T10:00:00+01:00',
				'codeSendCount' => 1,
				'failedAttempts' => 0,
			],
			'verifiedAt' => '2026-11-13T10:02:00+01:00',
		], json_decode($evidenceJson, true));
	}

	public function testCodeWithSpacesIsAccepted(): void
	{
		$signature = $this->createSignature();
		$this->signing->sendCode($signature);
		$code = $this->handler->lastCode();

		self::assertSame(VerifyCodeResult::Verified, $this->signing->verifyCode($signature, ' ' . substr($code, 0, 3) . ' ' . substr($code, 3) . "\n"));
	}

	public function testWrongCodeIsCountedAndLocksAfterMaxAttempts(): void
	{
		$signature = $this->createSignature();
		$this->signing->sendCode($signature);
		$code = $this->handler->lastCode();
		$wrong = $code === '000000' ? '111111' : '000000';

		for ($attempt = 1; $attempt < 5; $attempt++) {
			self::assertSame(VerifyCodeResult::WrongCode, $this->signing->verifyCode($signature, $wrong));
		}
		self::assertSame(VerifyCodeResult::CodeLocked, $this->signing->verifyCode($signature, $wrong));
		self::assertSame(5, $signature->getCodeAttempts());
		// Každý chybný pokus se uloží hned, jinak by šel kód hádat donekonečna
		self::assertSame(1 + 5, $this->flushes);

		self::assertSame(VerifyCodeResult::CodeLocked, $this->signing->verifyCode($signature, $code));
		self::assertFalse($signature->isVerified());
	}

	public function testNewCodeReplacesOldOneAndResetsAttempts(): void
	{
		$signature = $this->createSignature();
		$this->signing->sendCode($signature);
		$oldCode = $this->handler->lastCode();
		$signature->codeFailed();

		self::assertSame(SendCodeResult::Cooldown, $this->signing->sendCode($signature));
		self::assertSame(60, $this->signing->getResendWaitSeconds($signature));
		$this->clock->advance('+45 seconds');
		self::assertSame(15, $this->signing->getResendWaitSeconds($signature));
		$this->clock->advance('+15 seconds');
		self::assertSame(SendCodeResult::Sent, $this->signing->sendCode($signature));
		self::assertSame(0, $signature->getCodeAttempts());

		$newCode = $this->handler->lastCode();
		if ($newCode !== $oldCode) {
			self::assertSame(VerifyCodeResult::WrongCode, $this->signing->verifyCode($signature, $oldCode));
		}
		self::assertSame(VerifyCodeResult::Verified, $this->signing->verifyCode($signature, $newCode));
	}

	public function testSendLimit(): void
	{
		$signature = $this->createSignature();
		for ($send = 0; $send < 3; $send++) {
			self::assertSame(SendCodeResult::Sent, $this->signing->sendCode($signature));
			$this->clock->advance('+1 minute');
		}

		self::assertSame(SendCodeResult::LimitReached, $this->signing->sendCode($signature));
		self::assertCount(3, $this->handler->sentCodes);
	}

	public function testFailedSendingIsNotCounted(): void
	{
		$signature = $this->createSignature();
		$this->handler->failSending = true;

		self::assertSame(SendCodeResult::Failed, $this->signing->sendCode($signature));
		self::assertSame(0, $signature->getCodeSendCount());
		self::assertNull($signature->getCodeHash());
		self::assertSame(0, $this->flushes);
	}

	public function testCodeExpires(): void
	{
		$signature = $this->createSignature();
		self::assertSame(VerifyCodeResult::CodeExpired, $this->signing->verifyCode($signature, '123456'));

		$this->signing->sendCode($signature);
		$this->clock->advance('+10 minutes');
		self::assertFalse($this->signing->isCodeExpired($signature));
		$this->clock->advance('+1 second');

		self::assertSame(VerifyCodeResult::CodeExpired, $this->signing->verifyCode($signature, $this->handler->lastCode()));
	}

	public function testLinkExpires(): void
	{
		$signature = $this->createSignature();
		$this->signing->sendCode($signature);
		$this->clock->advance('+61 minutes');

		self::assertTrue($this->signing->isLinkExpired($signature));
		self::assertSame(VerifyCodeResult::LinkExpired, $this->signing->verifyCode($signature, $this->handler->lastCode()));
		self::assertSame(SendCodeResult::NotAllowed, $this->signing->sendCode($signature));
	}

	public function testVerifiedSignatureCannotBeVerifiedAgain(): void
	{
		$signature = $this->createSignature();
		$this->signing->sendCode($signature);
		$code = $this->handler->lastCode();
		$this->signing->verifyCode($signature, $code);
		$evidence = $signature->getEvidenceJson();

		self::assertSame(VerifyCodeResult::AlreadyVerified, $this->signing->verifyCode($signature, $code));
		self::assertSame(SendCodeResult::NotAllowed, $this->signing->sendCode($signature));
		self::assertSame($evidence, $signature->getEvidenceJson());
		self::assertSame(1, $this->handler->verifiedCount);
	}

	public function testCompletesOnSiteWithoutCode(): void
	{
		$signature = $this->createSignature(onSite: true);

		$this->signing->completeOnSite($signature);

		self::assertTrue($signature->isVerified());
		$evidence = json_decode((string) $signature->getEvidenceJson(), true);
		self::assertTrue($evidence['onSite']);
		self::assertNull($evidence['codeVerification']);
		self::assertSame(1, $this->flushes);
		self::assertSame([], $this->handler->sentCodes);
	}

	public function testOnSiteCompletionRequiresOnSiteSignature(): void
	{
		$this->expectException(\LogicException::class);

		$this->signing->completeOnSite($this->createSignature());
	}

	public function testOnSiteCompletionCannotRepeat(): void
	{
		$signature = $this->createSignature(onSite: true);
		$this->signing->completeOnSite($signature);

		$this->expectException(\LogicException::class);
		$this->signing->completeOnSite($signature);
	}

	public function testFailureAfterVerificationIsNotFlushed(): void
	{
		$signature = $this->createSignature();
		$this->signing->sendCode($signature);
		$this->handler->failOnVerified = true;

		try {
			$this->signing->verifyCode($signature, $this->handler->lastCode());
			self::fail('Výjimka z onVerified() se má propsat volajícímu.');
		} catch (\RuntimeException $e) {
			self::assertSame('PDF se nepodařilo vygenerovat.', $e->getMessage());
		}
		self::assertSame(1, $this->flushes);
	}

	public function testCustomPolicy(): void
	{
		$entityManager = $this->createStub(EntityManagerInterface::class);
		$signing = new BiometricSigning($entityManager, $this->handler, new SigningPolicy(codeLength: 4, maxCodeSends: 1), $this->clock);
		$signature = $this->createSignature();

		self::assertSame(SendCodeResult::Sent, $signing->sendCode($signature));
		self::assertMatchesRegularExpression('~^\d{4}$~', $this->handler->lastCode());
		$this->clock->advance('+5 minutes');
		self::assertSame(SendCodeResult::LimitReached, $signing->sendCode($signature));
	}

	public function testPolicyRejectsNonsense(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		new SigningPolicy(maxCodeAttempts: 0);
	}

	public function testMasksPhone(): void
	{
		self::assertSame('•••••••••456', $this->createSignature()->getMaskedPhone());
	}
}
