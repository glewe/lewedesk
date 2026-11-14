<?php

declare(strict_types=1);

namespace App\Services;

//---------------------------------------------------------------------------
/**
 * Thrown when the due time of an SLA timer cannot be changed by hand. The code is the HTTP status of the answer.
 */
class SlaOverrideException extends \RuntimeException
{
}
