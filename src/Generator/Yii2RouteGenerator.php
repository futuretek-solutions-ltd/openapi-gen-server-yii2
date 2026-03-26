<?php

declare(strict_types=1);

namespace futuretek\openapi\Generator;

use futuretek\openapi\Config;
use futuretek\openapi\GeneratorResult;
use futuretek\openapi\Parser\ParsedOperation;

/**
 * Generates Yii2 URL rules configuration file from parsed operations.
 */
final class Yii2RouteGenerator
{
    public function __construct(
        private readonly Config $config,
        private readonly GeneratorResult $result,
    ) {}

    /**
     * @param ParsedOperation[] $operations
     */
    public function generate(array $operations): void
    {
        $this->checkAmbiguousRoutes($operations);

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'declare(strict_types=1);';
        $lines[] = '';
        $lines[] = '/**';
        $lines[] = ' * Yii2 URL rules generated from OpenAPI specification.';
        $lines[] = ' *';
        $lines[] = ' * This file is auto-generated. Do not edit manually.';
        $lines[] = ' *';
        $lines[] = ' * Usage in Yii2 config:';
        $lines[] = " * 'urlManager' => [";
        $lines[] = " *     'rules' => require __DIR__ . '/routes.php',";
        $lines[] = " * ],";
        $lines[] = ' */';
        $lines[] = '';
        $lines[] = 'return [';

        foreach ($operations as $op) {
            // Build a map of path parameter name => PHP type for regex generation
            $pathParamTypes = [];
            foreach ($op->parameters as $param) {
                if ($param->in === 'path') {
                    $pathParamTypes[$param->name] = $param->type;
                }
            }

            $yiiPath = $this->convertPath($op->path, $pathParamTypes);
            $controllerId = $this->toControllerId($op->controllerName);
            $actionId = $this->toActionId($op->operationId);
            $method = strtoupper($op->httpMethod);

            $route = $this->config->routePrefix !== null
                ? "{$this->config->routePrefix}/$controllerId/$actionId"
                : "$controllerId/$actionId";

            $comment = $op->description !== null
                ? " // $op->description"
                : '';

            $lines[] = "    '$method $yiiPath' => '$route',$comment";
        }

        $lines[] = '];';
        $lines[] = '';

        $dir = dirname($this->config->routeFilePath());
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: $dir");
        }

        file_put_contents($this->config->routeFilePath(), implode("\n", $lines));
        $this->result->addGenerated($this->config->routeFilePath());
    }

    /**
     * Detect routes where a parametric segment with \S+ regex can shadow a static segment
     * in another route of the same HTTP method. Emits a warning for each ambiguous pair,
     * noting which appears first (and would therefore shadow the other).
     *
     * Example: GET /issues/{id} (\S+) listed before GET /issues/create →
     *   Yii2 matches /issues/create against the parametric rule and the static rule is never reached.
     *
     * @param ParsedOperation[] $operations
     */
    private function checkAmbiguousRoutes(array $operations): void
    {
        // Group by HTTP method, preserving declaration order (important for shadow direction)
        $byMethod = [];
        foreach ($operations as $op) {
            $byMethod[$op->httpMethod][] = $op;
        }

        foreach ($byMethod as $ops) {
            $n = count($ops);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $this->checkPair($ops[$i], $ops[$j]);
                }
            }
        }
    }

    /**
     * Check whether two operations produce ambiguous Yii2 route patterns.
     * $a appears before $b in the spec (and therefore in the route file).
     */
    private function checkPair(ParsedOperation $a, ParsedOperation $b): void
    {
        $segsA = array_values(array_filter(explode('/', $a->path), static fn(string $s) => $s !== ''));
        $segsB = array_values(array_filter(explode('/', $b->path), static fn(string $s) => $s !== ''));

        if (count($segsA) !== count($segsB)) {
            return; // Different depth — Yii2 won't confuse them
        }

        // Build param-name → PHP type maps for each operation
        $typesA = [];
        $typesB = [];
        foreach ($a->parameters as $param) {
            if ($param->in === 'path') {
                $typesA[$param->name] = $param->type;
            }
        }
        foreach ($b->parameters as $param) {
            if ($param->in === 'path') {
                $typesB[$param->name] = $param->type;
            }
        }

        // Determine whether A (first in spec) has a broad (\S+) param where B has a static segment.
        // That means A's rule fires first in Yii2 and swallows B's static path.
        $aShadowsB = false;

        for ($i = 0, $n = count($segsA); $i < $n; $i++) {
            $segA = $segsA[$i];
            $segB = $segsB[$i];

            $isParamA = str_starts_with($segA, '{') && str_ends_with($segA, '}');
            $isParamB = str_starts_with($segB, '{') && str_ends_with($segB, '}');

            if (!$isParamA && !$isParamB) {
                if ($segA !== $segB) {
                    return; // Segments differ statically — routes are unambiguous
                }
                continue; // Same static segment, keep checking
            }

            if ($isParamA && $isParamB) {
                continue; // Both parametric — same "width", keep checking
            }

            // A has the param, B has a static segment → A could shadow B
            if ($isParamA) {
                $paramName = trim($segA, '{}');
                $paramType = $typesA[$paramName] ?? 'string';
                if (!in_array($paramType, ['int', 'float'], true)) {
                    $aShadowsB = true;
                }
            }
            // B has the param, A has a static segment → A fires first (correct), no problem
        }

        // A appears before B in the route file (spec order is preserved).
        // Shadowing only happens when A's broad (\S+) param would consume B's static segment —
        // because Yii2 tries A first. If B has the param and A is static, A fires first and wins.
        if ($aShadowsB) {
            $this->result->addWarning(
                "Ambiguous routes: {$a->httpMethod} {$a->path} (listed first) will shadow " .
                "{$b->httpMethod} {$b->path} — the \\S+ path parameter matches the static segment. " .
                "Move {$b->path} before {$a->path} in the spec, or constrain the parameter type to int/float."
            );
        }
    }

    /**
     * Convert OpenAPI path to Yii2 URL rule pattern with type-based regex constraints.
     *
     * - Integer/float parameters → <name:\d+>
     * - String and all other types → <name:\S+>
     *
     * e.g., /pets/{petId}/items/{itemId} => pets/<petId:\S+>/items/<itemId:\S+>
     *
     * @param array<string, string> $pathParamTypes Map of param name => PHP type
     */
    private function convertPath(string $path, array $pathParamTypes = []): string
    {
        // Remove leading slash
        $path = ltrim($path, '/');

        // Convert {param} to <param:REGEX> based on parameter type
        return preg_replace_callback('/\{([^}]+)}/', function (array $matches) use ($pathParamTypes): string {
            $name = $matches[1];
            $type = $pathParamTypes[$name] ?? 'string';
            $regex = $this->typeToRegex($type);

            return "<{$name}:{$regex}>";
        }, $path);
    }

    /**
     * Map a PHP type to a Yii2 URL parameter regex pattern.
     *
     * - int, float → \d+ (digits only)
     * - string and all others → \S+ (any non-whitespace)
     */
    private function typeToRegex(string $phpType): string
    {
        return match ($phpType) {
            'int', 'float' => '\d+',
            default => '\S+',
        };
    }

    /**
     * Convert PascalCase controller name to kebab-case controller ID.
     * e.g., PetStore => pet-store
     */
    private function toControllerId(string $controllerName): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $controllerName));
    }

    /**
     * Convert camelCase operationId to kebab-case action ID.
     * e.g., createPet => create-pet
     */
    private function toActionId(string $operationId): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $operationId));
    }
}


