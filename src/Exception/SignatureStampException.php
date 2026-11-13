<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Exception;

use ADT\BiometricSignature\Pdf\SignatureBox;

/**
 * Podpis se do PDF nepodařilo vložit (SignatureStamper): dokument nemá označené místo pro
 * podpis, nebo ho nejde přečíst.
 */
final class SignatureStampException extends \RuntimeException
{
	/** @param list<string> $names */
	public static function boxNotFound(array $names): self
	{
		return new self(sprintf(
			'Dokument nemá označené místo pro podpis: %s. Místo se označuje odkazem na %s<název> (SignatureBox).',
			implode(', ', $names),
			SignatureBox::URL,
		));
	}

	public static function unreadable(\Throwable $previous): self
	{
		return new self(
			'PDF se nepodařilo přečíst nebo sestavit (zašifrované PDF a PDF s komprimovanou strukturou nejsou podporované): ' . $previous->getMessage(),
			0,
			$previous,
		);
	}
}
