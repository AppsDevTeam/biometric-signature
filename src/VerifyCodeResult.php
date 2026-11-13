<?php

declare(strict_types=1);

namespace ADT\BiometricSignature;

/** Výsledek ověření kódu (BiometricSigning::verifyCode()) */
enum VerifyCodeResult
{
	case Verified;

	case WrongCode;

	/** Kód vypršel nebo ještě žádný neodešel - je potřeba poslat nový */
	case CodeExpired;

	/** Vyčerpané pokusy na aktuální kód - je potřeba poslat nový */
	case CodeLocked;

	/** Vypršel celý odkaz na ověření - podepsat znovu */
	case LinkExpired;

	case AlreadyVerified;
}
