<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

//---------------------------------------------------------------------------
/**
 * Turns the HTML body of a received mail into plain text with a little Markdown (links, lists, quotes). Nothing of the
 * HTML is kept: the result is text, so scripts, styles, forms, frames and event handlers cannot get through, and remote
 * images are dropped (the server never fetches them, which also defeats tracking pixels). Images that belong to the
 * mail (cid:) become ![alt](cid:ID) so that the inline attachment can be put in their place. Links keep only the
 * schemes http, https and mailto. Quoted history (blockquote) gets "> " in front of each line, which is what
 * MailQuoteStripper looks for.
 */
final class MailHtmlConverter
{
  /** Longer HTML is cut off before it is parsed */
  private const MAX_HTML = 2097152;

  private const DROP = [
    'script', 'style', 'head', 'title', 'meta', 'link', 'object', 'embed', 'iframe', 'frame', 'frameset', 'form',
    'input', 'button', 'select', 'textarea', 'svg', 'math', 'noscript', 'template', 'audio', 'video', 'canvas', 'applet',
  ];

  /** Elements that are separated from their neighbours by a blank line */
  private const PARAGRAPH = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table', 'pre', 'hr', 'address', 'figure', 'dl'];

  /** Elements that start on a new line */
  private const LINE = ['div', 'tr', 'dd', 'dt', 'section', 'article', 'header', 'footer', 'nav', 'aside', 'main', 'center', 'caption', 'figcaption'];

  // Marks for line breaks while the text is built; they are resolved at the end. BLOCK is the edge of a block (one new
  // line, however many edges meet), PARA the edge of a paragraph (a blank line), BREAK a <br> (always a line end).
  private const BLOCK = "\x01";
  private const PARA = "\x02";
  private const BREAK = "\x03";

  //---------------------------------------------------------------------------
  /**
   * Converts an HTML body.
   *
   * @param string $html The HTML, already UTF-8
   *
   * @return string The text; empty if there is none
   */
  public function toText(string $html): string
  {
    $html = trim($html);
    if ($html === '') {
      return '';
    }
    if (strlen($html) > self::MAX_HTML) {
      $html = substr($html, 0, self::MAX_HTML);
    }

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($loaded === false) {
      return $this->normalize(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    $body = $dom->getElementsByTagName('body')->item(0);

    return $body === null ? '' : $this->normalize($this->render($body));
  }

  //---------------------------------------------------------------------------
  /**
   * Renders a node and what is below it.
   *
   * @param DOMNode $node The node
   * @param bool    $pre  Whether the white space is kept (inside pre)
   *
   * @return string Text with the break marks
   */
  private function render(DOMNode $node, bool $pre = false): string
  {
    if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
      $text = (string)$node->nodeValue;

      return $pre ? $text : (preg_replace('/[ \t\r\n\f\x{00A0}]+/u', ' ', $text) ?? '');
    }
    if (!$node instanceof DOMElement) {
      return $node->hasChildNodes() ? $this->children($node, $pre) : '';
    }

    $tag = strtolower($node->tagName);
    if (in_array($tag, self::DROP, true) || $this->isHidden($node)) {
      return '';
    }

    switch ($tag) {
      case 'br':
        return self::BREAK;
      case 'img':
        return $this->image($node);
      case 'a':
        return $this->link($node, $pre);
      case 'blockquote':
        return self::PARA . $this->quote($this->children($node, $pre)) . self::PARA;
      case 'li':
        return self::BLOCK . '- ' . $this->children($node, $pre) . self::BLOCK;
      case 'td':
      case 'th':
        return $this->children($node, $pre) . ' ';
      case 'pre':
        return self::PARA . $this->children($node, true) . self::PARA;
      case 'hr':
        return self::PARA;
    }

    $inner = $this->children($node, $pre);
    if (in_array($tag, self::PARAGRAPH, true)) {
      return self::PARA . $inner . self::PARA;
    }
    if (in_array($tag, self::LINE, true)) {
      return self::BLOCK . $inner . self::BLOCK;
    }

    return $inner;
  }

  //---------------------------------------------------------------------------
  /**
   * Renders the children of a node.
   *
   * @param DOMNode $node The parent
   * @param bool    $pre  Whether the white space is kept
   *
   * @return string
   */
  private function children(DOMNode $node, bool $pre): string
  {
    $out = '';
    foreach ($node->childNodes as $child) {
      $out .= $this->render($child, $pre);
    }

    return $out;
  }

  //---------------------------------------------------------------------------
  /**
   * Tells whether an element is hidden (the invisible preview text of newsletters and Outlook).
   *
   * @param DOMElement $element The element
   *
   * @return bool
   */
  private function isHidden(DOMElement $element): bool
  {
    if ($element->hasAttribute('hidden')) {
      return true;
    }
    $style = strtolower($element->getAttribute('style'));

    return $style !== '' && preg_match('/display\s*:\s*none|visibility\s*:\s*hidden|mso-hide\s*:\s*all|font-size\s*:\s*0(?:px|pt)?\s*(?:;|$)/', $style) === 1;
  }

  //---------------------------------------------------------------------------
  /**
   * Renders an image: pictures that belong to the mail (cid:) are kept as a reference, all others are dropped.
   *
   * @param DOMElement $img The element
   *
   * @return string
   */
  private function image(DOMElement $img): string
  {
    $src = trim($img->getAttribute('src'));
    if (stripos($src, 'cid:') !== 0) {
      return '';
    }
    $id = trim(rawurldecode(substr($src, 4)), " \t<>");
    $alt = trim(str_replace(['[', ']', "\n"], ' ', $img->getAttribute('alt')));

    return $id === '' ? '' : '![' . $alt . '](cid:' . $id . ')';
  }

  //---------------------------------------------------------------------------
  /**
   * Renders a link as Markdown; only http, https and mailto are kept.
   *
   * @param DOMElement $a   The element
   * @param bool       $pre Whether the white space is kept
   *
   * @return string
   */
  private function link(DOMElement $a, bool $pre): string
  {
    $text = $this->children($a, $pre);
    $href = trim($a->getAttribute('href'));
    if (preg_match('#^(https?://|mailto:)[^\s<>()"]+$#i', $href) !== 1) {
      return $text;
    }

    $label = trim(str_replace([self::BLOCK, self::PARA, self::BREAK], ' ', $text));
    $bare = preg_replace('#^(https?://|mailto:)#i', '', $href) ?? $href;
    if ($label === '' || strcasecmp(rtrim($label, '/'), rtrim($href, '/')) === 0 || strcasecmp(rtrim($label, '/'), rtrim($bare, '/')) === 0) {
      // A mail address is shown without "mailto:"
      return stripos($href, 'mailto:') === 0 ? $bare : $href;
    }

    return '[' . str_replace(['[', ']'], ['(', ')'], $label) . '](' . $href . ')';
  }

  //---------------------------------------------------------------------------
  /**
   * Puts "> " in front of every line of a quoted block.
   *
   * @param string $inner The text of the block, with break marks
   *
   * @return string
   */
  private function quote(string $inner): string
  {
    $lines = explode("\n", $this->normalize($inner));
    foreach ($lines as $i => $line) {
      $lines[$i] = $line === '' ? '>' : (str_starts_with($line, '>') ? '>' . $line : '> ' . $line);
    }

    return implode("\n", $lines);
  }

  //---------------------------------------------------------------------------
  /**
   * Resolves the break marks and tidies the white space.
   *
   * @param string $text The text with break marks
   *
   * @return string
   */
  private function normalize(string $text): string
  {
    $text = str_replace("\u{00A0}", ' ', $text);
    // A run of marks becomes line breaks: a blank line for a paragraph edge, else one line end plus one more for every
    // <br> that does not itself end the line of the text before it
    $text = preg_replace_callback('/[\x01-\x03]+/', static function (array $m): string {
      $marks = $m[0];
      $breaks = substr_count($marks, self::BREAK);
      $lines = $marks[0] === self::BREAK ? $breaks : 1 + $breaks;

      return str_repeat("\n", str_contains($marks, self::PARA) ? max(2, $lines) : max(1, $lines));
    }, $text) ?? $text;
    $text = preg_replace('/[ \t]*\n[ \t]*/', "\n", $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

    return trim($text);
  }
}
