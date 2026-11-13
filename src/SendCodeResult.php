<?php

declare(strict_types=1);

namespace ADT\BiometricSignature;

/** Výsledek odeslání ověřovacího kódu (BiometricSigning::sendCode()) */
enum SendCodeResult
{
	case Sent;

	/** Další kód až po uplynutí rozestupu (BiometricSigning::getResendWaitSeconds()) */
	case Cooldown;

	/** Vyčerpaný počet kódů k jednomu podpisu */
	case LimitReached;

	/** Podpis je už ověřený nebo odkaz na ověření vypršel */
	case NotAllowed;

	/** Projekt kód neodeslal (CodeNotSentException) */
	case Failed;
}
