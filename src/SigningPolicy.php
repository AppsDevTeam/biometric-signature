<?php

declare(strict_types=1);

namespace ADT\BiometricSignature;

/**
 * Limity ověření podpisu kódem. Výchozí hodnoty: 6místný kód platí 10 minut,
 * na kód je 5 pokusů, k jednomu podpisu jdou poslat nejvýš 3 kódy s rozestupem
 * 60 s a celý odkaz na ověření platí 60 minut. Uhodnout kód je tak
 * 15 pokusů z milionu.
 */
final readonly class SigningPolicy
{
	public function __construct(
		/** Jak dlouho od podpisu jde podpis ověřit (stránka se zadáním kódu) */
		public int $linkValidMinutes = 60,
		/** Platnost jednoho kódu */
		public int $codeValidMinutes = 10,
		/** Chybné pokusy na jeden kód, pak je nutné poslat nový */
		public int $maxCodeAttempts = 5,
		/** Kolik kódů lze k jednomu podpisu poslat celkem */
		public int $maxCodeSends = 3,
		/** Minimální rozestup opakovaného odeslání kódu */
		public int $resendCooldownSeconds = 60,
		/** Počet číslic kódu */
		public int $codeLength = 6,
	) {
		foreach (get_object_vars($this) as $name => $value) {
			if ($value < ($name === 'resendCooldownSeconds' ? 0 : 1)) {
				throw new \InvalidArgumentException(sprintf('SigningPolicy::$%s musí být kladné číslo, je %d.', $name, $value));
			}
		}
		if ($codeLength > 9) {
			throw new \InvalidArgumentException('Kód může mít nejvýš 9 číslic.');
		}
	}
}
