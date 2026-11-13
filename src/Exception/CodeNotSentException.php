<?php

declare(strict_types=1);

namespace ADT\BiometricSignature\Exception;

/**
 * Projekt nedokázal kód odeslat (SigningHandler::sendCode()). Původní chybu
 * brány předejte jako $previous a zalogujte ji - BiometricSigning::sendCode()
 * pak vrátí SendCodeResult::Failed.
 */
final class CodeNotSentException extends \RuntimeException
{
}
