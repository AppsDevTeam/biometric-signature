<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Evidence;

/** Výsledek ověření podpisu (EvidenceVerifier::verify()) */
final readonly class EvidenceVerification
{
	/**
	 * @param list<EvidenceProblem> $problems
	 * @param bool $pdfChecked bylo předané PDF, takže se kontroloval i jeho otisk
	 */
	public function __construct(
		public array $problems,
		public bool $pdfChecked,
	) {
	}

	public function isValid(): bool
	{
		return $this->problems === [];
	}

	public function hasProblem(EvidenceProblem $problem): bool
	{
		return in_array($problem, $this->problems, true);
	}
}
