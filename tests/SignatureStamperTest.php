<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Tests;

use ADT\BiometricSignature\Exception\SignatureStampException;
use ADT\BiometricSignature\Pdf\SignatureBox;
use ADT\BiometricSignature\Pdf\SignatureStamper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfParser\Type\PdfArray;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfString;
use setasign\Fpdi\PdfParser\Type\PdfType;
use setasign\Fpdi\PdfReader\PdfReader;

/**
 * Vložení podpisu do PDF: dokument si místo pro podpis označí odkazem na
 * https://signature.invalid/<název> a podpis přijde přesně do jeho plochy.
 * Stránky dokumentu i jeho odkazy zůstávají, z podepsaného PDF zmizí jen
 * označení podepsaného místa.
 */
final class SignatureStamperTest extends TestCase
{
	protected function setUp(): void
	{
		if (!class_exists(\FPDF::class) || !class_exists(Fpdi::class) || !function_exists('imagecreatetruecolor')) {
			self::markTestSkipped('Chybí setasign/fpdf, setasign/fpdi nebo rozšíření GD.');
		}
	}

	public function testFindsMarkedBoxesWithPositionAndSize(): void
	{
		$pdf = $this->document([
			[1, 50, 100, 200, 20, 'https://example.com/'],
			[2, 60, 600, 170, 57, SignatureBox::url('advisor')],
			[2, 320, 600, 170, 57, SignatureBox::url('client')],
		]);

		$boxes = new SignatureStamper()->findBoxes($pdf);

		// obyčejný odkaz místo pro podpis není
		self::assertSame(['advisor', 'client'], array_map(fn (SignatureBox $box): string => $box->name, $boxes));

		$client = $boxes[1];
		self::assertSame(2, $client->page);
		self::assertEqualsWithDelta(320.0, $client->x, 0.1);
		self::assertEqualsWithDelta(600.0, $client->y, 0.1);
		self::assertEqualsWithDelta(170.0, $client->width, 0.1);
		self::assertEqualsWithDelta(57.0, $client->height, 0.1);
	}

	public function testBoxNameIsUrlPartAfterPrefix(): void
	{
		// bez lomítka na konci a parametrů; adresa bez názvu místo neoznačuje
		$pdf = $this->document([
			[1, 50, 600, 170, 57, 'https://signature.invalid/client/'],
			[1, 300, 600, 170, 57, 'https://signature.invalid/podpis%20klienta?x=1'],
			[2, 50, 600, 170, 57, 'https://signature.invalid/'],
		]);

		self::assertSame(
			['client', 'podpis klienta'],
			array_map(fn (SignatureBox $box): string => $box->name, new SignatureStamper()->findBoxes($pdf)),
		);
	}

	public function testStampsSignatureIntoItsBox(): void
	{
		$stamper = new SignatureStamper();
		$pdf = $this->document([
			[2, 60, 600, 170, 57, SignatureBox::url('advisor')],
			[2, 320, 600, 170, 57, SignatureBox::url('client')],
		]);

		$signed = $stamper->stamp($pdf, ['client' => $this->signature()]);

		// stránky zůstaly a podpis je v dokumentu jednou
		self::assertSame(2, $this->pageCount($signed));
		self::assertSame(1, $this->imageDraws($signed));
		self::assertSame(0, $this->imageDraws($pdf));

		// označení podepsaného místa zmizelo, místo druhé strany zůstalo - jde podepsat později
		$left = $stamper->findBoxes($signed);
		self::assertSame(['advisor'], array_map(fn (SignatureBox $box): string => $box->name, $left));
		self::assertEqualsWithDelta(60.0, $left[0]->x, 0.1);
		self::assertEqualsWithDelta(600.0, $left[0]->y, 0.1);

		$both = $stamper->stamp($signed, ['advisor' => $this->signature()]);
		self::assertSame([], $stamper->findBoxes($both));
	}

	/** @param SignatureStamper::WRITER_* $writer */
	#[DataProvider('writers')]
	public function testKeepsOrdinaryLinks(string $writer): void
	{
		if ($writer === SignatureStamper::WRITER_TCPDF && !class_exists(\TCPDF::class)) {
			self::markTestSkipped('Chybí tecnickcom/tcpdf.');
		}

		$pdf = $this->document([
			[1, 50, 100, 200, 20, 'https://example.com/podminky?a=1'],
			[2, 50, 700, 120, 15, 'mailto:info@example.com'],
			[2, 320, 600, 170, 57, SignatureBox::url('client')],
		]);

		$signed = new SignatureStamper($writer)->stamp($pdf, ['client' => $this->signature()]);

		// odkaz na web i e-mail zůstal klikací na stejném místě
		self::assertSame(
			[
				[1, 'https://example.com/podminky?a=1', 50.0, 100.0, 200.0, 20.0],
				[2, 'mailto:info@example.com', 50.0, 700.0, 120.0, 15.0],
			],
			$this->links($signed),
		);
	}

	public function testStampsEveryBoxOfTheName(): void
	{
		$pdf = $this->document([
			[1, 320, 600, 170, 57, SignatureBox::url('client')],
			[2, 320, 600, 170, 57, SignatureBox::url('client')],
		]);

		$signed = new SignatureStamper()->stamp($pdf, ['client' => $this->signature()]);

		self::assertSame(2, $this->pageCount($signed));
		self::assertSame(2, $this->imageDraws($signed));
	}

	/** @param SignatureStamper::WRITER_* $writer */
	#[DataProvider('writers')]
	public function testWritesSignedPdf(string $writer): void
	{
		if ($writer === SignatureStamper::WRITER_TCPDF && !class_exists(\TCPDF::class)) {
			self::markTestSkipped('Chybí tecnickcom/tcpdf.');
		}

		$pdf = $this->document([[2, 320, 600, 170, 57, SignatureBox::url('client')]]);
		$signed = new SignatureStamper($writer)->stamp($pdf, ['client' => $this->signature()]);

		self::assertSame(2, $this->pageCount($signed));
		self::assertStringContainsString('/Subtype /Image', $signed);
		// ani označení místa, ani odkaz, který si TCPDF jinak přidává na poslední stránku
		self::assertStringNotContainsString('/Subtype /Link', $signed);
	}

	/** @return iterable<string, array{SignatureStamper::WRITER_*}> */
	public static function writers(): iterable
	{
		yield 'FPDF' => [SignatureStamper::WRITER_FPDF];
		yield 'TCPDF' => [SignatureStamper::WRITER_TCPDF];
	}

	public function testDocumentWithoutBoxIsNotSigned(): void
	{
		$pdf = $this->document([[2, 60, 600, 170, 57, SignatureBox::url('advisor')]]);

		$this->expectException(SignatureStampException::class);
		$this->expectExceptionMessageMatches('~client~');

		new SignatureStamper()->stamp($pdf, ['client' => $this->signature()]);
	}

	public function testUnreadablePdfIsReported(): void
	{
		$this->expectException(SignatureStampException::class);

		new SignatureStamper()->findBoxes('tohle není PDF');
	}

	public function testHtmlBoxIsLinkOverAreaOfGivenSize(): void
	{
		$html = SignatureBox::html('client', 45, 15.5);

		self::assertStringContainsString('href="https://signature.invalid/client"', $html);
		self::assertStringContainsString('width:45mm;height:15.5mm', $html);
		self::assertSame('https://signature.invalid/podpis%20klienta', SignatureBox::url('podpis klienta'));
	}

	/**
	 * Dokument o dvou stránkách A4 (jednotky = body); místa pro podpis jsou odkazy.
	 *
	 * @param list<array{int, float, float, float, float, string}> $links [stránka, x, y, šířka, výška, adresa]
	 */
	private function document(array $links): string
	{
		$pdf = new \FPDF('P', 'pt', 'A4');
		$pdf->SetFont('Helvetica', '', 12);

		foreach ([1, 2] as $page) {
			$pdf->AddPage();
			$pdf->Text(50, 60, 'Smlouva, strana ' . $page);

			foreach ($links as [$linkPage, $x, $y, $width, $height, $url]) {
				if ($linkPage === $page) {
					$pdf->Link($x, $y, $width, $height, $url);
				}
			}
		}

		return $pdf->Output('S');
	}

	private function signature(): string
	{
		$image = imagecreatetruecolor(800, 240);
		imagealphablending($image, false);
		imagesavealpha($image, true);
		imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 255, 255, 255, 127));
		imagefilledrectangle($image, 200, 80, 500, 160, (int) imagecolorallocate($image, 20, 33, 61));

		ob_start();
		imagepng($image);

		return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
	}

	/**
	 * Odkazy na adresu v PDF: [stránka, adresa, x, y, šířka, výška] v bodech od levého horního rohu.
	 *
	 * @return list<array{int, string, float, float, float, float}>
	 */
	private function links(string $pdf): array
	{
		$parser = new PdfParser(StreamReader::createByString($pdf));
		$reader = new PdfReader($parser);
		$links = [];

		for ($number = 1; $number <= $reader->getPageCount(); $number++) {
			$page = $reader->getPage($number);
			$annotations = $page->getAttribute('Annots');
			$pageSize = $page->getWidthAndHeight();
			self::assertIsArray($pageSize);
			$pageHeight = (float) $pageSize[1];

			if ($annotations === null) {
				continue;
			}

			foreach (PdfArray::ensure(PdfType::resolve($annotations, $parser))->value as $reference) {
				$annotation = PdfDictionary::ensure(PdfType::resolve($reference, $parser))->value;
				$action = PdfDictionary::ensure(PdfType::resolve($annotation['A'], $parser))->value;
				$rectangle = array_map(
					fn (PdfType $value): float => (float) PdfNumeric::ensure(PdfType::resolve($value, $parser))->value,
					PdfArray::ensure(PdfType::resolve($annotation['Rect'], $parser))->value,
				);
				$uri = PdfType::resolve($action['URI'], $parser);
				self::assertInstanceOf(PdfString::class, $uri);

				$links[] = [
					$number,
					PdfString::unescape($uri->value),
					round(min($rectangle[0], $rectangle[2]), 1),
					round($pageHeight - max($rectangle[1], $rectangle[3]), 1),
					round(abs($rectangle[2] - $rectangle[0]), 1),
					round(abs($rectangle[3] - $rectangle[1]), 1),
				];
			}
		}

		return $links;
	}

	private function pageCount(string $pdf): int
	{
		return new Fpdi()->setSourceFile(StreamReader::createByString($pdf));
	}

	/** Kolikrát se v obsahu stránek kreslí obrázek (operátor Do nad obrázkem I1) */
	private function imageDraws(string $pdf): int
	{
		preg_match_all('~stream\r?\n(.*?)\r?\nendstream~s', $pdf, $matches);
		$draws = 0;

		foreach ($matches[1] as $stream) {
			$content = @gzuncompress($stream);
			$draws += substr_count($content === false ? $stream : $content, '/I1 Do');
		}

		return $draws;
	}
}
