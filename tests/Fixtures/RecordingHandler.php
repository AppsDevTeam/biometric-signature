<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Tests\Fixtures;

use ADT\BiometricSignature\Entity\BiometricSignatureInterface;
use ADT\BiometricSignature\Exception\CodeNotSentException;
use ADT\BiometricSignature\SigningHandler;

/** Handler projektu pro testy: pamatuje si odeslané kódy a místo PDF uloží pevný obsah */
final class RecordingHandler implements SigningHandler
{
	/** @var list<string> */
	public array $sentCodes = [];

	public bool $failSending = false;

	public bool $failOnVerified = false;

	public int $verifiedCount = 0;

	public const PDF = '%PDF-1.7 test';

	/** @param array<mixed> $subject */
	public function __construct(
		public array $subject = ['document' => 'Smlouva', 'person' => ['id' => 42, 'name' => 'Jan Novák']],
	) {
	}

	public function sendCode(BiometricSignatureInterface $signature, string $code): void
	{
		if ($this->failSending) {
			throw new CodeNotSentException('Brána SMS neodpověděla.');
		}
		$this->sentCodes[] = $code;
	}

	public function getEvidenceSubject(BiometricSignatureInterface $signature): array
	{
		return $this->subject;
	}

	public function onVerified(BiometricSignatureInterface $signature): void
	{
		if ($this->failOnVerified) {
			throw new \RuntimeException('PDF se nepodařilo vygenerovat.');
		}
		$this->verifiedCount++;
		$signature->recordPdf(self::PDF);
	}

	public function lastCode(): string
	{
		if ($this->sentCodes === []) {
			throw new \LogicException('Žádný kód neodešel.');
		}

		return $this->sentCodes[count($this->sentCodes) - 1];
	}
}
