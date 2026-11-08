<?php

declare(strict_types=1);

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

//---------------------------------------------------------------------------
/**
 * Draws QR codes on the server as SVG, so no external service sees the secret and no Internet connection is needed.
 *
 * Uses the libraries bacon/bacon-qr-code and dasprid/enum (both BSD-2-Clause) that are copied to api/lib/ (the
 * application is deployed without Composer). They need the PHP extension iconv.
 */
class QrCode
{
  private static bool $registered = false;

  //---------------------------------------------------------------------------
  /**
   * Draws a QR code.
   *
   * @param string $text The text to encode
   * @param int    $size The size in pixels (width and height)
   *
   * @return string The SVG markup, ready to be put into a page (no XML declaration)
   */
  public function svg(string $text, int $size = 220): string
  {
    self::register();

    $renderer = new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd());
    $svg = (new Writer($renderer))->writeString($text);

    return trim((string)preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg));
  }

  //---------------------------------------------------------------------------
  /**
   * Registers the autoloader of the copied libraries (once).
   *
   * @return void
   */
  private static function register(): void
  {
    if (self::$registered) {
      return;
    }
    self::$registered = true;

    $base = dirname(__DIR__) . '/lib';
    $prefixes = ['BaconQrCode\\' => $base . '/BaconQrCode/', 'DASPRiD\\Enum\\' => $base . '/DASPRiD/'];
    spl_autoload_register(static function (string $class) use ($prefixes): void {
      foreach ($prefixes as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
          $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
          if (is_file($file)) {
            require $file;
          }
        }
      }
    });
  }
}
