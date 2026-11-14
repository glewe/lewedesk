<?php

declare(strict_types=1);

namespace App\Services;

//---------------------------------------------------------------------------
/**
 * The file side of the attachments: validates an uploaded file and keeps the files on disk.
 *
 * The files live below the upload directory (uploads/attachments, never served directly: the folder is denied
 * by uploads/.htaccess and the files are handed out by the attachment endpoint after the access check). A file
 * is stored under a random name without extension, in a sub folder named after the first two characters of the
 * name. The name of the uploader and the MIME type of the client are never trusted: the type follows from the
 * extension, and the content must match it (see inspect()).
 *
 * Allowed: txt, md, pdf, zip, gif, jpg, png. Only gif, jpg and png are images (shown inline).
 */
class AttachmentStorage
{
  /** The allowed extensions and the MIME type the files are served with */
  public const TYPES = [
    'txt' => 'text/plain',
    'md' => 'text/markdown',
    'pdf' => 'application/pdf',
    'zip' => 'application/zip',
    'gif' => 'image/gif',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
  ];

  /** The MIME types that are shown in the browser; everything else is a download */
  public const IMAGE_TYPES = ['image/gif', 'image/jpeg', 'image/png'];

  /** What the content detection may answer for an extension */
  private const DETECTED = [
    'pdf' => ['application/pdf'],
    'zip' => ['application/zip', 'application/x-zip-compressed', 'application/x-zip'],
    'gif' => ['image/gif'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
  ];

  private const IMAGE_TYPE_CONSTANTS = [
    'gif' => IMAGETYPE_GIF,
    'jpg' => IMAGETYPE_JPEG,
    'jpeg' => IMAGETYPE_JPEG,
    'png' => IMAGETYPE_PNG,
  ];

  private const MAX_NAME = 150;

  private string $dir;

  //---------------------------------------------------------------------------
  /**
   * Constructor.
   *
   * @param string $dir The directory that holds the attachment files
   */
  public function __construct(string $dir)
  {
    $this->dir = rtrim($dir, '/\\');
  }

  //---------------------------------------------------------------------------
  /**
   * Cleans the name of an uploaded file for storing and showing: no path, no control characters, none of the
   * characters that are special in a file system, a limited length (the extension stays).
   *
   * @param string $name The name the client sent
   *
   * @return string The clean name, "file" if nothing is left
   */
  public static function cleanName(string $name): string
  {
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[<>:"\/\\\\|?*]+/', '_', $name) ?? '';
    $name = trim($name, " .\t");
    if (mb_strlen($name) > self::MAX_NAME) {
      $extension = pathinfo($name, PATHINFO_EXTENSION);
      $base = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, self::MAX_NAME - mb_strlen($extension) - 1);
      $name = $extension === '' ? $base : "$base.$extension";
    }

    return $name === '' ? 'file' : $name;
  }

  //---------------------------------------------------------------------------
  /**
   * Checks an uploaded file (an entry of $_FILES): the upload itself, the size, the extension and the content.
   *
   * @param array $file    The $_FILES entry
   * @param int   $maxSize The largest size in bytes
   *
   * @return array{name: string, mime: string, size: int, tmp: string}|array{error: array{0: int, 1: string}} The facts of the
   *         file, or ['error' => [HTTP status, message]]
   */
  public function inspect(array $file, int $maxSize): array
  {
    $limit = self::megabytes($maxSize);
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
      return ['error' => [413, "The file is too large (maximum $limit)"]];
    }
    if ($code === UPLOAD_ERR_NO_FILE) {
      return ['error' => [400, 'No file was received']];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($code !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
      return ['error' => [400, 'The file could not be uploaded']];
    }

    return $this->checkFile($tmp, (string)($file['name'] ?? ''), $maxSize);
  }

  //---------------------------------------------------------------------------
  /**
   * Checks a file that did not come from an upload (a part of a received mail that was written to a temporary file):
   * the size, the extension and the content, with the same rules as inspect().
   *
   * @param string $path    The file
   * @param string $name    The name it came with
   * @param int    $maxSize The largest size in bytes
   *
   * @return array{name: string, mime: string, size: int, tmp: string}|array{error: array{0: int, 1: string}}
   */
  public function inspectLocal(string $path, string $name, int $maxSize): array
  {
    return is_file($path) ? $this->checkFile($path, $name, $maxSize) : ['error' => [400, 'The file could not be read']];
  }

  //---------------------------------------------------------------------------
  /**
   * Checks the size, the extension and the content of a file.
   *
   * @param string $tmp     The file
   * @param string $name    The name it came with
   * @param int    $maxSize The largest size in bytes
   *
   * @return array{name: string, mime: string, size: int, tmp: string}|array{error: array{0: int, 1: string}}
   */
  private function checkFile(string $tmp, string $name, int $maxSize): array
  {
    $limit = self::megabytes($maxSize);
    $size = (int)filesize($tmp);
    if ($size === 0) {
      return ['error' => [400, 'The file is empty']];
    }
    if ($size > $maxSize) {
      return ['error' => [413, "The file is too large (maximum $limit)"]];
    }

    $name = self::cleanName($name);
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(self::TYPES[$extension])) {
      return ['error' => [415, 'This file type is not allowed. Allowed: ' . implode(', ', ['txt', 'md', 'pdf', 'zip', 'gif', 'jpg', 'png'])]];
    }
    if (!$this->contentMatches($tmp, $extension)) {
      return ['error' => [415, 'The content of the file does not match its type']];
    }

    return ['name' => $name, 'mime' => self::TYPES[$extension], 'size' => $size, 'tmp' => $tmp];
  }

  //---------------------------------------------------------------------------
  /**
   * Moves an uploaded file into the storage.
   *
   * @param string $tmp The temporary file of the upload
   *
   * @return string|null The stored name (32 hexadecimal characters), or null if the file could not be stored
   */
  public function store(string $tmp): ?string
  {
    $stored = bin2hex(random_bytes(16));
    $path = $this->path($stored);
    $folder = dirname($path);
    if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
      return null;
    }

    return move_uploaded_file($tmp, $path) ? $stored : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Moves a file that did not come from an upload into the storage (see inspectLocal()).
   *
   * @param string $path The file; it is gone after a successful call
   *
   * @return string|null The stored name (32 hexadecimal characters), or null if the file could not be stored
   */
  public function storeLocal(string $path): ?string
  {
    $stored = bin2hex(random_bytes(16));
    $target = $this->path($stored);
    $folder = dirname($target);
    if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
      return null;
    }

    return (@rename($path, $target) || (@copy($path, $target) && @unlink($path))) ? $stored : null;
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the path of a stored file.
   *
   * @param string $stored The stored name
   *
   * @return string
   */
  public function path(string $stored): string
  {
    return $this->dir . '/' . substr($stored, 0, 2) . '/' . $stored;
  }

  //---------------------------------------------------------------------------
  /**
   * Deletes a stored file; a file that is gone already is no problem.
   *
   * @param string $stored The stored name
   *
   * @return void
   */
  public function remove(string $stored): void
  {
    if (preg_match('/^[a-f0-9]{32}$/', $stored) === 1) {
      @unlink($this->path($stored));
    }
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether the content of a file is what its extension says.
   *
   * @param string $path      The file
   * @param string $extension The lower case extension
   *
   * @return bool
   */
  private function contentMatches(string $path, string $extension): bool
  {
    $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
    if ($detected === false) {
      return false;
    }

    if (isset(self::IMAGE_TYPE_CONSTANTS[$extension])) {
      $image = @getimagesize($path);

      return in_array($detected, self::DETECTED[$extension], true) && $image !== false && $image[2] === self::IMAGE_TYPE_CONSTANTS[$extension];
    }

    $head = (string)file_get_contents($path, false, null, 0, 8);
    if ($extension === 'pdf') {
      return in_array($detected, self::DETECTED['pdf'], true) && str_starts_with($head, '%PDF-');
    }
    if ($extension === 'zip') {
      return in_array($detected, self::DETECTED['zip'], true) && (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06"));
    }

    // txt and md: text without binary content
    return str_starts_with($detected, 'text/') && !str_contains((string)file_get_contents($path), "\0");
  }

  //---------------------------------------------------------------------------
  /**
   * Writes a size in bytes as megabytes for the messages.
   *
   * @param int $bytes The size
   *
   * @return string For example "2 MB"
   */
  public static function megabytes(int $bytes): string
  {
    return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . ' MB';
  }
}
