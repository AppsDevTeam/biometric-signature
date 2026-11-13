<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Evidence;

use ADT\BiometricSignature\Entity\BiometricSignatureInterface;
use ADT\BiometricSignature\Json;

/**
 * Ověří, že se podpis od ověření nezměnil: otisk sedí na důkazní záznam,
 * záznam na uložený obrázek a biometriku a případně PDF na uložený otisk.
 *
 * Chrání proti změně jedné části bez ostatních (úprava PDF, záměna obrázku
 * v DB). Nechrání proti někomu, kdo má zápis do DB i úložiště a přepočítá
 * všechno - to vyřeší až pečeť s kvalifikovaným časovým razítkem.
 */
class EvidenceVerifier
{
	/**
	 * @param string|null $pdfContent obsah PDF k porovnání s uloženým otiskem; null = PDF se nekontroluje
	 */
	public function verify(BiometricSignatureInterface $signature, ?string $pdfContent = null): EvidenceVerification
	{
		$pdfChecked = $pdfContent !== null;
		$evidenceJson = $signature->getEvidenceJson();
		$documentHash = $signature->getDocumentHash();
		if (!$signature->isVerified() || $evidenceJson === null || $documentHash === null) {
			return new EvidenceVerification([EvidenceProblem::NotVerified], $pdfChecked);
		}

		$problems = [];
		if (!hash_equals($documentHash, Evidence::hash($evidenceJson))) {
			$problems[] = EvidenceProblem::DocumentHashMismatch;
		}

		$evidence = Json::decodeArray($evidenceJson);
		$imageHash = $evidence['signature']['imageSha256'] ?? null;
		$biometricsHash = $evidence['signature']['biometricsSha256'] ?? null;
		if (!is_string($imageHash) || !is_string($biometricsHash)) {
			$problems[] = EvidenceProblem::EvidenceUnreadable;
		} else {
			if (!hash_equals($imageHash, Evidence::imageHash($signature))) {
				$problems[] = EvidenceProblem::ImageHashMismatch;
			}
			if (!hash_equals($biometricsHash, Evidence::biometricsHash($signature))) {
				$problems[] = EvidenceProblem::BiometricsHashMismatch;
			}
		}

		if ($pdfContent !== null) {
			$pdfHash = $signature->getPdfHash();
			if ($pdfHash === null) {
				$problems[] = EvidenceProblem::PdfHashMissing;
			} elseif (!hash_equals($pdfHash, hash('sha256', $pdfContent))) {
				$problems[] = EvidenceProblem::PdfHashMismatch;
			}
		}

		return new EvidenceVerification($problems, $pdfChecked);
	}
}
