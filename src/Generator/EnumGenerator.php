<?php

declare(strict_types=1);

namespace futuretek\openapi\Generator;

use futuretek\openapi\Config;
use futuretek\openapi\GeneratorResult;
use futuretek\openapi\Parser\ParsedEnum;

/**
 * Generates PHP 8.4 backed enums from parsed enum definitions.
 */
final class EnumGenerator
{
    public function __construct(
        private readonly Config $config,
        private readonly GeneratorResult $result,
    ) {}

    /**
     * Generate all enum files.
     *
     * @param ParsedEnum[] $enums
     */
    public function generate(array $enums): void
    {
        $dir = $this->config->enumDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: $dir");
        }

        foreach ($enums as $enum) {
            $this->generateEnum($enum, $dir);
        }
    }

    private function generateEnum(ParsedEnum $enum, string $dir): void
    {
        $namespace = $this->config->enumNamespace();

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = "namespace $namespace;";
        $lines[] = '';

        // Docblock
        if ($enum->description !== null) {
            $lines[] = '/**';
            foreach (explode("\n", $enum->description) as $descLine) {
                $lines[] = ' * ' . $descLine;
            }
            $lines[] = ' */';
        }

        $lines[] = "enum {$enum->name}: {$enum->backingType}";
        $lines[] = '{';

        foreach ($enum->values as $index => $value) {
            $caseName = $this->toCaseName($value);

            // Per-case description
            if (isset($enum->descriptions[$index]) && $enum->descriptions[$index] !== '') {
                $lines[] = '    /** ' . $enum->descriptions[$index] . ' */';
            }

            $caseValue = $enum->backingType === 'int'
                ? (string)$value
                : "'" . addslashes((string)$value) . "'";

            $lines[] = "    case $caseName = $caseValue;";
        }

        $lines[] = '}';
        $lines[] = '';

        $filePath = $dir . DIRECTORY_SEPARATOR . $enum->name . '.php';
        file_put_contents($filePath, implode("\n", $lines));
        $this->result->addGenerated($filePath);
    }

    /**
     * Convert an enum value to a valid PHP case name.
     * e.g., "in_progress" => "InProgress", "active" => "Active", "404" => "V404"
     */
    private function toCaseName(string|int $value): string
    {
        $value = (string)$value;

        // Replace non-alphanumeric chars with underscores for splitting
        $normalized = preg_replace('/[^a-zA-Z0-9]+/', '_', $value);

        // PascalCase
        $parts = array_filter(explode('_', $normalized));
        $caseName = implode('', array_map(ucfirst(...), $parts));

        // If starts with digit, prefix with V
        if ($caseName !== '' && ctype_digit($caseName[0])) {
            $caseName = 'V' . $caseName;
        }

        // Fallback for empty
        if ($caseName === '') {
            $caseName = 'Unknown';
        }

        return $caseName;
    }
}


