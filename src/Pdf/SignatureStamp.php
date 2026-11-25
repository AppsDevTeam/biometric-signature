<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Pdf;

/**
 * Podpis vkládaný do PDF i s popiskem pod ním: ID podpisu a čas, kdy se podepisující do místa
 * podepsal. Stejné ID a čas nese auditní stopa, takže podpis v dokumentu jde spárovat se záznamem
 * o tom, kdo, odkud a jak ho udělal.
 *
 * Popisek je v PDF skutečný text (jde vyhledat a zkopírovat), ne součást obrázku.
 */
final readonly class SignatureStamp
{
	/** Formát času pod podpisem (v časovém pásmu předaného data) */
	public const string DATE_FORMAT = 'd.m.Y H:i:s';

	/**
	 * @param string $image obrázek podpisu (data URI, PNG)
	 * @param string|null $id ID podpisu (např. SignatureId::generate()) - jen tisknutelné ASCII,
	 *        nejvýš 64 znaků; null = bez řádku s ID
	 * @param \DateTimeInterface|null $signedAt čas podpisu; null = bez řádku s časem
	 */
	public function __construct(
		public string $image,
		public ?string $id = null,
		public ?\DateTimeInterface $signedAt = null,
	) {
		if ($id !== null && preg_match('~^[\x21-\x7E]{1,64}$~', $id) !== 1) {
			throw new \InvalidArgumentException('ID podpisu smí mít 1 až 64 tisknutelných znaků ASCII bez mezer.');
		}
	}

	/** Samotný obrázek (bez popisku) nebo hotový podpis s popiskem */
	public static function of(string|self $signature): self
	{
		return $signature instanceof self ? $signature : new self($signature);
	}

	/**
	 * Řádky pod podpisem, shora dolů.
	 *
	 * @return list<string>
	 */
	public function getCaptionLines(): array
	{
		$lines = [];

		if ($this->id !== null) {
			$lines[] = 'ID: ' . $this->id;
		}

		if ($this->signedAt !== null) {
			$lines[] = $this->signedAt->format(self::DATE_FORMAT);
		}

		return $lines;
	}
}
