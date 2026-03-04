<?php

declare(strict_types=1);

namespace futuretek\openapi\Middleware;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Minimal PSR-7 UploadedFile implementation wrapping a file path.
 *
 * Used by DefaultFileHandler to convert Yii2 uploaded files to PSR-7.
 */
final class Psr7UploadedFile implements UploadedFileInterface
{
    private bool $moved = false;

    public function __construct(
        private readonly string $filePath,
        private readonly ?int $size = null,
        private readonly int $errorStatus = UPLOAD_ERR_OK,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
    ) {}

    public function getStream(): StreamInterface
    {
        if ($this->errorStatus !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Cannot retrieve stream due to upload error');
        }

        if ($this->moved) {
            throw new \RuntimeException('Cannot retrieve stream after it has been moved');
        }

        throw new \RuntimeException('Stream access not supported — use moveTo() or getFilePath()');
    }

    public function moveTo(string $targetPath): void
    {
        if ($this->moved) {
            throw new \RuntimeException('File has already been moved');
        }

        if ($this->errorStatus !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Cannot move file due to upload error');
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (php_sapi_name() === 'cli') {
            if (!rename($this->filePath, $targetPath)) {
                throw new \RuntimeException("Failed to move uploaded file to $targetPath");
            }
        } else {
            if (!move_uploaded_file($this->filePath, $targetPath)) {
                throw new \RuntimeException("Failed to move uploaded file to $targetPath");
            }
        }

        $this->moved = true;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->errorStatus;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    /**
     * Get the temporary file path (non-PSR-7, convenience method).
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }
}

