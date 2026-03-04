<?php

declare(strict_types=1);

namespace futuretek\openapi\Parser;

/**
 * Normalized representation of an OpenAPI enum.
 */
final class ParsedEnum
{
    /**
     * @param string $name Enum class name
     * @param string $backingType PHP backing type (string or int)
     * @param array<string|int> $values Enum values
     * @param string[] $descriptions Descriptions aligned by value index
     * @param string|null $description Enum description
     */
    public function __construct(
        public readonly string $name,
        public readonly string $backingType = 'string',
        public readonly array $values = [],
        public readonly array $descriptions = [],
        public readonly ?string $description = null,
    ) {}
}

