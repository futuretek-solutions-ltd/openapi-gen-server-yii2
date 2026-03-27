<?php

declare(strict_types=1);

namespace futuretek\openapi\Generator;

use futuretek\openapi\Config;
use futuretek\openapi\GeneratorResult;
use futuretek\openapi\Parser\ParsedOperation;
use futuretek\openapi\Parser\ParsedParameter;

/**
 * Generates controller interface files from parsed operations.
 *
 * Each unique controller name produces one interface with action methods.
 * Method signature: body first (if present), then path params, query params, header params, cookie params.
 */
final class ControllerInterfaceGenerator
{
    public function __construct(
        private readonly Config $config,
        private readonly GeneratorResult $result,
    ) {}

    /**
     * Generate all controller interface files.
     *
     * @param ParsedOperation[] $operations
     */
    public function generate(array $operations): void
    {
        $dir = $this->config->controllerDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: $dir");
        }

        // Group operations by controller name
        $grouped = $this->groupByController($operations);

        foreach ($grouped as $controllerName => $ops) {
            $this->generateInterface($controllerName, $ops, $dir);
        }
    }

    /**
     * @param ParsedOperation[] $operations
     * @return array<string, ParsedOperation[]>
     */
    private function groupByController(array $operations): array
    {
        $grouped = [];
        foreach ($operations as $op) {
            $grouped[$op->controllerName][] = $op;
        }
        return $grouped;
    }

    /**
     * @param ParsedOperation[] $operations
     */
    private function generateInterface(string $controllerName, array $operations, string $dir): void
    {
        // Use operation-level namespace override if present, otherwise global
        $namespace = $this->config->controllerNamespace();
        $schemaNamespace = $this->config->schemaNamespace();
        $enumNamespace = $this->config->enumNamespace();
        $interfaceName = $controllerName . 'ControllerInterface';

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = "namespace $namespace;";
        $lines[] = '';

        // Collect all imports
        $imports = $this->collectImports($operations, $schemaNamespace, $enumNamespace, $namespace);
        foreach ($imports as $import) {
            $lines[] = "use $import;";
        }
        if (!empty($imports)) {
            $lines[] = '';
        }

        $lines[] = "interface $interfaceName";
        $lines[] = '{';

        foreach ($operations as $i => $op) {
            if ($i > 0) {
                $lines[] = '';
            }

            $methodLines = $this->generateMethod($op);
            foreach ($methodLines as $methodLine) {
                $lines[] = '    ' . $methodLine;
            }
        }

        $lines[] = '}';
        $lines[] = '';

        $filePath = $dir . DIRECTORY_SEPARATOR . $interfaceName . '.php';
        file_put_contents($filePath, implode("\n", $lines));
        $this->result->addGenerated($filePath);
    }

    /**
     * @return string[]
     */
    private function generateMethod(ParsedOperation $op): array
    {
        $lines = [];

        // Docblock
        $lines[] = '/**';
        if ($op->description !== null) {
            foreach (explode("\n", $op->description) as $descLine) {
                $lines[] = ' * ' . $descLine;
            }
            $lines[] = ' *';
        }
        $lines[] = " * @method {$op->httpMethod} {$op->path}";

        // Document parameters
        if ($op->requestBodyClass !== null) {
            if ($op->requestBodyIsArray) {
                $lines[] = " * @param {$op->requestBodyClass}[] \$body Request body (array)";
            } else {
                $lines[] = " * @param {$op->requestBodyClass} \$body Request body";
            }
        }
        foreach ($op->parameters as $param) {
            $lines[] = " * @param {$param->type}" . ($param->nullable ? '|null' : '') . " \${$param->phpName} [{$param->in}] {$param->description}";
        }

        if ($op->successResponseClass !== null) {
            if ($op->successResponseIsArray) {
                $lines[] = " * @return {$op->successResponseClass}[]";
            } else {
                $lines[] = " * @return {$op->successResponseClass}";
            }
        }

        $lines[] = ' */';

        // Method signature
        $params = $this->buildMethodParameters($op);
        $returnType = $op->successResponseIsArray ? 'array' : ($op->successResponseClass ?? 'void');

        $signature = "public function {$op->actionName}($params): $returnType;";
        $lines[] = $signature;

        return $lines;
    }

    private function buildMethodParameters(ParsedOperation $op): string
    {
        $required = [];
        $optional = [];

        // Body parameter — bucket by required/optional
        if ($op->requestBodyClass !== null) {
            $type = $op->requestBodyIsArray ? 'array' : $op->requestBodyClass;
            if ($op->requestBodyRequired) {
                $required[] = "{$type} \$body";
            } else {
                $optional[] = "?{$type} \$body = null";
            }
        }

        // Parameters in order: path, query, header, cookie (required before optional within each group)
        $ordered = $this->orderParameters($op->parameters);

        foreach ($ordered as $param) {
            $type = $param->type;

            if (!$param->required) {
                if ($param->default !== null) {
                    $default = ' = ' . $this->exportDefault($param->default);
                } else {
                    $default = ' = null';
                }
                $optional[] = "?{$type} \${$param->phpName}{$default}";
            } else {
                $nullable = $param->nullable ? '?' : '';
                $required[] = "{$nullable}{$type} \${$param->phpName}";
            }
        }

        // Required parameters must always precede optional ones
        return implode(', ', array_merge($required, $optional));
    }

    /**
     * Order parameters: path first (required), then query, header, cookie.
     * Within each group, required params come first.
     *
     * @param ParsedParameter[] $parameters
     * @return ParsedParameter[]
     */
    private function orderParameters(array $parameters): array
    {
        $order = ['path' => 0, 'query' => 1, 'header' => 2, 'cookie' => 3];

        usort($parameters, function (ParsedParameter $a, ParsedParameter $b) use ($order) {
            $orderA = $order[$a->in] ?? 4;
            $orderB = $order[$b->in] ?? 4;

            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }

            // Required first within same group
            return ($b->required ? 1 : 0) <=> ($a->required ? 1 : 0);
        });

        return $parameters;
    }

    private const BUILTIN_TYPES = ['int', 'float', 'string', 'bool', 'array', 'object', 'mixed', 'void', 'null', 'true', 'false', 'never'];

    /** PSR / well-known interface short names → their fully-qualified names. */
    private const KNOWN_INTERFACE_IMPORTS = [
        'UploadedFileInterface' => 'Psr\\Http\\Message\\UploadedFileInterface',
    ];

    /**
     * @return string[]
     */
    private function collectImports(array $operations, string $schemaNamespace, string $enumNamespace, string $currentNamespace): array
    {
        $imports = [];

        foreach ($operations as $op) {
            if ($op->requestBodyClass !== null && !in_array($op->requestBodyClass, self::BUILTIN_TYPES, true) && !$op->requestBodyIsArray) {
                $imports[$op->requestBodyClass] = $schemaNamespace . '\\' . $op->requestBodyClass;
            }

            if ($op->successResponseClass !== null && !in_array($op->successResponseClass, self::BUILTIN_TYPES, true)) {
                if ($op->successResponseIsArray) {
                    // Item class needs to be imported
                    if (!str_contains($op->successResponseClass, '\\')) {
                        $imports[$op->successResponseClass] = $schemaNamespace . '\\' . $op->successResponseClass;
                    }
                } elseif (isset(self::KNOWN_INTERFACE_IMPORTS[$op->successResponseClass])) {
                    // Well-known PSR/framework interface — import from its real namespace.
                    $imports[$op->successResponseClass] = self::KNOWN_INTERFACE_IMPORTS[$op->successResponseClass];
                } elseif (!str_contains($op->successResponseClass, '\\')) {
                    // Regular generated schema class.
                    $imports[$op->successResponseClass] = $schemaNamespace . '\\' . $op->successResponseClass;
                }
                // FQCN types (contain backslash) are used as-is in the signature — no import needed.
            }

            foreach ($op->parameters as $param) {
                if ($param->enumRef !== null) {
                    $imports[$param->enumRef] = $enumNamespace . '\\' . $param->enumRef;
                }
            }
        }

        // Remove imports in the same namespace
        $imports = array_filter($imports, fn(string $fqcn) => !str_starts_with($fqcn, $currentNamespace . '\\'));

        $values = array_unique(array_values($imports));
        sort($values);
        return $values;
    }

    private function exportDefault(mixed $value): string
    {
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return (string)$value;
    }
}





