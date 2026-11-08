<?php

declare(strict_types=1);

namespace App\Utils;

//---------------------------------------------------------------------------
/**
 * Simple environment variable loader.
 */
class EnvLoader
{
  //---------------------------------------------------------------------------
  /**
   * Loads the .env file from the specified directory.
   *
   * @param string $path Path to the directory containing .env
   *
   * @return void
   */
  public static function load(string $path): void
  {
    $file = $path . '/.env';
    if (!file_exists($file))
    {
      return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line)
    {
      if (str_starts_with(trim($line), '#'))
      {
        continue;
      }

      [$name, $value] = explode('=', $line, 2);
      $name = trim($name);
      $value = trim($value, " \t\n\r\0\x0B\"'");

      if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV))
      {
        putenv(sprintf('%s=%s', $name, $value));
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
      }
    }
  }
}
