<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Entity;

use ADT\DoctrineAnonymization\AnonymizationTypeInterface;

/**
 * Obraz a biometrika podpisu jsou zvláštní kategorie osobních údajů
 * (čl. 9 GDPR) - v anonymizovaných pohledech se maskují vždy, i na
 * entitách s výjimkou z anonymizace.
 */
enum BiometricAnonymizationType: string implements AnonymizationTypeInterface
{
	/** Klíč je součást seedu pseudonymů - neměnit */
	case BIOMETRIC = 'biometric';

	public function key(): string
	{
		return $this->value;
	}

	public function isAnonymized(): bool
	{
		return true;
	}

	public function alwaysAnonymize(): bool
	{
		return true;
	}

	public function isDirectIdentifier(): bool
	{
		return false;
	}
}
