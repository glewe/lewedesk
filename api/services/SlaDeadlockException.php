<?php

declare(strict_types=1);

namespace App\Services;

//---------------------------------------------------------------------------
/**
 * Thrown by SlaService when the database ended the transaction of the caller because of a deadlock. The SLA code never
 * swallows it: the action of the user is gone as well and must not report success.
 */
class SlaDeadlockException extends \RuntimeException
{
}
