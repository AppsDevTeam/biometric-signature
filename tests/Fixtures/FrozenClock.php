<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Tests\Fixtures;

use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
	public function __construct(
		private \DateTimeImmutable $now = new \DateTimeImmutable('2026-11-13 10:00:00', new \DateTimeZone('Europe/Prague')),
	) {
	}

	public function now(): \DateTimeImmutable
	{
		return $this->now;
	}

	public function advance(string $modifier): void
	{
		$this->now = $this->now->modify($modifier);
	}
}
