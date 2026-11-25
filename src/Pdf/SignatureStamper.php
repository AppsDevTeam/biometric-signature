<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Pdf;

use ADT\BiometricSignature\Exception\SignatureStampException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfParser\Type\PdfArray;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfHexString;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfString;
use setasign\Fpdi\PdfParser\Type\PdfType;
use setasign\Fpdi\PdfReader\DataStructure\Rectangle;
use setasign\Fpdi\PdfReader\Page;
use setasign\Fpdi\PdfReader\PdfReader;

/**
 * Vloží obrázek podpisu do PDF na místo, které si dokument sám označil (SignatureBox,
 * README, Podpis do PDF).
 *
 * Stránky dokumentu zůstávají, jak jsou - podpis se jen přidá přes ně, takže podepsané PDF je
 * dokument, který podepisující viděl. Obrázek se ořízne na tah a vejde do označeného místa
 * (SignatureImage). S podpisem jde vložit i popisek - ID podpisu a čas (SignatureStamp); vejde se
 * do téhož místa pod obrázek. Potřebuje balíček setasign/fpdi a k němu setasign/fpdf, nebo tecnickcom/tcpdf
 * (některé projekty FPDF mít nemůžou - koliduje jim s jiným balíčkem); použije se ten, který
 * projekt má.
 *
 * Odkazy na web a e-mail v dokumentu zůstávají klikací a zůstává i označení míst, do kterých
 * se teď nepodepisovalo (podpis další strany jde doplnit později). Označení podepsaného místa
 * zmizí.
 *
 * Omezení: z podepsaného PDF zmizí odkazy uvnitř dokumentu (na jinou stránku) a pole formulářů,
 * nejde číst zašifrované PDF ani PDF s komprimovanou strukturou (PDF 1.5+ s xref streamem)
 * a odkazy i místa pro podpis na otočených stránkách se přeskakují.
 *
 * @phpstan-type Link array{page: int, url: string, x: float, y: float, width: float, height: float}
 */
final class SignatureStamper
{
	public const string WRITER_FPDF = 'fpdf';
	public const string WRITER_TCPDF = 'tcpdf';

	/** Velikost písma popisku pod podpisem v bodech; zmenší se, když se řádek do místa nevejde */
	private const float CAPTION_FONT_SIZE = 5.0;
	private const float CAPTION_MIN_FONT_SIZE = 3.0;
	private const float CAPTION_LINE_HEIGHT = 1.25;

	/** Jak velkou část výšky místa smí popisek zabrat - zbytek patří obrázku podpisu */
	private const float CAPTION_MAX_SHARE = 0.45;

	/**
	 * @param self::WRITER_*|null $writer čím se podepsané PDF zapisuje, null = co je nainstalované
	 *        (přednost má FPDF)
	 */
	public function __construct(
		private readonly ?string $writer = null,
	) {
	}

	/**
	 * Místa pro podpis označená v PDF, v pořadí stránek.
	 *
	 * @return list<SignatureBox>
	 * @throws SignatureStampException PDF nejde přečíst
	 */
	public function findBoxes(string $pdf): array
	{
		$boxes = [];

		foreach ($this->findLinks($pdf) as $link) {
			$box = $this->createBox($link);

			if ($box !== null) {
				$boxes[] = $box;
			}
		}

		return $boxes;
	}

	/**
	 * Vloží podpisy do označených míst a vrátí podepsané PDF.
	 *
	 * @param array<string, string|SignatureStamp> $signatures název místa => obrázek podpisu
	 *        (data URI, PNG) nebo podpis s popiskem; podpis přijde do všech míst toho názvu,
	 *        ostatní místa zůstanou prázdná a označená
	 * @throws SignatureStampException dokument nemá místo některého názvu, nebo ho nejde přečíst
	 */
	public function stamp(string $pdf, array $signatures): string
	{
		$links = $this->findLinks($pdf);
		$found = [];

		foreach ($links as $link) {
			$box = $this->createBox($link);

			if ($box !== null) {
				$found[$box->name] = true;
			}
		}

		$missing = array_values(array_diff(array_keys($signatures), array_keys($found)));

		if ($missing) {
			throw SignatureStampException::boxNotFound($missing);
		}

		return $this->write($pdf, $links, fn (SignatureBox $box): string|SignatureStamp|null => $signatures[$box->name] ?? null);
	}

	/**
	 * Vloží do každého místa jeho vlastní podpis a vrátí podepsané PDF. Na rozdíl od stamp()
	 * rozlišuje i stejně pojmenovaná místa - podepisující potvrzuje každé zvlášť a může se do
	 * každého podepsat jinak.
	 *
	 * @param array<int, string|SignatureStamp> $signatures pořadí místa ve findBoxes() (od 0) =>
	 *        obrázek podpisu (data URI, PNG) nebo podpis s popiskem (každé místo má vlastní ID
	 *        a čas); místa bez podpisu zůstanou prázdná a označená
	 * @throws SignatureStampException dokument místo s některým pořadím nemá, nebo ho nejde přečíst
	 */
	public function stampBoxes(string $pdf, array $signatures): string
	{
		$links = $this->findLinks($pdf);
		$count = 0;

		foreach ($links as $link) {
			if ($this->createBox($link) !== null) {
				$count++;
			}
		}

		$missing = array_values(array_filter(array_keys($signatures), fn (int $index): bool => $index < 0 || $index >= $count));

		if ($missing) {
			throw SignatureStampException::boxIndexNotFound($missing, $count);
		}

		$index = 0;

		// místa se procházejí v pořadí findBoxes() (stránky, na stránce pořadí odkazů)
		return $this->write($pdf, $links, function () use ($signatures, &$index): string|SignatureStamp|null {
			return $signatures[$index++] ?? null;
		});
	}

	/**
	 * Sestaví PDF ze stránek dokumentu a do míst vloží podpisy.
	 *
	 * @param list<Link> $links
	 * @param \Closure(SignatureBox): (string|SignatureStamp|null) $signatureFor podpis místa,
	 *        null = místo zůstane označené; volá se pro každé místo jednou, v pořadí findBoxes()
	 * @throws SignatureStampException
	 */
	private function write(string $pdf, array $links, \Closure $signatureFor): string
	{
		try {
			$tcpdf = $this->getWriter() === self::WRITER_TCPDF;
			$output = $tcpdf ? $this->createTcpdf() : new Fpdi('P', 'pt');
			$output->SetAutoPageBreak(false);
			$pageCount = $output->setSourceFile(StreamReader::createByString($pdf));

			for ($number = 1; $number <= $pageCount; $number++) {
				$template = $output->importPage($number);
				$size = $output->getTemplateSize($template);

				if (!is_array($size)) {
					throw new \RuntimeException(sprintf('Stránku %d se nepodařilo převzít.', $number));
				}

				$output->AddPage($size['orientation'], [$size['width'], $size['height']]);
				$output->useTemplate($template);

				foreach ($links as $link) {
					if ($link['page'] !== $number) {
						continue;
					}

					$box = $this->createBox($link);
					$signature = $box === null ? null : $signatureFor($box);

					// Stránky se přebírají bez odkazů - obyčejný odkaz i označení místa, do kterého
					// se teď nepodepisuje, se proto zapíše znovu
					if ($box === null || $signature === null) {
						$output->Link($link['x'], $link['y'], $link['width'], $link['height'], $link['url']);

						continue;
					}

					$stamp = SignatureStamp::of($signature);
					$captionHeight = $this->writeCaption($output, $box, $stamp->getCaptionLines());
					$imageHeight = $box->height - $captionHeight;

					// obrázek má poměr stran své části místa, takže ji vyplní celou a tah zůstane uprostřed
					$image = SignatureImage::fit($stamp->image, $box->width / $imageHeight);

					if ($tcpdf) {
						// TCPDF bere obrázek jako data za zavináčem
						$output->Image('@' . self::decode($image), $box->x, $box->y, $box->width, $imageHeight, 'PNG');
					} else {
						$output->Image($image, $box->x, $box->y, $box->width, $imageHeight, 'png');
					}
				}
			}

			return $tcpdf ? $output->Output('', 'S') : $output->Output('S');
		} catch (SignatureStampException $e) {
			throw $e;
		} catch (\Exception $e) {
			throw SignatureStampException::unreadable($e);
		}
	}

	/**
	 * Napíše popisek do spodní části místa a vrátí výšku, kterou zabral (0 = bez popisku).
	 *
	 * Písmo se zmenší, aby se nejdelší řádek vešel na šířku místa a popisek nezabral víc než
	 * CAPTION_MAX_SHARE jeho výšky; pod CAPTION_MIN_FONT_SIZE se už nezmenšuje (v hodně malém
	 * místě popisek přesáhne).
	 *
	 * @param list<string> $lines
	 */
	private function writeCaption(Fpdi|\setasign\Fpdi\Tcpdf\Fpdi $output, SignatureBox $box, array $lines): float
	{
		if (!$lines) {
			return 0.0;
		}

		$font = $output instanceof Fpdi ? 'Helvetica' : 'helvetica';
		$size = min(self::CAPTION_FONT_SIZE, $box->height * self::CAPTION_MAX_SHARE / (count($lines) * self::CAPTION_LINE_HEIGHT));
		$output->SetFont($font, '', $size);
		$widest = max(array_map(fn (string $line): float => (float) $output->GetStringWidth($line), $lines));

		if ($widest > $box->width) {
			$size *= $box->width / $widest;
		}

		$size = max($size, self::CAPTION_MIN_FONT_SIZE);
		$lineHeight = $size * self::CAPTION_LINE_HEIGHT;
		$height = count($lines) * $lineHeight;
		$top = $box->y + $box->height - $height;

		$output->SetFont($font, '', $size);
		$output->SetTextColor(110, 110, 110);

		foreach ($lines as $index => $line) {
			if ($output instanceof Fpdi) {
				// FPDF: y je účaří
				$output->Text($box->x, $top + $index * $lineHeight + $size * 0.85, $line);
			} else {
				// TCPDF: y je horní okraj řádku
				$output->setCellPaddings(0, 0, 0, 0);
				$output->Text($box->x, $top + $index * $lineHeight, $line);
			}
		}

		return $height;
	}

	/**
	 * Odkazy na adresu (web, e-mail, označení míst pro podpis) v PDF, v pořadí stránek.
	 *
	 * @return list<Link>
	 * @throws SignatureStampException PDF nejde přečíst
	 */
	private function findLinks(string $pdf): array
	{
		$this->requireFpdi();

		try {
			$parser = new PdfParser(StreamReader::createByString($pdf));
			$reader = new PdfReader($parser);
			$links = [];

			for ($number = 1; $number <= $reader->getPageCount(); $number++) {
				array_push($links, ...$this->findPageLinks($reader->getPage($number), $parser, $number));
			}

			return $links;
		} catch (\Exception $e) {
			throw SignatureStampException::unreadable($e);
		}
	}

	/**
	 * @return list<Link>
	 */
	private function findPageLinks(Page $page, PdfParser $parser, int $number): array
	{
		// Anotace mají souřadnice neotočené stránky - na otočené by odkaz i podpis přišly jinam
		if ($page->getRotation() !== 0) {
			return [];
		}

		$annotations = $page->getAttribute('Annots');

		if ($annotations === null) {
			return [];
		}

		$annotations = PdfType::resolve($annotations, $parser);

		if (!$annotations instanceof PdfArray) {
			return [];
		}

		// Stránka nemusí začínat v nule - souřadnice se počítají od jejího levého horního rohu
		$boundary = $page->getBoundary();

		if (!$boundary instanceof Rectangle) {
			return [];
		}

		$links = [];

		foreach ($annotations->value as $reference) {
			$annotation = PdfType::resolve($reference, $parser);

			if (!$annotation instanceof PdfDictionary) {
				continue;
			}

			$url = $this->getUrl($annotation, $parser);
			$rectangle = $this->getRectangle($annotation, $parser);

			if ($url === null || $rectangle === null) {
				continue;
			}

			[$left, $bottom, $right, $top] = $rectangle;

			$links[] = [
				'page' => $number,
				'url' => $url,
				'x' => $left - $boundary->getLlx(),
				'y' => $boundary->getUry() - $top,
				'width' => $right - $left,
				'height' => $top - $bottom,
			];
		}

		return $links;
	}

	/**
	 * Místo pro podpis z odkazu, null = obyčejný odkaz.
	 *
	 * @param Link $link
	 */
	private function createBox(array $link): ?SignatureBox
	{
		if (!str_starts_with($link['url'], SignatureBox::URL)) {
			return null;
		}

		// některé nástroje k adrese přidají lomítko, parametry za otazníkem nejsou součást názvu
		$name = rawurldecode(trim((string) strtok(substr($link['url'], strlen(SignatureBox::URL)), '?#'), '/'));

		return $name === ''
			? null
			: new SignatureBox($name, $link['page'], $link['x'], $link['y'], $link['width'], $link['height']);
	}

	/** Adresa, na kterou anotace odkazuje, null = není odkaz na adresu */
	private function getUrl(PdfDictionary $annotation, PdfParser $parser): ?string
	{
		if (!isset($annotation->value['A'])) {
			return null;
		}

		$action = PdfType::resolve($annotation->value['A'], $parser);

		if (!$action instanceof PdfDictionary || !isset($action->value['URI'])) {
			return null;
		}

		$uri = PdfType::resolve($action->value['URI'], $parser);

		if ($uri instanceof PdfHexString) {
			$url = (string) hex2bin($uri->value);
		} elseif ($uri instanceof PdfString) {
			$url = PdfString::unescape($uri->value);
		} else {
			return null;
		}

		return $url === '' ? null : $url;
	}

	/**
	 * Plocha anotace [vlevo, dole, vpravo, nahoře] v bodech, null = bez plochy.
	 *
	 * @return array{float, float, float, float}|null
	 */
	private function getRectangle(PdfDictionary $annotation, PdfParser $parser): ?array
	{
		if (!isset($annotation->value['Rect'])) {
			return null;
		}

		$rectangle = PdfType::resolve($annotation->value['Rect'], $parser);

		if (!$rectangle instanceof PdfArray || count($rectangle->value) !== 4) {
			return null;
		}

		$numbers = [];

		foreach ($rectangle->value as $value) {
			$value = PdfType::resolve($value, $parser);

			if (!$value instanceof PdfNumeric) {
				return null;
			}

			$numbers[] = (float) $value->value;
		}

		// rohy můžou být zapsané v libovolném pořadí
		$left = min($numbers[0], $numbers[2]);
		$right = max($numbers[0], $numbers[2]);
		$bottom = min($numbers[1], $numbers[3]);
		$top = max($numbers[1], $numbers[3]);

		return $right > $left && $top > $bottom ? [$left, $bottom, $right, $top] : null;
	}

	/** @return self::WRITER_* */
	private function getWriter(): string
	{
		if ($this->writer !== null) {
			return $this->writer;
		}

		// Některé balíčky (iio/libmergepdf) si definují vlastní FPDF jako potomka TCPDF - s ním
		// Fpdi pro FPDF nejde ani načíst, takže se píše přes TCPDF
		$hasFpdf = class_exists(\FPDF::class) && !in_array('TCPDF', class_parents(\FPDF::class) ?: [], true);

		return $hasFpdf ? self::WRITER_FPDF : self::WRITER_TCPDF;
	}

	/** TCPDF bez hlavičky, patičky, okrajů a bez svého odkazu na poslední stránce */
	private function createTcpdf(): \setasign\Fpdi\Tcpdf\Fpdi
	{
		$output = new class ('P', 'pt') extends \setasign\Fpdi\Tcpdf\Fpdi {
			public function __construct(string $orientation, string $unit)
			{
				parent::__construct($orientation, $unit);
				$this->tcpdflink = false;
			}
		};
		$output->setPrintHeader(false);
		$output->setPrintFooter(false);
		$output->SetMargins(0, 0, 0);

		return $output;
	}

	private static function decode(string $dataUri): string
	{
		$comma = strpos($dataUri, ',');

		return (string) base64_decode($comma === false ? $dataUri : substr($dataUri, $comma + 1), true);
	}

	private function requireFpdi(): void
	{
		if (!class_exists(PdfParser::class)) {
			throw new \LogicException('Vložení podpisu do PDF potřebuje balíček setasign/fpdi (composer require setasign/fpdi) a k němu setasign/fpdf nebo tecnickcom/tcpdf.');
		}

		$writerClass = $this->getWriter() === self::WRITER_TCPDF ? \TCPDF::class : \FPDF::class;

		if (!class_exists($writerClass)) {
			throw new \LogicException('Vložení podpisu do PDF potřebuje k setasign/fpdi ještě setasign/fpdf nebo tecnickcom/tcpdf (composer require setasign/fpdf).');
		}
	}
}
