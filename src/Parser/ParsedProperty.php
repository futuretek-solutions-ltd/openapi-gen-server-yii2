<?php

declare(strict_types=1);

namespace futuretek\openapi\Parser;

/**
 * Normalized representation of a schema property.
 */
final class ParsedProperty
{
    /**
     * @param string $name Property name
     * @param string $phpType PHP type string
     * @param bool $required Whether the property is required
     * @param bool $nullable Whether the property is nullable
     * @param string|null $format OpenAPI format (date, date-time, binary, etc.)
     * @param string|null $description Property description
     * @param string|null $ref Reference to another schema class name
     * @param string|null $arrayItemType For array properties, the item type class name or scalar
     * @param string|null $mapValueType For map (additionalProperties) properties, the value type
     * @param string|null $enumRef Reference to an enum class name
     * @param mixed $default Default value
     * @param bool $isFile Whether this property represents a file upload
     */
    public function __construct(
        public readonly string $name,
        public readonly string $phpType,
        public readonly bool $required = false,
        public readonly bool $nullable = false,
        public readonly ?string $format = null,
        public readonly ?string $description = null,
        public readonly ?string $ref = null,
        public readonly ?string $arrayItemType = null,
        public readonly ?string $mapValueType = null,
        public readonly ?string $enumRef = null,
        public readonly mixed $default = null,
        public readonly bool $isFile = false,
    ) {}
}

