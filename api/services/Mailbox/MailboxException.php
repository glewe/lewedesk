<?php

declare(strict_types=1);

namespace App\Services\Mailbox;

use RuntimeException;

//---------------------------------------------------------------------------
/**
 * A problem with the connection to the mailbox or an answer of the server that says no. The message never contains the
 * password.
 */
class MailboxException extends RuntimeException
{
}
