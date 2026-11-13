<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Tests;

use ADT\BiometricSignature\Entity\BiometricAnonymizationType;
use ADT\BiometricSignature\Tests\Fixtures\SampleInput;
use ADT\BiometricSignature\Tests\Fixtures\TestSignature;
use ADT\DoctrineAnonymization\AnonymizationType;
use ADT\DoctrineAnonymization\Attributes\Anonymize;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class MappingTest extends TestCase
{
	private function createEntityManager(): EntityManager
	{
		$config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Fixtures'], true);
		$config->enableNativeLazyObjects(true);
		// Názvy sloupců v README počítají s UnderscoreNamingStrategy
		$config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
		$entityManager = new EntityManager($connection, $config);
		new SchemaTool($entityManager)->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

		return $entityManager;
	}

	/**
	 * Názvy sloupců jsou součást veřejného API - jejich změna znamená migraci
	 * dat ve všech projektech, které knihovnu používají.
	 */
	public function testColumnNamesAreStable(): void
	{
		$metadata = $this->createEntityManager()->getClassMetadata(TestSignature::class);

		$columns = [];
		foreach ($metadata->getFieldNames() as $field) {
			$columns[] = $metadata->getColumnName($field);
		}

		self::assertEqualsCanonicalizing([
			'id', 'created_at',
			'token', 'image_data_uri', 'biometrics_json', 'phone', 'on_site', 'ip_address', 'user_agent',
			'code_hash', 'code_sent_at', 'code_send_count', 'code_attempts',
			'verified_at', 'evidence_json', 'document_hash', 'pdf_hash',
		], $columns);
		self::assertTrue($metadata->isUniqueField('token'));
	}

	public function testRoundTrip(): void
	{
		$entityManager = $this->createEntityManager();
		$signature = new TestSignature(SampleInput::capture(), '+420000000456', new \DateTimeImmutable('2026-11-13 10:00:00'));
		$signature->codeSent(password_hash('123456', PASSWORD_DEFAULT), new \DateTimeImmutable('2026-11-13 10:00:05'));
		$signature->markVerified(new \DateTimeImmutable('2026-11-13 10:01:00'), '{"evidence":true}');
		$signature->recordPdf('%PDF');
		$entityManager->persist($signature);
		$entityManager->flush();
		$entityManager->clear();

		$loaded = $entityManager->getRepository(TestSignature::class)->findOneBy(['token' => $signature->getToken()]);

		self::assertInstanceOf(TestSignature::class, $loaded);
		self::assertSame(SampleInput::PNG, $loaded->getImageDataUri());
		self::assertSame($signature->getBiometricsJson(), $loaded->getBiometricsJson());
		self::assertSame(1, $loaded->getCodeSendCount());
		self::assertSame(hash('sha256', '{"evidence":true}'), $loaded->getDocumentHash());
		self::assertSame(hash('sha256', '%PDF'), $loaded->getPdfHash());
		self::assertSame(64, strlen($loaded->getToken()));
	}

	public function testPersonalDataIsMarkedForAnonymization(): void
	{
		$expected = [
			'token' => AnonymizationType::SECRET,
			'imageDataUri' => BiometricAnonymizationType::BIOMETRIC,
			'biometricsJson' => BiometricAnonymizationType::BIOMETRIC,
			'phone' => AnonymizationType::PHONE,
			'ipAddress' => AnonymizationType::IP_ADDRESS,
			'userAgent' => AnonymizationType::USER_AGENT,
			'codeHash' => AnonymizationType::SECRET,
			'evidenceJson' => AnonymizationType::FREE_TEXT,
		];
		foreach ($expected as $property => $type) {
			$attributes = new \ReflectionProperty(TestSignature::class, $property)->getAttributes(Anonymize::class);
			self::assertCount(1, $attributes, $property);
			self::assertSame($type, $attributes[0]->newInstance()->type, $property);
		}
		self::assertTrue(BiometricAnonymizationType::BIOMETRIC->alwaysAnonymize());
	}
}
