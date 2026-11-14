<?php

declare(strict_types=1);

namespace App\Services;

//---------------------------------------------------------------------------
/**
 * An attachment of a received mail (see MailParser). The content is held in memory: a mail is at most about 10 MB.
 * Nothing here has been checked yet; the type, the size and the content are validated when it is stored
 * (AttachmentStorage).
 */
final class ParsedAttachment
{
  /**
   * Constructor.
   *
   * @param string      $filename   A safe file name (no path, no control characters); never empty
   * @param string      $mimeType   The type the sender declared, lower case
   * @param string      $data       The decoded content
   * @param string|null $contentId  The Content-ID without angle brackets, if the part has one
   * @param bool        $inline     Whether the part is an image shown inside the mail (Content-ID or inline disposition)
   * @param bool        $referenced Whether the HTML body of the mail points to it with cid:
   */
  public function __construct(
    public readonly string $filename,
    public readonly string $mimeType,
    public readonly string $data,
    public readonly ?string $contentId,
    public readonly bool $inline,
    public readonly bool $referenced = false
  ) {
  }

  //---------------------------------------------------------------------------
  /**
   * Gives the size of the content.
   *
   * @return int Bytes
   */
  public function size(): int
  {
    return strlen($this->data);
  }

  //---------------------------------------------------------------------------
  /**
   * Gives a copy with the "referenced" flag set.
   *
   * @param bool $referenced Whether the HTML body points to the attachment
   *
   * @return self
   */
  public function withReferenced(bool $referenced): self
  {
    return new self($this->filename, $this->mimeType, $this->data, $this->contentId, $this->inline, $referenced);
  }
}
