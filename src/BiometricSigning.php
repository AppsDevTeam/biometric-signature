<?php

declare(strict_types=1);

namespace ADT\BiometricSignature;

use ADT\BiometricSignature\Clock\SystemClock;
use ADT\BiometricSignature\Entity\BiometricSignatureInterface;
use ADT\BiometricSignature\Evidence\Evidence;
use ADT\BiometricSignature\Exception\CodeNotSentException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Průběh podpisu po zachycení:
 *
 * 1. projekt vytvoří entitu ze SignatureCapture::fromInput() a uloží ji
 *    (neověřený podpis)
 * 2a. sendCode() + verifyCode() - kód z SMS váže podpis na telefon
 *     podepisujícího, nebo
 * 2b. completeOnSite() - na zařízení provozovatele ověřuje totožnost personál
 * 3. po ověření vznikne důkazní záznam s otiskem SHA-256 a projekt v
 *    SigningHandler::onVerified() vygeneruje PDF
 *
 * Biometrika dělá z obrázku dynamický biometrický podpis, kód z SMS ho váže
 * na telefon podepisujícího.
 *
 * sendCode(), verifyCode() a completeOnSite() flushují samy - počet chybných
 * pokusů se musí uložit i při špatném kódu, jinak by šel kód hádat donekonečna.
 */
class BiometricSigning
{
	public function __construct(
		private readonly EntityManagerInterface $entityManager,
		private readonly SigningHandler $handler,
		private readonly SigningPolicy $policy = new SigningPolicy(),
		private readonly ClockInterface $clock = new SystemClock(),
	) {
	}

	public function getPolicy(): SigningPolicy
	{
		return $this->policy;
	}

	/**
	 * Vygeneruje a pošle nový kód (předchozí tím přestává platit).
	 * Limity hlídá sám, volající jen ukáže výsledek.
	 */
	public function sendCode(BiometricSignatureInterface $signature): SendCodeResult
	{
		if ($signature->isVerified() || $this->isLinkExpired($signature)) {
			return SendCodeResult::NotAllowed;
		}
		if (!$this->hasCodeSendsLeft($signature)) {
			return SendCodeResult::LimitReached;
		}
		if ($this->getResendWaitSeconds($signature) > 0) {
			return SendCodeResult::Cooldown;
		}

		$length = $this->policy->codeLength;
		$code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

		try {
			$this->handler->sendCode($signature, $code);
		} catch (CodeNotSentException) {
			return SendCodeResult::Failed;
		}

		$signature->codeSent(password_hash($code, PASSWORD_DEFAULT), $this->clock->now());
		$this->entityManager->flush();

		return SendCodeResult::Sent;
	}

	/**
	 * Ověří kód. Správný kód podpis dokončí: důkazní záznam s otiskem
	 * a SigningHandler::onVerified() (PDF).
	 */
	public function verifyCode(BiometricSignatureInterface $signature, string $code): VerifyCodeResult
	{
		if ($signature->isVerified()) {
			return VerifyCodeResult::AlreadyVerified;
		}
		if ($this->isLinkExpired($signature)) {
			return VerifyCodeResult::LinkExpired;
		}
		$codeHash = $signature->getCodeHash();
		if ($codeHash === null || $this->isCodeExpired($signature)) {
			return VerifyCodeResult::CodeExpired;
		}
		if ($this->isCodeLocked($signature)) {
			return VerifyCodeResult::CodeLocked;
		}

		// Mezery z kopírování SMS („123 456“) nevadí
		$code = preg_replace('~\s+~', '', $code) ?? '';
		if (!password_verify($code, $codeHash)) {
			$signature->codeFailed();
			$this->entityManager->flush();

			return $this->isCodeLocked($signature) ? VerifyCodeResult::CodeLocked : VerifyCodeResult::WrongCode;
		}

		$this->complete($signature);

		return VerifyCodeResult::Verified;
	}

	/**
	 * Podpis na zařízení provozovatele bez kódu - totožnost ověřil personál,
	 * podpis platí rovnou. Důkazní záznam i PDF vzniknou stejně jako po
	 * ověření kódem, záznam uvádí "onSite": true a "codeVerification": null.
	 */
	public function completeOnSite(BiometricSignatureInterface $signature): void
	{
		if (!$signature->isOnSite() || $signature->isVerified()) {
			throw new \LogicException('Bez kódu jde dokončit jen neověřený podpis na zařízení provozovatele (onSite).');
		}

		$this->complete($signature);
	}

	/** Odkaz na ověření vypršel - neověřený podpis už nejde dokončit */
	public function isLinkExpired(BiometricSignatureInterface $signature): bool
	{
		return $signature->getCreatedAt() < $this->clock->now()->modify('-' . $this->policy->linkValidMinutes . ' minutes');
	}

	public function isCodeExpired(BiometricSignatureInterface $signature): bool
	{
		$sentAt = $signature->getCodeSentAt();

		return $sentAt === null
			|| $sentAt < $this->clock->now()->modify('-' . $this->policy->codeValidMinutes . ' minutes');
	}

	/** Aktuální kód je vyčerpaný chybnými pokusy */
	public function isCodeLocked(BiometricSignatureInterface $signature): bool
	{
		return $signature->getCodeAttempts() >= $this->policy->maxCodeAttempts;
	}

	public function hasCodeSendsLeft(BiometricSignatureInterface $signature): bool
	{
		return $signature->getCodeSendCount() < $this->policy->maxCodeSends;
	}

	/** Za kolik sekund jde poslat další kód (0 = hned) */
	public function getResendWaitSeconds(BiometricSignatureInterface $signature): int
	{
		$sentAt = $signature->getCodeSentAt();
		if ($sentAt === null) {
			return 0;
		}

		$elapsed = $this->clock->now()->getTimestamp() - $sentAt->getTimestamp();

		return max(0, $this->policy->resendCooldownSeconds - $elapsed);
	}

	private function complete(BiometricSignatureInterface $signature): void
	{
		$now = $this->clock->now();
		$signature->markVerified($now, Evidence::build($signature, $this->handler->getEvidenceSubject($signature), $now));
		$this->handler->onVerified($signature);
		$this->entityManager->flush();
	}
}
