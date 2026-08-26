<?php

declare(strict_types=1);

namespace futuretek\openapi\Generator;

use futuretek\openapi\Config;
use futuretek\openapi\GeneratorResult;
use futuretek\openapi\Parser\ParsedProperty;
use futuretek\openapi\Parser\ParsedSchema;

/**
 * Generates DTO classes from parsed schemas using DataMapper attributes.
 */
final class SchemaGenerator
{
    public function __construct(
        private readonly Config $config,
        private readonly GeneratorResult $result,
    ) {}

    /**
     * Generate all schema DTO files.
     *
     * @param ParsedSchema[] $schemas
     */
    public function generate(array $schemas): void
    {
        $dir = $this->config->schemaDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: $dir");
        }

        foreach ($schemas as $schema) {
            // Skip schemas with no properties and no parent — nothing useful to generate
            if (empty($schema->properties) && $schema->parentClass === null) {
                continue;
            }
            $this->generateSchema($schema, $dir);
        }
    }

    private function generateSchema(ParsedSchema $schema, string $dir): void
    {
        $namespace = $this->config->schemaNamespace();
        $enumNamespace = $this->config->enumNamespace();
        $className = $schema->name;

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = "namespace $namespace;";
        $lines[] = '';

        // Collect imports
        $imports = $this->collectImports($schema, $namespace, $enumNamespace);
        foreach ($imports as $import) {
            $lines[] = "use $import;";
        }
        if (!empty($imports)) {
            $lines[] = '';
        }

        // Class docblock
        if ($schema->description !== null) {
            $lines[] = '/**';
            foreach (explode("\n", $schema->description) as $descLine) {
                $lines[] = ' * ' . $descLine;
            }
            $lines[] = ' */';
        }

        // Class declaration
        $extends = '';
        if ($schema->parentClass !== null) {
            $extends = " extends $schema->parentClass";
        }

        $lines[] = "class $className$extends";
        $lines[] = '{';

        // Properties
        foreach ($schema->properties as $i => $property) {
            if ($i > 0) {
                $lines[] = '';
            }

            $propLines = $this->generateProperty($property, $enumNamespace, $namespace);
            foreach ($propLines as $propLine) {
                $lines[] = '    ' . $propLine;
            }
        }

        // Setters
        if (!empty($schema->properties)) {
            $this->warnDuplicateSetterNames($schema);

            $lines[] = '';
            foreach ($schema->properties as $i => $property) {
                if ($i > 0) {
                    $lines[] = '';
                }
                $setterLines = $this->generateSetter($property);
                foreach ($setterLines as $setterLine) {
                    $lines[] = '    ' . $setterLine;
                }
            }
        }

        $lines[] = '}';
        $lines[] = '';

        $filePath = $dir . DIRECTORY_SEPARATOR . $className . '.php';
        file_put_contents($filePath, implode("\n", $lines));
        $this->result->addGenerated($filePath);
    }

    /**
     * @return string[]
     */
    private function collectImports(ParsedSchema $schema, string $namespace, string $enumNamespace): array
    {
        $imports = [];
        $hasFormat = false;
        $hasArrayType = false;
        $hasMapType = false;

        foreach ($schema->properties as $property) {
            if ($property->format === 'date' || $property->format === 'date-time') {
                $hasFormat = true;
                $imports['DateTimeInterface'] = 'DateTimeInterface';
            }

            if ($property->arrayItemType === 'DateTimeInterface') {
                $imports['DateTimeInterface'] = 'DateTimeInterface';
            }

            if ($property->arrayItemType !== null && ($this->isClassName($property->arrayItemType) || $property->isFile)) {
                $hasArrayType = true;

                // Import array item class if it's a schema reference. Must check
                // $property->arrayItemIsEnum here, NOT $property->enumRef - enumRef is only ever
                // set for a *singular* enum-typed property (see its docblock), so for an
                // array-of-enum property it's always null and this import would silently never be
                // added, leaving the generated #[ArrayType(SomeEnum::class)] attribute pointing at
                // an unimported class name that resolves (wrongly) to the current namespace instead
                // of $enumNamespace - DataMapper's enum_exists()/class_exists() checks against that
                // wrong FQCN then fail, and array items are left as unconverted raw strings.
                if ($this->isClassName($property->arrayItemType) && $property->arrayItemIsEnum) {
                    $imports[$property->arrayItemType] = $enumNamespace . '\\' . $property->arrayItemType;
                }
            }

            if ($property->mapValueType !== null) {
                $hasMapType = true;
            }

            if ($property->isFile) {
                $imports['UploadedFileInterface'] = 'Psr\\Http\\Message\\UploadedFileInterface';
            }

            if ($property->enumRef !== null && $property->arrayItemType === null) {
                $imports[$property->enumRef] = $enumNamespace . '\\' . $property->enumRef;
            }

            // Ref in same namespace — no import needed
        }

        if ($hasFormat) {
            $imports['Format'] = 'futuretek\\datamapper\\attributes\\Format';
        }
        if ($hasArrayType) {
            $imports['ArrayType'] = 'futuretek\\datamapper\\attributes\\ArrayType';
        }
        if ($hasMapType) {
            $imports['MapType'] = 'futuretek\\datamapper\\attributes\\MapType';
        }

        // Parent class is in same namespace — no import needed

        sort($imports);
        return array_values($imports);
    }

    /**
     * @return string[]
     */
    private function generateProperty(ParsedProperty $property, string $enumNamespace, string $schemaNamespace): array
    {
        $lines = [];

        // Docblock
        $docLines = [];
        if ($property->description !== null) {
            $docLines[] = $property->description;
        }

        // @var type hint for typed arrays and maps
        $varType = $this->resolveVarType($property);
        if ($varType !== null) {
            $docLines[] = "@var $varType";
        }

        if (!empty($docLines)) {
            $lines[] = '/**';
            foreach ($docLines as $docLine) {
                $lines[] = ' * ' . $docLine;
            }
            $lines[] = ' */';
        }

        // Attributes
        if ($property->format === 'date' || $property->format === 'date-time') {
            $lines[] = "#[Format('{$property->format}')]";
        }

        if ($property->arrayItemType !== null && $this->isClassName($property->arrayItemType)) {
            $formatArg = $property->arrayItemFormat !== null ? ", format: '{$property->arrayItemFormat}'" : '';
            $lines[] = "#[ArrayType({$property->arrayItemType}::class{$formatArg})]";
        }

        if ($property->arrayItemType !== null && $property->isFile) {
            $lines[] = '#[ArrayType(UploadedFileInterface::class)]';
        }

        if ($property->mapValueType !== null) {
            $valueType = $this->isClassName($property->mapValueType)
                ? $property->mapValueType . '::class'
                : "'{$property->mapValueType}'";
            $lines[] = "#[MapType('string', $valueType)]";
        }

        // Property declaration
        $phpType = $this->resolvePropertyType($property);
        $nullable = $property->nullable && !$property->required ? '?' : '';

        // If nullable and not required, add null prefix
        if ($property->nullable || !$property->required) {
            $nullable = '?';
        }

        $defaultValue = '';
        if (!$property->required) {
            if ($property->default !== null) {
                $defaultValue = ' = ' . $this->exportDefault($property->default, $property->enumRef);
            } else {
                $defaultValue = ' = null';
            }
        } elseif ($property->default !== null) {
            $defaultValue = ' = ' . $this->exportDefault($property->default, $property->enumRef);
        }

        $lines[] = "public {$nullable}{$phpType} \${$property->name}{$defaultValue};";

        return $lines;
    }

    /**
     * @return string[]
     */
    private function generateSetter(ParsedProperty $property): array
    {
        $phpType = $this->resolvePropertyType($property);
        $nullable = ($property->nullable || !$property->required) ? '?' : '';
        $methodName = $this->setterName($property);

        return [
            "public function {$methodName}({$nullable}{$phpType} \$value): static",
            '{',
            "    \$this->{$property->name} = \$value;",
            '    return $this;',
            '}',
        ];
    }

    private function setterName(ParsedProperty $property): string
    {
        return 'set' . str_replace('_', '', ucwords($property->name, '_'));
    }

    private function warnDuplicateSetterNames(ParsedSchema $schema): void
    {
        $seen = [];
        foreach ($schema->properties as $property) {
            $name = $this->setterName($property);
            if (isset($seen[$name])) {
                $this->result->addWarning(
                    "Schema '{$schema->name}': properties '{$seen[$name]}' and '{$property->name}' both generate setter '{$name}'."
                );
            } else {
                $seen[$name] = $property->name;
            }
        }
    }

    private function resolvePropertyType(ParsedProperty $property): string
    {
        // Array of files — PHP type is array, not UploadedFileInterface
        if ($property->isFile && $property->arrayItemType !== null) {
            return 'array';
        }

        if ($property->isFile) {
            return 'UploadedFileInterface';
        }

        if ($property->enumRef !== null) {
            return $property->enumRef;
        }

        if ($property->format === 'date' || $property->format === 'date-time') {
            return 'DateTimeInterface';
        }

        if ($property->phpType === 'object') {
            return 'object';
        }

        // For referenced schemas (same namespace)
        if ($property->ref !== null) {
            return $property->ref;
        }

        return $property->phpType;
    }

    private function isClassName(string $type): bool
    {
        return preg_match('/^[A-Z]/', $type) === 1;
    }

    private function exportDefault(mixed $value, ?string $enumRef): string
    {
        if ($enumRef !== null) {
            return "$enumRef::from(" . var_export($value, true) . ')';
        }

        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return '[]';
        }

        if ($value === null) {
            return 'null';
        }

        return (string)$value;
    }

    /**
     * Resolve @var PHPDoc type for array and map properties.
     * Returns null if no special type hint is needed.
     */
    private function resolveVarType(ParsedProperty $property): ?string
    {
        $nullable = ($property->nullable || !$property->required) ? '|null' : '';

        // Typed array (e.g., Pet[], UploadedFileInterface[])
        if ($property->arrayItemType !== null) {
            $itemType = $property->isFile ? 'UploadedFileInterface' : $property->arrayItemType;
            return $itemType . '[]' . $nullable;
        }

        // Map type (e.g., array<string, Pet>)
        if ($property->mapValueType !== null) {
            return 'array<string, ' . $property->mapValueType . '>' . $nullable;
        }

        return null;
    }
}








