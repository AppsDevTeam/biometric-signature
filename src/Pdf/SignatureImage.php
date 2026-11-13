<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Pdf;

/**
 * Obrázek podpisu připravený ke vložení do dokumentu (README, Podpis do PDF).
 *
 * Podpisové pole na obrazovce je velké (často celá šířka stránky) a místo pro podpis v dokumentu malé.
 * Celé plátno by se zmenšilo i s prázdným místem a podpis psaný menším písmem by byl v dokumentu
 * drobný. Obrázek se proto ořízne na samotný tah a doplní na daný poměr stran - podpis je
 * v dokumentu stejně velký, ať ho člověk napsal přes celé pole, nebo jen do jeho části,
 * a nezáleží ani na šířce displeje.
 *
 * Původní obrázek zůstává beze změny u podpisu (BiometricSignatureTrait) - z něj se počítá
 * otisk v důkazním záznamu.
 */
final class SignatureImage
{
	/** Výchozí poměr stran výsledku (šířka : výška) - obvyklý tvar místa pro podpis */
	public const float DEFAULT_RATIO = 3.0;

	/** Okraj kolem tahu jako podíl výšky výřezu, ať se tah nedotýká hrany */
	private const float PADDING = 0.05;

	/** Nejmenší výřez jako podíl výšky plátna - tečka nebo krátká čárka se neroztáhne přes celý podpis */
	private const float MIN_HEIGHT = 0.25;

	/** Průhlednost (GD: 0 neprůhledné - 127 průhledné), od které už bod není tah */
	private const int ALPHA_LIMIT = 110;

	/** Světlost, od které je neprůhledný bod pozadí (plátno s bílým podkladem) */
	private const int WHITE_LIMIT = 245;

	/**
	 * Ořízne obrázek podpisu (data URI) na tah a doplní ho na poměr stran místa, kam přijde
	 * (šířka : výška). Obrázek, který nejde načíst, nebo prázdné plátno vrací beze změny.
	 */
	public static function fit(string $dataUri, float $ratio = self::DEFAULT_RATIO): string
	{
		if ($ratio <= 0) {
			throw new \InvalidArgumentException('Poměr stran obrázku podpisu musí být kladný.');
		}

		$comma = strpos($dataUri, ',');
		$contents = base64_decode($comma === false ? $dataUri : substr($dataUri, $comma + 1), true);
		$source = $contents === false || $contents === '' ? false : @imagecreatefromstring($contents);

		if ($source === false) {
			return $dataUri;
		}

		imagepalettetotruecolor($source);
		$box = self::findInk($source);

		if ($box === null) {
			return $dataUri;
		}

		[$left, $top, $right, $bottom] = $box;
		$inkWidth = $right - $left + 1;
		$inkHeight = $bottom - $top + 1;

		// Výška výřezu: podle tahu, podle jeho šířky (poměr stran) a aspoň kus plátna
		$height = max($inkHeight, $inkWidth / $ratio, imagesy($source) * self::MIN_HEIGHT);
		$height = max(1, (int) ceil($height * (1 + 2 * self::PADDING)));
		$width = max(1, (int) ceil($height * $ratio));

		$target = imagecreatetruecolor($width, $height);
		imagealphablending($target, false);
		imagesavealpha($target, true);
		imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 255, 255, 255, 127));

		// Tah beze změny měřítka doprostřed - na velikost místa v dokumentu ho zmenší až PDF
		imagecopy(
			$target,
			$source,
			intdiv($width - $inkWidth, 2),
			intdiv($height - $inkHeight, 2),
			$left,
			$top,
			$inkWidth,
			$inkHeight,
		);

		ob_start();
		imagepng($target);
		$png = (string) ob_get_clean();

		return $png === '' ? $dataUri : 'data:image/png;base64,' . base64_encode($png);
	}

	/**
	 * Obdélník, do kterého se vejde celý tah: [vlevo, nahoře, vpravo, dole], null = prázdné plátno.
	 * Hledá se od okrajů dovnitř, takže se většina plátna neprochází.
	 *
	 * @return array{int, int, int, int}|null
	 */
	private static function findInk(\GdImage $image): ?array
	{
		$width = imagesx($image);
		$height = imagesy($image);

		$rowHasInk = static function (int $y) use ($image, $width): bool {
			for ($x = 0; $x < $width; $x++) {
				if (self::isInk($image, $x, $y)) {
					return true;
				}
			}

			return false;
		};

		$top = 0;
		while ($top < $height && !$rowHasInk($top)) {
			$top++;
		}

		if ($top === $height) {
			return null;
		}

		$bottom = $height - 1;
		while ($bottom > $top && !$rowHasInk($bottom)) {
			$bottom--;
		}

		$columnHasInk = static function (int $x) use ($image, $top, $bottom): bool {
			for ($y = $top; $y <= $bottom; $y++) {
				if (self::isInk($image, $x, $y)) {
					return true;
				}
			}

			return false;
		};

		$left = 0;
		while (!$columnHasInk($left)) {
			$left++;
		}

		$right = $width - 1;
		while ($right > $left && !$columnHasInk($right)) {
			$right--;
		}

		return [$left, $top, $right, $bottom];
	}

	/** Bod tahu = není průhledný ani bílý (plátno bývá průhledné, starší podpisy mají bílý podklad) */
	private static function isInk(\GdImage $image, int $x, int $y): bool
	{
		$color = (int) imagecolorat($image, $x, $y);

		if ((($color >> 24) & 0x7F) >= self::ALPHA_LIMIT) {
			return false;
		}

		return (($color >> 16) & 0xFF) < self::WHITE_LIMIT
			|| (($color >> 8) & 0xFF) < self::WHITE_LIMIT
			|| ($color & 0xFF) < self::WHITE_LIMIT;
	}
}
