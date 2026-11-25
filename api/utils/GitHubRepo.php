<?php

declare(strict_types=1);

namespace App\Utils;

//---------------------------------------------------------------------------
/**
 * The GitHub repository of a product (see docs/GITHUB.md): reads what a person types and gives the normalized form
 * https://github.com/owner/repo. Only github.com is supported. No I/O is done here.
 *
 * Accepted: https://github.com/owner/repo, http://, www., github.com/owner/repo, owner/repo, each with an optional
 * ".git", a trailing slash, a query or fragment, or a path behind the repository (/tree/main, /issues).
 */
final class GitHubRepo
{
  /** The owner: letters, digits and single dashes, at most 39 characters */
  private const OWNER = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})';

  /** The repository name: letters, digits, dash, underscore and dot, at most 100 characters */
  private const REPO = '[A-Za-z0-9_.-]{1,100}';

  /** The address of the repositories on the web */
  public const BASE_URL = 'https://github.com/';

  //---------------------------------------------------------------------------
  /**
   * Normalizes the input.
   *
   * @param string $input What was typed
   *
   * @return string|null https://github.com/owner/repo, or null if the input is not a GitHub repository
   */
  public static function normalize(string $input): ?string
  {
    $parts = self::parts($input);

    return $parts === null ? null : self::BASE_URL . $parts['owner'] . '/' . $parts['repo'];
  }

  //---------------------------------------------------------------------------
  /**
   * Splits the input into owner and repository.
   *
   * @param string $input What was typed, or a normalized address
   *
   * @return array{owner: string, repo: string}|null Null if the input is not a GitHub repository
   */
  public static function parts(string $input): ?array
  {
    $text = trim($input);
    // Scheme and host; a bare "owner/repo" has none
    $text = preg_replace('#^(?:https?://)?(?:www\.)?github\.com/#i', '', $text, 1);
    // Query, fragment
    $text = preg_replace('/[?#].*$/', '', (string)$text);

    if (!preg_match('#^(' . self::OWNER . ')/(' . self::REPO . ')(?:/.*)?$#', (string)$text, $m)) {
      return null;
    }

    $repo = preg_replace('/\.git$/i', '', $m[2]);
    if ($repo === '' || $repo === '.' || $repo === '..') {
      return null;
    }

    return ['owner' => $m[1], 'repo' => $repo];
  }
}
