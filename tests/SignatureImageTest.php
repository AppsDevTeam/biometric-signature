<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Tests;

use ADT\BiometricSignature\Pdf\SignatureImage;
use PHPUnit\Framework\TestCase;

/**
 * Obrázek podpisu do dokumentu: ořízne se na samotný tah a doplní na poměr
 * stran místa pro podpis, takže podpis je v dokumentu stejně velký bez ohledu
 * na to, jak velkou část podpisového pole člověk popsal a jak široké bylo plátno.
 */
final class SignatureImageTest extends TestCase
{
	protected function setUp(): void
	{
		if (!function_exists('imagecreatetruecolor')) {
			self::markTestSkipped('Chybí rozšíření GD.');
		}
	}

	public function testSmallSignatureFillsImage(): void
	{
		// tah přes třetinu výšky plátna zabere po ořezu většinu výšky obrázku
		$fitted = $this->decode(SignatureImage::fit($this->canvas(1584, 480, [100, 60, 500, 220])));

		self::assertEqualsWithDelta(3.0, imagesx($fitted) / imagesy($fitted), 0.02);
		self::assertGreaterThan(0.85, $this->inkHeight($fitted) / imagesy($fitted));
		self::assertLessThan(480, imagesy($fitted));
	}

	public function testImageHasRatioOfItsPlace(): void
	{
		$canvas = $this->canvas(1584, 480, [100, 60, 500, 220]);

		foreach ([1.5, 3.0, 5.0] as $ratio) {
			$fitted = $this->decode(SignatureImage::fit($canvas, $ratio));
			self::assertEqualsWithDelta($ratio, imagesx($fitted) / imagesy($fitted), 0.03);
		}
	}

	public function testRejectsNonPositiveRatio(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		SignatureImage::fit($this->canvas(600, 200, [10, 10, 100, 100]), 0);
	}

	public function testResultDoesNotDependOnCanvasWidth(): void
	{
		$narrow = $this->decode(SignatureImage::fit($this->canvas(1584, 480, [100, 60, 700, 300])));
		$wide = $this->decode(SignatureImage::fit($this->canvas(2400, 480, [900, 60, 1500, 300])));

		self::assertSame([imagesx($narrow), imagesy($narrow)], [imagesx($wide), imagesy($wide)]);
	}

	public function testWideSignatureKeepsRatio(): void
	{
		// dlouhý nízký tah se vejde na šířku - výšku určí poměr stran
		$fitted = $this->decode(SignatureImage::fit($this->canvas(1584, 480, [20, 200, 1560, 240])));

		self::assertEqualsWithDelta(3.0, imagesx($fitted) / imagesy($fitted), 0.02);
		self::assertGreaterThanOrEqual(1541, imagesx($fitted));
	}

	public function testDotIsNotBlownUp(): void
	{
		// výřez má aspoň čtvrtinu výšky plátna
		$fitted = $this->decode(SignatureImage::fit($this->canvas(1584, 480, [800, 240, 806, 246])));

		self::assertGreaterThanOrEqual(120, imagesy($fitted));
	}

	public function testWhiteBackgroundIsNotInk(): void
	{
		$transparent = $this->decode(SignatureImage::fit($this->canvas(1584, 480, [100, 60, 400, 160])));
		$white = $this->decode(SignatureImage::fit($this->canvas(1584, 480, [100, 60, 400, 160], white: true)));

		self::assertSame([imagesx($transparent), imagesy($transparent)], [imagesx($white), imagesy($white)]);
	}

	public function testEmptyOrBrokenImageIsReturnedAsIs(): void
	{
		$empty = $this->canvas(600, 200, null);

		self::assertSame($empty, SignatureImage::fit($empty));
		self::assertSame('data:image/png;base64,nesmysl', SignatureImage::fit('data:image/png;base64,nesmysl'));
		self::assertSame('', SignatureImage::fit(''));
	}

	/**
	 * Plátno podpisu jako data URI; tah = vyplněný obdélník [x1, y1, x2, y2], null = bez tahu.
	 *
	 * @param int<1, max> $width
	 * @param int<1, max> $height
	 * @param array{int, int, int, int}|null $ink
	 */
	private function canvas(int $width, int $height, ?array $ink, bool $white = false): string
	{
		$image = imagecreatetruecolor($width, $height);
		imagealphablending($image, false);
		imagesavealpha($image, true);
		$background = $white
			? imagecolorallocate($image, 255, 255, 255)
			: imagecolorallocatealpha($image, 255, 255, 255, 127);
		imagefill($image, 0, 0, (int) $background);

		if ($ink !== null) {
			imagefilledrectangle($image, $ink[0], $ink[1], $ink[2], $ink[3], (int) imagecolorallocate($image, 20, 33, 61));
		}

		ob_start();
		imagepng($image);

		return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
	}

	private function decode(string $dataUri): \GdImage
	{
		$image = imagecreatefromstring((string) base64_decode(substr($dataUri, (int) strpos($dataUri, ',') + 1), true));
		self::assertInstanceOf(\GdImage::class, $image);

		return $image;
	}

	/** Počet řádků obrázku, ve kterých je tah (tmavý neprůhledný bod) */
	private function inkHeight(\GdImage $image): int
	{
		$rows = 0;

		for ($y = 0; $y < imagesy($image); $y++) {
			for ($x = 0; $x < imagesx($image); $x++) {
				$color = (int) imagecolorat($image, $x, $y);

				if ((($color >> 24) & 0x7F) < 100 && ($color & 0xFF) < 200) {
					$rows++;

					break;
				}
			}
		}

		return $rows;
	}
}
