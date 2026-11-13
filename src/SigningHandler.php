<?php

declare(strict_types=1);

namespace ADT\BiometricSignature;

use ADT\BiometricSignature\Entity\BiometricSignatureInterface;
use ADT\BiometricSignature\Exception\CodeNotSentException;

/**
 * Co k podpisu dodává projekt: odeslání kódu, obsah důkazního záznamu
 * a co se má stát po ověření (typicky PDF).
 *
 * Projekt s více druhy podpisů (formuláře, smlouvy …) rozliší druh podle
 * třídy entity.
 */
interface SigningHandler
{
	/**
	 * Pošle kód na $signature->getPhone() (SMS). Text zprávy je na projektu,
	 * platnost kódu je v SigningPolicy::$codeValidMinutes.
	 *
	 * @throws CodeNotSentException brána kód neodeslala (původní chybu předat jako $previous a zalogovat)
	 */
	public function sendCode(BiometricSignatureInterface $signature, string $code): void;

	/**
	 * Co se podepisuje: kdo, jaký dokument, vyplněné hodnoty, otisk originálu …
	 * Uloží se do důkazního záznamu jako "subject" a otisk záznamu ho tak
	 * pokrývá. Jen hodnoty serializovatelné do JSON.
	 *
	 * @return array<mixed>
	 */
	public function getEvidenceSubject(BiometricSignatureInterface $signature): array;

	/**
	 * Podpis je právě ověřený, důkazní záznam a otisk jsou nastavené.
	 * Tady projekt vygeneruje PDF (s otiskem getDocumentHash()) a zavolá
	 * $signature->recordPdf(). Volá se před flush: když metoda vyhodí výjimku
	 * a požadavek už nic dalšího neflushne, podpis zůstane v DB neověřený
	 * a ověření jde zopakovat se stejným kódem.
	 */
	public function onVerified(BiometricSignatureInterface $signature): void;
}
