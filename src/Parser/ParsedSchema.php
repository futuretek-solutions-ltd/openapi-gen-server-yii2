<?php

declare(strict_types=1);

namespace futuretek\openapi\Parser;

/**
 * Normalized representation of an OpenAPI schema (used for DTO generation).
 */
final class ParsedSchema
{
    /**
     * @param string $name Schema name (class name)
     * @param ParsedProperty[] $properties Schema properties
     * @param string|null $description Schema description
     * @param string|null $parentClass Parent class for allOf inheritance
     * @param string[] $allOfRefs allOf schema references
     * @param array<string, string>|null $discriminator Discriminator mapping (propertyName => [value => ref])
     */
    public function __construct(
        public readonly string $name,
        public readonly array $properties = [],
        public readonly ?string $description = null,
        public readonly ?string $parentClass = null,
        public readonly array $allOfRefs = [],
        public readonly ?array $discriminator = null,
    ) {}
}

