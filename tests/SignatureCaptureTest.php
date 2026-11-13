<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Tests;

use ADT\BiometricSignature\Biometrics\BiometricsNormalizer;
use ADT\BiometricSignature\Exception\InvalidSignatureException;
use ADT\BiometricSignature\PngDataUri;
use ADT\BiometricSignature\SignatureCapture;
use ADT\BiometricSignature\Tests\Fixtures\SampleInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignatureCaptureTest extends TestCase
{
	public function testAcceptsPngAndNormalizesBiometrics(): void
	{
		$capture = SignatureCapture::fromInput(SampleInput::PNG, SampleInput::biometricsJson(['extra' => 1]));

		self::assertSame(SampleInput::PNG, $capture->imageDataUri);
		self::assertSame(new BiometricsNormalizer()->normalize(SampleInput::biometricsJson()), $capture->biometricsJson);
	}

	#[DataProvider('invalidImages')]
	public function testRejectsInvalidImage(string $image): void
	{
		self::assertFalse(PngDataUri::isValid($image));

		$this->expectException(InvalidSignatureException::class);
		$this->expectExceptionMessage('obrázek');
		SignatureCapture::fromInput($image, SampleInput::biometricsJson());
	}

	/** @return iterable<string, array{string}> */
	public static function invalidImages(): iterable
	{
		yield 'prázdný' => [''];
		yield 'JPEG prefix' => [str_replace('image/png', 'image/jpeg', SampleInput::PNG)];
		yield 'neplatné base64' => ['data:image/png;base64,***'];
		yield 'není PNG' => ['data:image/png;base64,' . base64_encode('<svg onload="alert(1)"/>')];
		yield 'SVG' => ['data:image/svg+xml;base64,' . base64_encode('<svg/>')];
	}

	public function testRejectsTooLongImage(): void
	{
		self::assertTrue(PngDataUri::isValid(SampleInput::PNG, strlen(SampleInput::PNG)));
		self::assertFalse(PngDataUri::isValid(SampleInput::PNG, strlen(SampleInput::PNG) - 1));

		$this->expectException(InvalidSignatureException::class);
		SignatureCapture::fromInput(SampleInput::PNG, SampleInput::biometricsJson(), maxImageLength: 10);
	}

	public function testRejectsInvalidBiometrics(): void
	{
		$this->expectException(InvalidSignatureException::class);
		$this->expectExceptionMessage('biometrický');

		SignatureCapture::fromInput(SampleInput::PNG, '');
	}

	public function testDecodesPng(): void
	{
		self::assertStringStartsWith("\x89PNG", (string) PngDataUri::decode(SampleInput::PNG));
		self::assertNull(PngDataUri::decode('data:image/gif;base64,R0lGODlh'));
	}
}
