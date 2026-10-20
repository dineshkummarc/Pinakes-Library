<?php
declare(strict_types=1);

namespace App\Support;

use Slim\Psr7\Stream;

/**
 * A file handle served from $offset to its end: the body of an open-ended Range
 * response ("bytes=N-", what an audio player sends on every seek forward).
 *
 * A plain Stream positioned with fseek() is not enough: Slim's ResponseEmitter rewinds
 * every seekable body before sending it, and then read Content-Length bytes from the
 * START of the file, labelled as bytes N to the end. Here "the start" is $offset, so a
 * rewind, a seek and the size all describe only the requested part.
 */
final class OffsetFileStream extends Stream
{
    /** @param resource $handle */
    public function __construct($handle, private int $offset)
    {
        parent::__construct($handle);
        $this->rewind();
    }

    public function rewind(): void
    {
        parent::seek($this->offset);
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        parent::seek($whence === SEEK_SET ? $this->offset + (int) $offset : $offset, $whence);
    }

    public function tell(): int
    {
        return parent::tell() - $this->offset;
    }

    public function getSize(): ?int
    {
        $size = parent::getSize();
        return $size === null ? null : max(0, $size - $this->offset);
    }
}
