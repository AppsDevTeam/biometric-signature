# biometric-signature

Dynamický biometrický podpis na dotykovém zařízení (tablet, telefon): podpis
na plátně se záznamem průběhu psaní, potvrzení kódem z SMS nebo personálem na
místě a důkazní záznam s otiskem SHA-256, který se tiskne do PDF.

```bash
composer require adt/biometric-signature
```

## Co to je

Podepisující se podepíše prstem nebo perem. Vznikne:

- **obrázek** podpisu (PNG z plátna),
- **biometrický záznam**: souřadnice v čase, tlak, náklon a natočení pera,
  typ vstupu. Z obrázku podpis ve sporu porovnat nejde, ze záznamu průběhu ano
  (grafologická expertiza).

Podpis pak potvrdí **kód z SMS** (váže podpis na telefon podepisujícího), nebo
na zařízení provozovatele (např. tablet na pobočce) **ověří totožnost personál**. Po
ověření vznikne **důkazní záznam**: co se podepsalo, otisky obrázku
a biometriky, jak a kdy byl podpis ověřen. Otisk tohoto záznamu se tiskne do
PDF a otisk PDF se uloží.

Knihovna obsahuje:

| Část | Co dělá |
|---|---|
| `assets/biometric-signature.js` | plátno (`signature_pad`) + záznam biometriky do hidden inputů |
| `SignatureCapture` | kontrola obrázku a normalizace biometriky z formuláře (vstup se dá podvrhnout) |
| `Entity\BiometricSignatureTrait` | sloupce a stav podpisu pro entitu projektu |
| `BiometricSigning` | ověřovací kód (generování, limity, pokusy) a dokončení podpisu |
| `Evidence\Evidence` | důkazní záznam a jeho otisk |
| `Evidence\EvidenceVerifier` | kontrola, že se podpis od ověření nezměnil |
| `Pdf\SignatureBox`, `Pdf\SignatureStamper` | místo pro podpis v dokumentu a vložení podpisu do hotového PDF |

Projekt dodá entitu, odeslání SMS, obsah důkazního záznamu a PDF
(`SigningHandler`) a UI (formulář s podpisem, stránku se zadáním kódu).
Knihovna nezávisí na žádném frameworku pro UI, potřebuje jen Doctrine ORM.

Jestli takový podpis v daném oboru stačí (a kdy je potřeba kvalifikovaný
elektronický podpis nebo pečeť), posuďte podle předpisů, které se na vás
vztahují.

## Entita

```php
use ADT\BiometricSignature\Entity\BiometricSignatureInterface;
use ADT\BiometricSignature\Entity\BiometricSignatureTrait;
use ADT\BiometricSignature\SignatureCapture;

#[ORM\Entity]
class FormSignature extends BaseEntity implements BiometricSignatureInterface
{
	use CreatedAt;               // getCreatedAt(): čas podpisu
	use BiometricSignatureTrait;

	#[ORM\ManyToOne(targetEntity: FormSubmission::class)]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	protected FormSubmission $submission;

	public function __construct(FormSubmission $submission, SignatureCapture $capture, string $phone)
	{
		$this->submission = $submission;
		$this->initializeBiometricSignature($capture, $phone);
	}
}
```

Trait přidá sloupce `token`, `image_data_uri`, `biometrics_json`, `phone`,
`on_site`, `ip_address`, `user_agent`, `code_hash`, `code_sent_at`,
`code_send_count`, `code_attempts`, `verified_at`, `evidence_json`,
`document_hash`, `pdf_hash` (názvy s `UnderscoreNamingStrategy`). Osobní údaje
jsou označené atributem `#[Anonymize]` pro
[adt/doctrine-anonymization](https://github.com/AppsDevTeam/doctrine-anonymization),
obrázek a biometrika jako `BiometricAnonymizationType::BIOMETRIC` (maskuje se
vždy). Balíček je volitelný, bez něj Doctrine atributy přeskočí. Entitu
nelogujte do change logu, biometrika má stovky kB.

## Handler projektu

```php
use ADT\BiometricSignature\Entity\BiometricSignatureInterface;
use ADT\BiometricSignature\Exception\CodeNotSentException;
use ADT\BiometricSignature\SigningHandler;

class FormSigningHandler implements SigningHandler
{
	public function sendCode(BiometricSignatureInterface $signature, string $code): void
	{
		try {
			$this->smsSender->send($signature->getPhone(), "Kod pro podpis je $code. Plati 10 minut.");
		} catch (SmsGatewayException $e) {
			Debugger::log($e, ILogger::EXCEPTION);
			throw new CodeNotSentException('SMS s kódem neodešla.', previous: $e);
		}
	}

	public function getEvidenceSubject(BiometricSignatureInterface $signature): array
	{
		assert($signature instanceof FormSignature);
		$submission = $signature->getSubmission();

		// Všechno, co se podepisuje - otisk důkazního záznamu to pokryje
		return [
			'person' => ['id' => $submission->getPerson()->getId(), 'name' => $submission->getPerson()->getFullName()],
			'form' => ['code' => $submission->getForm()->getCode(), 'values' => $submission->getValues()],
		];
	}

	public function onVerified(BiometricSignatureInterface $signature): void
	{
		$pdf = $this->pdfGenerator->render($signature); // s otiskem $signature->getDocumentHash()
		$signature->recordPdf($pdf);
		// uložit PDF (adt/files) …
	}
}
```

## Služby

```neon
services:
	- ADT\BiometricSignature\BiometricSigning
	- ADT\BiometricSignature\Evidence\EvidenceVerifier
	- App\Model\Signing\FormSigningHandler
	# jiné limity (výchozí viz SigningPolicy)
	- ADT\BiometricSignature\SigningPolicy(codeValidMinutes: 15)
```

`BiometricSigning` potřebuje `Doctrine\ORM\EntityManagerInterface`; má-li jich
projekt víc, předejte ho jménem (`entityManager: @…`). Hodiny jsou PSR-20
`ClockInterface`, bez služby se použijí systémové.

## Průběh podpisu

```php
// 1. formulář: kontrola a uložení neověřeného podpisu
try {
	$capture = SignatureCapture::fromInput($values['signatureImage'], $values['signatureBiometrics']);
} catch (InvalidSignatureException) {
	$form->addError('Podpis se nepodařilo zachytit. Vymažte ho prosím a podepište se znovu.');
	return;
}
$signature = new FormSignature($submission, $capture, $phone);
$signature->setOnSite($onTablet);
$signature->setRequestContext($httpRequest->getRemoteAddress(), $httpRequest->getHeader('User-Agent'));
$entityManager->persist($signature);
$entityManager->flush();

// 2a. ověření kódem z SMS
$signing->sendCode($signature);                  // SendCodeResult
// … stránka s kódem (odkaz s $signature->getToken())
$signing->verifyCode($signature, $values['code']); // VerifyCodeResult

// 2b. nebo na tabletu provozovatele bez kódu (ověřil personál)
$signing->completeOnSite($signature);
```

`sendCode()`, `verifyCode()` a `completeOnSite()` flushují samy: chybný pokus
se musí uložit i při špatném kódu, jinak by šel kód hádat donekonečna.

| `SendCodeResult` | | `VerifyCodeResult` | |
|---|---|---|---|
| `Sent` | odesláno | `Verified` | podpis platí, PDF vzniklo |
| `Cooldown` | další až za `getResendWaitSeconds()` | `WrongCode` | zkusit znovu |
| `LimitReached` | vyčerpané kódy | `CodeExpired` | poslat nový kód |
| `NotAllowed` | ověřeno nebo odkaz vypršel | `CodeLocked` | vyčerpané pokusy, poslat nový kód |
| `Failed` | SMS neodešla | `LinkExpired` | podepsat znovu |
| | | `AlreadyVerified` | |

Výchozí limity (`SigningPolicy`): 6místný kód platí 10 minut, 5 pokusů na kód,
nejvýš 3 kódy s rozestupem 60 s, odkaz na ověření platí 60 minut.

## Důkazní záznam a ověření

```json
{
	"format": "adt/biometric-signature",
	"version": 1,
	"subject": { "…": "co dodá SigningHandler::getEvidenceSubject()" },
	"signature": {
		"imageSha256": "…",
		"biometricsSha256": "…",
		"signedAt": "2026-11-13T10:00:00+01:00",
		"ipAddress": "192.0.2.10",
		"userAgent": "…"
	},
	"onSite": false,
	"codeVerification": {
		"phone": "+420000000456",
		"codeSentAt": "2026-11-13T10:00:00+01:00",
		"codeSendCount": 1,
		"failedAttempts": 0
	},
	"verifiedAt": "2026-11-13T10:02:00+01:00"
}
```

Záznam se ukládá doslova do `evidence_json`, `document_hash` je jeho SHA-256
(tiskne se do PDF), `pdf_hash` je SHA-256 vygenerovaného PDF.
`codeVerification` je `null`, když podpis ověřil personál na místě.

```php
$result = $evidenceVerifier->verify($signature, $pdfContent); // PDF nepovinné
$result->isValid();
$result->problems; // list<EvidenceProblem>, getDescription() pro uživatele
```

Ověření pozná změnu jedné části bez ostatních (upravené PDF, vyměněný obrázek
nebo biometrika v DB, upravený důkazní záznam).

**Co otisk nezaručí:** všechno počítá a ukládá aplikace sama. Kdo má zápis do
DB i úložiště, může záznam upravit, přepočítat otisky a vygenerovat nové PDF.
Čas podpisu je čas serveru. Nezávislé potvrzení přinese až elektronická pečeť
PDF (PAdES) s kvalifikovaným časovým razítkem (RFC 3161), viz Plán.

## Podpis do PDF

Dokument si místo pro podpis označí sám a `SignatureStamper` do něj obrázek
podpisu vloží. Stránky dokumentu zůstávají, jak jsou - podpis se jen přidá přes
ně, takže podepsané PDF je dokument, který podepisující viděl.

**Označení místa** je odkaz na adresu `https://signature.invalid/<název>` přes
plochu, kam má podpis přijít. Název říká, čí podpis na místo patří (`client`,
`advisor` …); stejně pojmenovaných míst může být víc a podpis přijde do všech.
Odkaz umí zapsat každý nástroj, kterým PDF vzniká, a doména `.invalid` je
vyhrazená, takže nikam nevede.

V HTML šabloně (mPDF, Chrome, wkhtmltopdf) stačí místo vypsat tam, kam má podpis
přijít - název, šířka a výška v mm:

```latte
<td>Podpis klienta: {\ADT\BiometricSignature\Pdf\SignatureBox::html('client', 45, 15)|noescape}</td>
```

Je to průhledná plocha: v dokumentu není vidět, jen podpisu drží místo.
V projektu se vyplatí zaregistrovat ji jako funkci šablon, ať se píše jen
`{signatureBox('client', 45, 15)}`:

```php
$template->addFunction('signatureBox', fn (string $name, float $width = 60, float $height = 20)
	=> new \Latte\Runtime\Html(SignatureBox::html($name, $width, $height)));
```

V dokumentu z Wordu nebo LibreOffice je místo obrázek (rámeček) s hypertextovým
odkazem na `https://signature.invalid/client`, v TCPDF a FPDF
`$pdf->Link($x, $y, $w, $h, SignatureBox::url('client'))`.

**Vložení podpisu:**

```php
$stamper = new SignatureStamper();

$stamper->findBoxes($pdf);  // list<SignatureBox>: název, stránka, poloha a rozměr
$signedPdf = $stamper->stamp($pdf, ['client' => $signature->getImageDataUri()]);
```

Obrázek se před vložením ořízne na samotný tah a vejde do místa se zachovaným
poměrem stran (`SignatureImage::fit()`), takže podpis je v dokumentu stejně
velký, ať ho člověk napsal přes celé podpisové pole, nebo jen do jeho části.
Původní obrázek u podpisu zůstává beze změny - počítá se z něj otisk.
Když dokument místo některého názvu nemá, nebo PDF nejde přečíst, vyhodí
`SignatureStampException`.

Potřebuje `setasign/fpdi`, k němu `setasign/fpdf` nebo `tecnickcom/tcpdf`
(použije se, co projekt má; vynutit jde `new SignatureStamper(SignatureStamper::WRITER_TCPDF)`)
a rozšíření GD.

Odkazy na web a e-mail v dokumentu zůstávají klikací. Zůstává i označení míst,
do kterých se teď nepodepisovalo, takže podpis další strany jde doplnit později
dalším voláním `stamp()`; označení podepsaného místa zmizí.

Omezení: z podepsaného PDF zmizí odkazy uvnitř dokumentu (na jinou stránku)
a pole formulářů, nejde číst zašifrované PDF ani PDF s komprimovanou strukturou
(PDF 1.5+ s xref streamem) a odkazy i místa pro podpis na otočených stránkách se
přeskakují.
Ověřeno na PDF z mPDF, Chromu, LibreOffice, TCPDF a FPDF.

## Prohlížeč

JS se bere přímo z balíčku ve `vendor/` (jedna verze s PHP, formát záznamu se
tak nerozejde). Projekt potřebuje `signature_pad` v `package.json` a alias ve
Vite:

```js
// vite.config.mjs
alias: {
	'@biometric-signature': path.resolve(__dirname, 'vendor/adt/biometric-signature/assets'),
},
```

```html
<div data-biometric-signature>
	<canvas class="signature-canvas"></canvas>
	<button type="button" data-biometric-signature-clear>Vymazat podpis</button>
	<input type="hidden" name="signatureImage" data-biometric-signature-image>
	<input type="hidden" name="signatureBiometrics" data-biometric-signature-biometrics>
</div>
```

```js
import { initBiometricSignatures } from '@biometric-signature/biometric-signature.js';

// Opakované volání (AJAX překreslení) napojí jen nové podpisy
initBiometricSignatures(document, {
	penColor: () => getComputedStyle(document.body).getPropertyValue('--signature-pen-color').trim(),
});
```

Plátno musí mít šířku i výšku z CSS (např. `width: 100%; height: 200px`) -
skript podle ní nastaví rozlišení plátna. Změna velikosti podpis smaže
(souřadnice biometriky by neseděly s obrázkem), ale jen při skutečné změně
rozměrů plátna, ne při schování lišty mobilního prohlížeče. Skryté plátno
(modal, záložka) se napojí, až se zobrazí.

Pro vlastní napojení je `attachBiometricSignature(canvas, { imageInput,
biometricsInput, clearButton, penColor, padOptions, onChange })`, které vrací
`{ pad, biometrics, clear(), isEmpty(), destroy() }`. Záznamník bez plátna
`signature_pad` je třída `SignatureBiometrics`.

Formát bodu je `BIOMETRICS_FIELDS` = `BiometricsNormalizer::FIELDS`
(`x`, `y`, `t` ms, `p` tlak, `tx`/`ty` náklon, `alt`/`az` výška a azimut pera,
`tw` natočení, `w`/`h` kontaktní plocha). Shodu obou stran hlídá
`JsContractTest`.

## Plán

- elektronická pečeť PDF (PAdES) s kvalifikovaným časovým razítkem (RFC 3161)
  od důvěryhodné autority - čas a neměnnost potvrdí třetí strana a změnu PDF
  pozná běžný prohlížeč PDF,
- šifrování biometriky veřejným klíčem třetí strany (zvláštní kategorie údajů
  podle čl. 9 GDPR): ani provozovatel aplikace by ji nepřečetl bez držitele klíče.

## Vývoj

Potřebuje PHP 8.4+ s `pdo_sqlite` (test mapování běží nad SQLite v paměti).

```bash
composer install
composer test      # PHPUnit
composer analyse   # PHPStan
```
