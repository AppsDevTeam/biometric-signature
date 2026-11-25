<?php

declare(strict_types=1);

namespace ADT\BiometricSignature;

/**
 * Čitelná podoba hlavičky User-Agent pro auditní stopu: „Chrome 158.0.0.0 (Macintosh)“.
 * Surová hlavička zůstává v důkazním záznamu, tohle je jen její popis pro člověka.
 *
 * Hlavičku posílá prohlížeč a jde podvrhnout. iPad v režimu „web pro počítače“ se hlásí jako
 * Macintosh.
 */
final class UserAgentSummary
{
	/** Prohlížeče v pořadí rozpoznání - odvozené se hlásí i jako Chrome nebo Safari */
	private const array BROWSERS = [
		'Edge' => '~\b(?:Edg|EdgA|EdgiOS)/([\d.]+)~',
		'Opera' => '~\b(?:OPR|OPiOS)/([\d.]+)~',
		'Samsung Internet' => '~\bSamsungBrowser/([\d.]+)~',
		'Firefox' => '~\b(?:Firefox|FxiOS)/([\d.]+)~',
		'Chrome' => '~\b(?:Chrome|CriOS)/([\d.]+)~',
		'Safari' => '~\bVersion/([\d.]+).*\bSafari/~',
	];

	/** Zařízení v pořadí rozpoznání - Android se hlásí i jako Linux, iPhone a iPad i jako Mac OS X */
	private const array PLATFORMS = [
		'iPad' => '~\biPad\b~',
		'iPhone' => '~\biPhone\b~',
		'Android' => '~\bAndroid\b~',
		'Windows' => '~\bWindows\b~',
		'Macintosh' => '~\bMacintosh\b~',
		'Chrome OS' => '~\bCrOS\b~',
		'Linux' => '~\bLinux\b~',
	];

	/**
	 * @return string|null „prohlížeč verze (zařízení)“; null, když hlavička chybí. Co se nepozná,
	 *         se vynechá - z neznámé hlavičky zbude její začátek.
	 */
	public static function describe(?string $userAgent): ?string
	{
		$userAgent = trim((string) $userAgent);

		if ($userAgent === '') {
			return null;
		}

		$browser = null;

		foreach (self::BROWSERS as $name => $pattern) {
			if (preg_match($pattern, $userAgent, $match) === 1) {
				$browser = $name . ' ' . $match[1];

				break;
			}
		}

		$platform = null;

		foreach (self::PLATFORMS as $name => $pattern) {
			if (preg_match($pattern, $userAgent) === 1) {
				$platform = $name;

				break;
			}
		}

		if ($browser === null && $platform === null) {
			return mb_strimwidth($userAgent, 0, 80, '…');
		}

		return $browser === null ? $platform : ($platform === null ? $browser : sprintf('%s (%s)', $browser, $platform));
	}
}
