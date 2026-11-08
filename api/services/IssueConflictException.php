<?php

declare(strict_types=1);

namespace App\Services;

//---------------------------------------------------------------------------
/**
 * Thrown when an issue changed between the check of an action and its execution (somebody else was faster).
 * The caller answers 409 and the user reloads the issue.
 */
class IssueConflictException extends \RuntimeException
{
}
