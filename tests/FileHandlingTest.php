<?php

declare(strict_types=1);

/**
 * File Handling Unit Tests
 *
 * Tests for Psr7Stream, Psr7UploadedFile, and DefaultFileHandler.
 */

use futuretek\openapi\Middleware\DefaultFileHandler;
use futuretek\openapi\Middleware\Psr7Stream;
use futuretek\openapi\Middleware\Psr7UploadedFile;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

// ============================================================
// Psr7Stream
// ============================================================

test('Psr7Stream implements StreamInterface', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);
    expect($stream)->toBeInstanceOf(StreamInterface::class);
    $stream->close();
});

test('Psr7Stream constructor rejects non-resource', function () {
    expect(fn() => new Psr7Stream('not-a-resource'))->toThrow(\InvalidArgumentException::class);
});

test('Psr7Stream reads written content', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);

    $stream->write('hello world');
    $stream->rewind();

    expect($stream->read(5))->toBe('hello');
    expect($stream->read(6))->toBe(' world');
    $stream->close();
});

test('Psr7Stream getContents returns remaining content', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);

    $stream->write('foobar');
    $stream->seek(3);

    expect($stream->getContents())->toBe('bar');
    $stream->close();
});

test('Psr7Stream __toString returns full content', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);

    $stream->write('complete content');
    $stream->seek(5); // move pointer somewhere in the middle

    expect((string) $stream)->toBe('complete content');
    $stream->close();
});

test('Psr7Stream getSize returns size from fstat', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);
    $stream->write('12345');

    expect($stream->getSize())->toBe(5);
    $stream->close();
});

test('Psr7Stream getSize returns explicit size when provided', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource, 999);

    expect($stream->getSize())->toBe(999);
    $stream->close();
});

test('Psr7Stream getSize returns null after detach', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);

    $detached = $stream->detach();
    expect($stream->getSize())->toBeNull();

    if (is_resource($detached)) {
        fclose($detached);
    }
});

test('Psr7Stream tell returns current position', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);

    $stream->write('abcdef');
    $stream->seek(3);

    expect($stream->tell())->toBe(3);
    $stream->close();
});

test('Psr7Stream tell throws after detach', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);
    $detached = $stream->detach();

    expect(fn() => $stream->tell())->toThrow(\RuntimeException::class, 'Stream is detached');

    if (is_resource($detached)) {
        fclose($detached);
    }
});

test('Psr7Stream eof returns true at end of stream', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);

    $stream->write('ab');
    $stream->rewind();

    expect($stream->eof())->toBeFalse();
    $stream->read(2);
    // feof returns true only after a read past the end
    $stream->read(1);
    expect($stream->eof())->toBeTrue();
    $stream->close();
});

test('Psr7Stream eof returns true after detach', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);
    $detached = $stream->detach();

    expect($stream->eof())->toBeTrue();

    if (is_resource($detached)) {
        fclose($detached);
    }
});

test('Psr7Stream isSeekable returns true for temp streams', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);

    expect($stream->isSeekable())->toBeTrue();
    $stream->close();
});

test('Psr7Stream isSeekable returns false after detach', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);
    $detached = $stream->detach();

    expect($stream->isSeekable())->toBeFalse();

    if (is_resource($detached)) {
        fclose($detached);
    }
});

test('Psr7Stream seek throws after detach', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);
    $detached = $stream->detach();

    expect(fn() => $stream->seek(0))->toThrow(\RuntimeException::class, 'Stream is detached');

    if (is_resource($detached)) {
        fclose($detached);
    }
});

test('Psr7Stream rewind seeks to beginning', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);

    $stream->write('test data');
    expect($stream->tell())->toBe(9);

    $stream->rewind();
    expect($stream->tell())->toBe(0);
    expect($stream->getContents())->toBe('test data');
    $stream->close();
});

test('Psr7Stream isWritable for writable modes', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);
    expect($stream->isWritable())->toBeTrue();
    $stream->close();

    $resource = fopen('php://memory', 'wb');
    $stream = new Psr7Stream($resource);
    expect($stream->isWritable())->toBeTrue();
    $stream->close();
});

test('Psr7Stream isWritable returns false for read-only mode', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'psr7_stream_test_');
    file_put_contents($tmp, 'data');

    $resource = fopen($tmp, 'rb');
    $stream = new Psr7Stream($resource);
    expect($stream->isWritable())->toBeFalse();
    $stream->close();

    unlink($tmp);
});

test('Psr7Stream isWritable returns false after detach', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);
    $detached = $stream->detach();

    expect($stream->isWritable())->toBeFalse();

    if (is_resource($detached)) {
        fclose($detached);
    }
});

test('Psr7Stream write throws on read-only stream', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'psr7_stream_test_');
    file_put_contents($tmp, 'data');

    $resource = fopen($tmp, 'rb');
    $stream = new Psr7Stream($resource);

    expect(fn() => $stream->write('x'))->toThrow(\RuntimeException::class, 'Stream is not writable');
    $stream->close();

    unlink($tmp);
});

test('Psr7Stream write throws after detach', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);
    $detached = $stream->detach();

    expect(fn() => $stream->write('x'))->toThrow(\RuntimeException::class, 'Stream is detached');

    if (is_resource($detached)) {
        fclose($detached);
    }
});

test('Psr7Stream write resets cached size', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource, 0);

    expect($stream->getSize())->toBe(0);
    $stream->write('hello');
    // After write, cached size is reset, so getSize falls back to fstat
    expect($stream->getSize())->toBe(5);
    $stream->close();
});

test('Psr7Stream isReadable for readable modes', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);
    expect($stream->isReadable())->toBeTrue();
    $stream->close();

    $tmp = tempnam(sys_get_temp_dir(), 'psr7_stream_test_');
    $resource = fopen($tmp, 'rb');
    $stream = new Psr7Stream($resource);
    expect($stream->isReadable())->toBeTrue();
    $stream->close();
    unlink($tmp);
});

test('Psr7Stream isReadable returns false after detach', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);
    $detached = $stream->detach();

    expect($stream->isReadable())->toBeFalse();

    if (is_resource($detached)) {
        fclose($detached);
    }
});

test('Psr7Stream read throws after detach', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);
    $detached = $stream->detach();

    expect(fn() => $stream->read(1))->toThrow(\RuntimeException::class, 'Stream is detached');

    if (is_resource($detached)) {
        fclose($detached);
    }
});

test('Psr7Stream getContents throws after detach', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);
    $detached = $stream->detach();

    expect(fn() => $stream->getContents())->toThrow(\RuntimeException::class, 'Stream is detached');

    if (is_resource($detached)) {
        fclose($detached);
    }
});

test('Psr7Stream getMetadata returns metadata array', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);

    $meta = $stream->getMetadata();
    expect($meta)->toBeArray();
    expect($meta)->toHaveKey('mode');
    expect($meta)->toHaveKey('seekable');
    $stream->close();
});

test('Psr7Stream getMetadata returns specific key', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);

    expect($stream->getMetadata('seekable'))->toBeTrue();
    expect($stream->getMetadata('nonexistent'))->toBeNull();
    $stream->close();
});

test('Psr7Stream getMetadata returns null/empty after detach', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);
    $detached = $stream->detach();

    expect($stream->getMetadata())->toBe([]);
    expect($stream->getMetadata('mode'))->toBeNull();

    if (is_resource($detached)) {
        fclose($detached);
    }
});

test('Psr7Stream detach returns underlying resource', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);

    $detached = $stream->detach();
    expect(is_resource($detached))->toBeTrue();

    // Second detach returns null
    expect($stream->detach())->toBeNull();

    if (is_resource($detached)) {
        fclose($detached);
    }
});

test('Psr7Stream close releases resource', function () {
    $resource = fopen('php://temp', 'r+b');
    $stream = new Psr7Stream($resource);

    $stream->close();

    // After close, all state methods should indicate detached/closed
    expect($stream->isReadable())->toBeFalse();
    expect($stream->isWritable())->toBeFalse();
    expect($stream->isSeekable())->toBeFalse();
    expect($stream->getSize())->toBeNull();
});

test('Psr7Stream works with real file', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'psr7_stream_test_');
    file_put_contents($tmp, 'file content here');

    $resource = fopen($tmp, 'rb');
    $stream = new Psr7Stream($resource);

    expect($stream->isReadable())->toBeTrue();
    expect($stream->isWritable())->toBeFalse();
    expect((string) $stream)->toBe('file content here');
    expect($stream->getSize())->toBe(17);

    $stream->close();
    unlink($tmp);
});

// ============================================================
// Psr7UploadedFile
// ============================================================

test('Psr7UploadedFile implements UploadedFileInterface', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'psr7_upload_test_');
    file_put_contents($tmp, 'test');
    $file = new Psr7UploadedFile($tmp, 4, UPLOAD_ERR_OK, 'test.txt', 'text/plain');

    expect($file)->toBeInstanceOf(UploadedFileInterface::class);

    unlink($tmp);
});

test('Psr7UploadedFile accessors return correct values', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'psr7_upload_test_');
    file_put_contents($tmp, 'data');
    $file = new Psr7UploadedFile($tmp, 4, UPLOAD_ERR_OK, 'photo.jpg', 'image/jpeg');

    expect($file->getSize())->toBe(4);
    expect($file->getError())->toBe(UPLOAD_ERR_OK);
    expect($file->getClientFilename())->toBe('photo.jpg');
    expect($file->getClientMediaType())->toBe('image/jpeg');
    expect($file->getFilePath())->toBe($tmp);

    unlink($tmp);
});

test('Psr7UploadedFile accessors handle null values', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'psr7_upload_test_');
    file_put_contents($tmp, '');
    $file = new Psr7UploadedFile($tmp);

    expect($file->getSize())->toBeNull();
    expect($file->getError())->toBe(UPLOAD_ERR_OK);
    expect($file->getClientFilename())->toBeNull();
    expect($file->getClientMediaType())->toBeNull();

    unlink($tmp);
});

test('Psr7UploadedFile getStream returns readable stream with file contents', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'psr7_upload_test_');
    file_put_contents($tmp, 'stream content test');

    $file = new Psr7UploadedFile($tmp, 19, UPLOAD_ERR_OK, 'test.txt', 'text/plain');
    $stream = $file->getStream();

    expect($stream)->toBeInstanceOf(StreamInterface::class);
    expect($stream->isReadable())->toBeTrue();
    expect((string) $stream)->toBe('stream content test');
    expect($stream->getSize())->toBe(19);

    $stream->close();
    unlink($tmp);
});

test('Psr7UploadedFile getStream can be called multiple times', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'psr7_upload_test_');
    file_put_contents($tmp, 'multi read');

    $file = new Psr7UploadedFile($tmp, 10, UPLOAD_ERR_OK);

    $stream1 = $file->getStream();
    expect((string) $stream1)->toBe('multi read');
    $stream1->close();

    $stream2 = $file->getStream();
    expect((string) $stream2)->toBe('multi read');
    $stream2->close();

    unlink($tmp);
});

test('Psr7UploadedFile getStream throws on upload error', function () {
    $file = new Psr7UploadedFile('/tmp/nonexistent', null, UPLOAD_ERR_INI_SIZE);

    expect(fn() => $file->getStream())->toThrow(\RuntimeException::class, 'Cannot retrieve stream due to upload error');
});

test('Psr7UploadedFile getStream throws after moveTo', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'psr7_upload_test_');
    file_put_contents($tmp, 'moved');

    $file = new Psr7UploadedFile($tmp, 5, UPLOAD_ERR_OK);

    $target = tempnam(sys_get_temp_dir(), 'psr7_move_test_');
    $file->moveTo($target);

    expect(fn() => $file->getStream())->toThrow(\RuntimeException::class, 'Cannot retrieve stream after it has been moved');

    unlink($target);
});

test('Psr7UploadedFile moveTo moves file in CLI mode', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'psr7_upload_test_');
    file_put_contents($tmp, 'file to move');

    $file = new Psr7UploadedFile($tmp, 12, UPLOAD_ERR_OK, 'doc.txt', 'text/plain');

    $targetDir = sys_get_temp_dir() . '/psr7_move_test_' . uniqid('', true);
    $target = $targetDir . '/moved_file.txt';

    $file->moveTo($target);

    expect(file_exists($target))->toBeTrue();
    expect(file_get_contents($target))->toBe('file to move');
    expect(file_exists($tmp))->toBeFalse();

    unlink($target);
    rmdir($targetDir);
});

test('Psr7UploadedFile moveTo creates target directory', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'psr7_upload_test_');
    file_put_contents($tmp, 'nested');

    $file = new Psr7UploadedFile($tmp, 6, UPLOAD_ERR_OK);

    $targetDir = sys_get_temp_dir() . '/psr7_nested_' . uniqid('', true) . '/sub/dir';
    $target = $targetDir . '/file.bin';

    $file->moveTo($target);

    expect(file_exists($target))->toBeTrue();
    expect(is_dir($targetDir))->toBeTrue();

    unlink($target);
    // Clean up nested dirs
    rmdir($targetDir);
    rmdir(dirname($targetDir));
    rmdir(dirname(dirname($targetDir)));
});

test('Psr7UploadedFile moveTo throws on double move', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'psr7_upload_test_');
    file_put_contents($tmp, 'once');

    $file = new Psr7UploadedFile($tmp, 4, UPLOAD_ERR_OK);

    $target = tempnam(sys_get_temp_dir(), 'psr7_move_test_');
    $file->moveTo($target);

    expect(fn() => $file->moveTo($target . '_2'))->toThrow(\RuntimeException::class, 'File has already been moved');

    unlink($target);
});

test('Psr7UploadedFile moveTo throws on upload error', function () {
    $file = new Psr7UploadedFile('/tmp/nonexistent', null, UPLOAD_ERR_PARTIAL);

    expect(fn() => $file->moveTo('/tmp/target'))->toThrow(\RuntimeException::class, 'Cannot move file due to upload error');
});

// ============================================================
// DefaultFileHandler
// ============================================================

test('DefaultFileHandler converts Yii2 UploadedFile', function () {
    $handler = new DefaultFileHandler();

    // Create a Yii2 UploadedFile instance via reflection (no actual upload needed)
    $yiiFile = new \yii\web\UploadedFile();
    $yiiFile->tempName = tempnam(sys_get_temp_dir(), 'yii2_upload_test_');
    file_put_contents($yiiFile->tempName, 'yii2 file content');
    $yiiFile->size = 17;
    $yiiFile->error = UPLOAD_ERR_OK;
    $yiiFile->name = 'document.pdf';
    $yiiFile->type = 'application/pdf';

    $result = $handler->convertUploadedFile($yiiFile);

    expect($result)->toBeInstanceOf(UploadedFileInterface::class);
    expect($result)->toBeInstanceOf(Psr7UploadedFile::class);
    expect($result->getSize())->toBe(17);
    expect($result->getError())->toBe(UPLOAD_ERR_OK);
    expect($result->getClientFilename())->toBe('document.pdf');
    expect($result->getClientMediaType())->toBe('application/pdf');
    expect($result->getFilePath())->toBe($yiiFile->tempName);

    // Verify stream access works
    $stream = $result->getStream();
    expect((string) $stream)->toBe('yii2 file content');
    $stream->close();

    unlink($yiiFile->tempName);
});

test('DefaultFileHandler passes through existing UploadedFileInterface', function () {
    $handler = new DefaultFileHandler();

    $tmp = tempnam(sys_get_temp_dir(), 'psr7_passthrough_');
    file_put_contents($tmp, 'existing');
    $existing = new Psr7UploadedFile($tmp, 8, UPLOAD_ERR_OK, 'existing.txt', 'text/plain');

    $result = $handler->convertUploadedFile($existing);

    expect($result)->toBe($existing); // Same instance
    expect($result->getClientFilename())->toBe('existing.txt');

    unlink($tmp);
});

test('DefaultFileHandler converts raw $_FILES array', function () {
    $handler = new DefaultFileHandler();

    $tmp = tempnam(sys_get_temp_dir(), 'files_array_test_');
    file_put_contents($tmp, 'raw files content');

    $filesEntry = [
        'tmp_name' => $tmp,
        'size' => 17,
        'error' => UPLOAD_ERR_OK,
        'name' => 'upload.csv',
        'type' => 'text/csv',
    ];

    $result = $handler->convertUploadedFile($filesEntry);

    expect($result)->toBeInstanceOf(UploadedFileInterface::class);
    expect($result)->toBeInstanceOf(Psr7UploadedFile::class);
    expect($result->getSize())->toBe(17);
    expect($result->getError())->toBe(UPLOAD_ERR_OK);
    expect($result->getClientFilename())->toBe('upload.csv');
    expect($result->getClientMediaType())->toBe('text/csv');

    // Verify stream access works
    $stream = $result->getStream();
    expect((string) $stream)->toBe('raw files content');
    $stream->close();

    unlink($tmp);
});

test('DefaultFileHandler converts raw $_FILES array with minimal fields', function () {
    $handler = new DefaultFileHandler();

    $tmp = tempnam(sys_get_temp_dir(), 'files_minimal_test_');
    file_put_contents($tmp, 'minimal');

    $filesEntry = [
        'tmp_name' => $tmp,
    ];

    $result = $handler->convertUploadedFile($filesEntry);

    expect($result)->toBeInstanceOf(Psr7UploadedFile::class);
    expect($result->getSize())->toBeNull();
    expect($result->getError())->toBe(UPLOAD_ERR_OK);
    expect($result->getClientFilename())->toBeNull();
    expect($result->getClientMediaType())->toBeNull();

    unlink($tmp);
});

test('DefaultFileHandler throws on unsupported type: stdClass', function () {
    $handler = new DefaultFileHandler();

    expect(fn() => $handler->convertUploadedFile(new \stdClass()))
        ->toThrow(\InvalidArgumentException::class, 'Unsupported uploaded file type');
});

test('DefaultFileHandler throws on unsupported type: string', function () {
    $handler = new DefaultFileHandler();

    expect(fn() => $handler->convertUploadedFile('/some/path'))
        ->toThrow(\InvalidArgumentException::class, 'Unsupported uploaded file type');
});

test('DefaultFileHandler throws on unsupported type: int', function () {
    $handler = new DefaultFileHandler();

    expect(fn() => $handler->convertUploadedFile(42))
        ->toThrow(\InvalidArgumentException::class, 'Unsupported uploaded file type');
});

test('DefaultFileHandler throws on array without tmp_name', function () {
    $handler = new DefaultFileHandler();

    expect(fn() => $handler->convertUploadedFile(['name' => 'test.txt', 'size' => 100]))
        ->toThrow(\InvalidArgumentException::class, 'Unsupported uploaded file type');
});

test('DefaultFileHandler handles Yii2 UploadedFile with error', function () {
    $handler = new DefaultFileHandler();

    $yiiFile = new \yii\web\UploadedFile();
    $yiiFile->tempName = '';
    $yiiFile->size = 0;
    $yiiFile->error = UPLOAD_ERR_INI_SIZE;
    $yiiFile->name = 'huge.bin';
    $yiiFile->type = 'application/octet-stream';

    $result = $handler->convertUploadedFile($yiiFile);

    expect($result->getError())->toBe(UPLOAD_ERR_INI_SIZE);
    expect($result->getClientFilename())->toBe('huge.bin');
    expect(fn() => $result->getStream())->toThrow(\RuntimeException::class);
    expect(fn() => $result->moveTo('/tmp/x'))->toThrow(\RuntimeException::class);
});

// ============================================================
// Psr7UploadedFile + Psr7Stream integration
// ============================================================

test('Psr7UploadedFile stream contents match file contents', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'psr7_integration_');
    $content = str_repeat('A', 1024) . 'marker' . str_repeat('B', 1024);
    file_put_contents($tmp, $content);

    $file = new Psr7UploadedFile($tmp, strlen($content), UPLOAD_ERR_OK, 'large.bin', 'application/octet-stream');
    $stream = $file->getStream();

    // Read in chunks
    $stream->rewind();
    $chunk1 = $stream->read(1024);
    expect($chunk1)->toBe(str_repeat('A', 1024));

    $marker = $stream->read(6);
    expect($marker)->toBe('marker');

    $chunk2 = $stream->read(1024);
    expect($chunk2)->toBe(str_repeat('B', 1024));

    expect($stream->eof())->toBeFalse();
    $stream->read(1); // trigger eof
    expect($stream->eof())->toBeTrue();

    $stream->close();
    unlink($tmp);
});

test('Psr7UploadedFile stream is seekable for regular files', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'psr7_seekable_');
    file_put_contents($tmp, 'seekable content');

    $file = new Psr7UploadedFile($tmp, 16, UPLOAD_ERR_OK);
    $stream = $file->getStream();

    expect($stream->isSeekable())->toBeTrue();
    $stream->seek(9);
    expect($stream->getContents())->toBe('content');

    $stream->close();
    unlink($tmp);
});

test('DefaultFileHandler + getStream end-to-end with Yii2 UploadedFile', function () {
    $handler = new DefaultFileHandler();

    $tmp = tempnam(sys_get_temp_dir(), 'e2e_yii2_');
    file_put_contents($tmp, 'e2e yii2 content');

    $yiiFile = new \yii\web\UploadedFile();
    $yiiFile->tempName = $tmp;
    $yiiFile->size = 16;
    $yiiFile->error = UPLOAD_ERR_OK;
    $yiiFile->name = 'report.txt';
    $yiiFile->type = 'text/plain';

    $result = $handler->convertUploadedFile($yiiFile);
    $stream = $result->getStream();

    expect((string) $stream)->toBe('e2e yii2 content');

    $stream->close();
    unlink($tmp);
});

test('DefaultFileHandler + getStream end-to-end with $_FILES array', function () {
    $handler = new DefaultFileHandler();

    $tmp = tempnam(sys_get_temp_dir(), 'e2e_files_');
    file_put_contents($tmp, 'raw file e2e');

    $result = $handler->convertUploadedFile([
        'tmp_name' => $tmp,
        'size' => 12,
        'error' => UPLOAD_ERR_OK,
        'name' => 'data.bin',
        'type' => 'application/octet-stream',
    ]);

    $stream = $result->getStream();
    expect((string) $stream)->toBe('raw file e2e');

    $stream->close();
    unlink($tmp);
});

