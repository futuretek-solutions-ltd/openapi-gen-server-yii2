<?php

declare(strict_types=1);

namespace futuretek\openapi\Middleware;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Default file handler that converts Yii2 UploadedFile to PSR-7 UploadedFileInterface.
 *
 * Uses a minimal PSR-7 UploadedFile wrapper around Yii2's file instance.
 */
class DefaultFileHandler implements FileHandlerInterface
{
    public function convertUploadedFile(mixed $file): UploadedFileInterface
    {
        // Yii2 UploadedFile
        if ($file instanceof \yii\web\UploadedFile) {
            return new Psr7UploadedFile(
                filePath: $file->tempName,
                size: $file->size,
                errorStatus: $file->error,
                clientFilename: $file->name,
                clientMediaType: $file->type,
            );
        }

        // Already PSR-7
        if ($file instanceof UploadedFileInterface) {
            return $file;
        }

        // Raw $_FILES entry
        if (is_array($file) && isset($file['tmp_name'])) {
            return new Psr7UploadedFile(
                filePath: $file['tmp_name'],
                size: $file['size'] ?? null,
                errorStatus: $file['error'] ?? UPLOAD_ERR_OK,
                clientFilename: $file['name'] ?? null,
                clientMediaType: $file['type'] ?? null,
            );
        }

        throw new \InvalidArgumentException('Unsupported uploaded file type: ' . get_debug_type($file));
    }
}

