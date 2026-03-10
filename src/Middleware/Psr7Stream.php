<?php

declare(strict_types=1);

namespace futuretek\openapi\Middleware;

use Psr\Http\Message\StreamInterface;

/**
 * Minimal PSR-7 Stream implementation wrapping a PHP resource.
 *
 * Used by Psr7UploadedFile to provide stream access to uploaded file contents.
 */
final class Psr7Stream implements StreamInterface
{
    /** @var resource|null */
    private $stream;

    private ?int $size;

    /**
     * @param resource $stream A PHP stream resource.
     * @param int|null $size Known size in bytes, or null to detect via fstat.
     */
    public function __construct($stream, ?int $size = null)
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Stream must be a valid PHP resource');
        }

        $this->stream = $stream;
        $this->size = $size;
    }

    public function __toString(): string
    {
        try {
            $this->rewind();
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    /**
     * @return resource|null
     */
    public function detach()
    {
        $stream = $this->stream;
        $this->stream = null;
        $this->size = null;
        return $stream;
    }

    public function getSize(): ?int
    {
        if ($this->stream === null) {
            return null;
        }

        if ($this->size !== null) {
            return $this->size;
        }

        $stats = fstat($this->stream);
        return $stats['size'] ?? null;
    }

    public function tell(): int
    {
        if ($this->stream === null) {
            throw new \RuntimeException('Stream is detached');
        }

        $position = ftell($this->stream);
        if ($position === false) {
            throw new \RuntimeException('Unable to determine stream position');
        }

        return $position;
    }

    public function eof(): bool
    {
        return $this->stream === null || feof($this->stream);
    }

    public function isSeekable(): bool
    {
        if ($this->stream === null) {
            return false;
        }

        $meta = stream_get_meta_data($this->stream);
        return $meta['seekable'] ?? false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if ($this->stream === null) {
            throw new \RuntimeException('Stream is detached');
        }

        if (!$this->isSeekable()) {
            throw new \RuntimeException('Stream is not seekable');
        }

        if (fseek($this->stream, $offset, $whence) === -1) {
            throw new \RuntimeException("Unable to seek to position $offset");
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        if ($this->stream === null) {
            return false;
        }

        $meta = stream_get_meta_data($this->stream);
        $mode = $meta['mode'] ?? '';

        return str_contains($mode, 'w')
            || str_contains($mode, 'a')
            || str_contains($mode, 'x')
            || str_contains($mode, 'c')
            || str_contains($mode, '+');
    }

    public function write(string $string): int
    {
        if ($this->stream === null) {
            throw new \RuntimeException('Stream is detached');
        }

        if (!$this->isWritable()) {
            throw new \RuntimeException('Stream is not writable');
        }

        $bytes = fwrite($this->stream, $string);
        if ($bytes === false) {
            throw new \RuntimeException('Unable to write to stream');
        }

        $this->size = null; // Reset cached size after write

        return $bytes;
    }

    public function isReadable(): bool
    {
        if ($this->stream === null) {
            return false;
        }

        $meta = stream_get_meta_data($this->stream);
        $mode = $meta['mode'] ?? '';

        return str_contains($mode, 'r') || str_contains($mode, '+');
    }

    public function read(int $length): string
    {
        if ($this->stream === null) {
            throw new \RuntimeException('Stream is detached');
        }

        if (!$this->isReadable()) {
            throw new \RuntimeException('Stream is not readable');
        }

        $data = fread($this->stream, $length);
        if ($data === false) {
            throw new \RuntimeException('Unable to read from stream');
        }

        return $data;
    }

    public function getContents(): string
    {
        if ($this->stream === null) {
            throw new \RuntimeException('Stream is detached');
        }

        $contents = stream_get_contents($this->stream);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read stream contents');
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        if ($this->stream === null) {
            return $key !== null ? null : [];
        }

        $meta = stream_get_meta_data($this->stream);

        if ($key === null) {
            return $meta;
        }

        return $meta[$key] ?? null;
    }
}

