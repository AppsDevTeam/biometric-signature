<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Evidence;

/** Co při ověření podpisu nesedí (EvidenceVerifier::verify()) */
enum EvidenceProblem: string
{
	/** Podpis ještě nebyl ověřen - důkazní záznam neexistuje */
	case NotVerified = 'notVerified';

	/** Otisk neodpovídá důkaznímu záznamu - záznam nebo otisk někdo změnil */
	case DocumentHashMismatch = 'documentHashMismatch';

	/** Důkazní záznam není čitelný JSON s otisky obrázku a biometriky */
	case EvidenceUnreadable = 'evidenceUnreadable';

	/** Uložený obrázek podpisu je jiný než ten, který byl ověřen */
	case ImageHashMismatch = 'imageHashMismatch';

	/** Uložená biometrika je jiná než ta, která byla ověřena */
	case BiometricsHashMismatch = 'biometricsHashMismatch';

	/** K podpisu není uložený otisk PDF */
	case PdfHashMissing = 'pdfHashMissing';

	/** Předané PDF není to, které vzniklo při podpisu */
	case PdfHashMismatch = 'pdfHashMismatch';

	public function getDescription(): string
	{
		return match ($this) {
			self::NotVerified => 'Podpis nebyl ověřen, důkazní záznam neexistuje.',
			self::DocumentHashMismatch => 'Otisk neodpovídá důkaznímu záznamu.',
			self::EvidenceUnreadable => 'Důkazní záznam nejde přečíst.',
			self::ImageHashMismatch => 'Obrázek podpisu se od ověření změnil.',
			self::BiometricsHashMismatch => 'Biometrický záznam se od ověření změnil.',
			self::PdfHashMissing => 'K podpisu není uložený otisk PDF.',
			self::PdfHashMismatch => 'PDF není to, které vzniklo při podpisu.',
		};
	}
}
