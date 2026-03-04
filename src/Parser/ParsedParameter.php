<?php

declare(strict_types=1);

namespace futuretek\openapi\Parser;

/**
 * Normalized representation of an OpenAPI parameter.
 */
final class ParsedParameter
{
    /** @var string PHP-safe variable name (camelCase, no hyphens) */
    public readonly string $phpName;

    public function __construct(
        public readonly string $name,
        public readonly string $in, // path, query, header, cookie
        public readonly bool $required,
        public readonly string $type, // PHP type
        public readonly bool $nullable,
        public readonly ?string $format = null,
        public readonly mixed $default = null,
        public readonly ?string $description = null,
        public readonly ?string $enumRef = null,
    ) {
        $this->phpName = self::toPhpName($name);
    }

    /**
     * Convert a parameter name to a valid PHP variable name.
     * e.g., "X-Request-Id" => "xRequestId", "per_page" => "perPage"
     */
    private static function toPhpName(string $name): string
    {
        // Already a valid PHP identifier with no special chars
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            return $name;
        }

        // Convert kebab-case / other separators to camelCase
        $parts = preg_split('/[-_.]/', $name);
        $result = lcfirst(array_shift($parts));
        foreach ($parts as $part) {
            $result .= ucfirst(strtolower($part));
        }

        return $result;
    }
}


