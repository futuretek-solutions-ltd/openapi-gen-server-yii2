<?php

declare(strict_types=1);

namespace futuretek\openapi;

/**
 * Collects warnings and errors during generation.
 */
final class GeneratorResult
{
    /** @var string[] */
    private array $warnings = [];

    /** @var string[] */
    private array $errors = [];

    /** @var string[] */
    private array $generated = [];

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function addGenerated(string $filePath): void
    {
        $this->generated[] = $filePath;
    }

    /** @return string[] */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /** @return string[] */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @return string[] */
    public function getGenerated(): array
    {
        return $this->generated;
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function hasWarnings(): bool
    {
        return count($this->warnings) > 0;
    }
}

