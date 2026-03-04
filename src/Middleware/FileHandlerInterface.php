<?php

declare(strict_types=1);

namespace futuretek\openapi\Middleware;

use Psr\Http\Message\UploadedFileInterface;

/**
 * File handler middleware interface.
 *
 * Converts framework-specific uploaded file instances to PSR-7 UploadedFileInterface.
 * Default implementation handles Yii2 UploadedFile conversion.
 *
 * Users can override for custom file handling.
 */
interface FileHandlerInterface
{
    /**
     * Convert a raw uploaded file resource to PSR-7 UploadedFileInterface.
     *
     * @param mixed $file The raw uploaded file (e.g., Yii2 UploadedFile, $_FILES entry, etc.)
     * @return UploadedFileInterface
     */
    public function convertUploadedFile(mixed $file): UploadedFileInterface;
}

