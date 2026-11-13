<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Pdf;

/**
 * Místo pro podpis v PDF (README, Podpis do PDF).
 *
 * Dokument si místo označí sám: odkazem na adresu `https://signature.invalid/<název>` přes
 * plochu, kam má podpis přijít. Odkaz umí zapsat každý nástroj, kterým PDF vzniká (HTML šablona
 * přes mPDF nebo Chrome, TCPDF, Word, LibreOffice) a v dokumentu není vidět. SignatureStamper
 * ho v hotovém PDF najde a podpis vloží přesně do jeho plochy; z podepsaného PDF odkaz zmizí.
 * Doména `.invalid` je vyhrazená a nikam nevede.
 *
 * Název rozlišuje, čí podpis na místo patří (`client`, `advisor` …). Stejně pojmenovaných míst
 * může být v dokumentu víc - podpis přijde do všech.
 */
final class SignatureBox
{
	/** Začátek adresy odkazu, kterým dokument označuje místo pro podpis; za ním je název místa */
	public const string URL = 'https://signature.invalid/';

	/** Průhledný obrázek 1 x 1 bod - plocha odkazu v HTML šabloně */
	private const string BLANK_IMAGE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADUlEQVQImWP4//8/AwAI/AL+hc2rNAAAAABJRU5ErkJggg==';

	public function __construct(
		/** Název místa - část adresy odkazu za SignatureBox::URL */
		public readonly string $name,
		/** Číslo stránky od 1 */
		public readonly int $page,
		/** Levý horní roh od levého horního rohu stránky, v bodech (1/72 palce) */
		public readonly float $x,
		public readonly float $y,
		/** Rozměry v bodech */
		public readonly float $width,
		public readonly float $height,
	) {
	}

	/** Adresa odkazu, kterým se místo v dokumentu označí */
	public static function url(string $name): string
	{
		return self::URL . rawurlencode($name);
	}

	/**
	 * Místo pro podpis do HTML šablony dokumentu - stačí ho vypsat tam, kam má podpis přijít
	 * (v Latte `{SignatureBox::html('client', 45, 15)|noescape}`, vkládá se bez escapování).
	 * Je to průhledná plocha dané velikosti s odkazem: v dokumentu není vidět, jen podpisu
	 * drží místo, a SignatureStamper podle ní pozná, kam podpis vložit. Když se stejná šablona
	 * zobrazí v prohlížeči (tisk), na odkaz nejde kliknout.
	 */
	public static function html(string $name, float $widthMm = 60, float $heightMm = 20): string
	{
		return sprintf(
			'<a href="%s" style="text-decoration:none;pointer-events:none"><img src="%s" alt="" style="width:%smm;height:%smm;border:0" /></a>',
			htmlspecialchars(self::url($name), ENT_QUOTES),
			self::BLANK_IMAGE,
			self::number($widthMm),
			self::number($heightMm),
		);
	}

	/** Číslo do CSS - s tečkou bez ohledu na locale */
	private static function number(float $value): string
	{
		return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
	}
}
