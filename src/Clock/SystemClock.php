<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Clock;

use Psr\Clock\ClockInterface;

/** Výchozí hodiny BiometricSigning, když projekt žádné PSR-20 hodiny nemá */
final class SystemClock implements ClockInterface
{
	public function now(): \DateTimeImmutable
	{
		return new \DateTimeImmutable();
	}
}
