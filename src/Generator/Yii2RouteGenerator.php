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
     * Detect routes where a parametric segment can shadow another route of the same
     * HTTP method — either by matching a static segment at the same depth, or (for a
     * param whose regex isn't scoped to a single segment) by swallowing a longer
     * route's extra trailing segments. Emits a warning for each ambiguous pair,
     * noting which appears first (and would therefore shadow the other).
     *
     * Example: GET /issues/{id} listed before GET /issues/create →
     *   Yii2 matches /issues/create against the parametric rule and the static rule is never reached.
     *
     * Routes of differing depth are still compared: this used to be skipped outright
     * on the assumption that different segment counts can never collide, but that's
     * only true when every path-param regex is scoped to a single segment. Checking
     * it explicitly (via {@see paramCanSpanSegments()}) keeps this correct even if a
     * future type mapping ever produces a regex that can match `/`.
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

        // A longer than B: every regex this generator emits is scoped to a single
        // path segment and Yii2 anchors the whole pattern, so a rule requiring
        // strictly more segments than B's path can never match it.
        if (count($segsA) > count($segsB)) {
            return;
        }

        $aShorter = count($segsA) < count($segsB);
        $minLen = count($segsA);

        // Build a param-name → PHP type map for A. Only A's types matter here: A is
        // always the earlier-declared route (see checkAmbiguousRoutes), and Yii2
        // tries rules in order, so shadowing is only possible when A is the broad one.
        $typesA = [];
        foreach ($a->parameters as $param) {
            if ($param->in === 'path') {
                $typesA[$param->name] = $param->type;
            }
        }

        $aShadowsB = false;

        for ($i = 0; $i < $minLen; $i++) {
            $segA = $segsA[$i];
            $segB = $segsB[$i];
            $isLastOfA = $i === $minLen - 1;

            $isParamA = str_starts_with($segA, '{') && str_ends_with($segA, '}');
            $isParamB = str_starts_with($segB, '{') && str_ends_with($segB, '}');

            if (!$isParamA && !$isParamB) {
                if ($segA !== $segB) {
                    return; // Segments differ statically — routes are unambiguous
                }
                continue; // Same static segment, keep checking
            }

            if ($isParamA && $isParamB) {
                if ($isLastOfA && $aShorter) {
                    // A ends here but B continues beyond it — A can only swallow the
                    // rest of B if its param regex can also cross into a following segment.
                    $paramName = trim($segA, '{}');
                    if ($this->paramCanSpanSegments($typesA[$paramName] ?? 'string')) {
                        $aShadowsB = true;
                    }
                    break;
                }
                continue; // Both parametric — same width so far, keep checking
            }

            if ($isParamA) {
                // A has the param, B has a static segment here.
                $paramName = trim($segA, '{}');
                $paramType = $typesA[$paramName] ?? 'string';

                if ($isLastOfA && $aShorter) {
                    // A ends here but B continues — A only shadows the rest of B if
                    // its regex can cross into the following segments.
                    if ($this->paramCanSpanSegments($paramType)) {
                        $aShadowsB = true;
                    }
                    break;
                }

                // Same shared position (equal depth, or still within the common
                // prefix of a shorter A): A shadows B if its param isn't restricted
                // to a pattern that would exclude B's static text.
                if (!in_array($paramType, ['int', 'float'], true)) {
                    $aShadowsB = true;
                }
                continue;
            }

            // B has the param, A has a static segment → A fires first (correct), no problem
        }

        if ($aShadowsB) {
            $this->result->addWarning(
                "Ambiguous routes: {$a->httpMethod} {$a->path} (listed first) will shadow " .
                "{$b->httpMethod} {$b->path} — its path parameter also matches {$b->path}. " .
                "Move {$b->path} before {$a->path} in the spec, or constrain the parameter type to int/float."
            );
        }
    }

    /**
     * Convert OpenAPI path to Yii2 URL rule pattern with type-based regex constraints.
     *
     * - Integer/float parameters → <name:\d+>
     * - String and all other types → <name:[^/]+>
     *
     * e.g., /pets/{petId}/items/{itemId} => pets/<petId:[^/]+>/items/<itemId:[^/]+>
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
     * - string and all others → [^/]+ (anything but a path separator)
     *
     * Every pattern produced here must be scoped to a single path segment
     * (i.e. must never match `/`), otherwise a shorter route's trailing
     * parameter can silently swallow a longer, more specific route's extra
     * segments regardless of declaration order. {@see paramCanSpanSegments()}
     * relies on this invariant to detect ambiguous routes.
     */
    private function typeToRegex(string $phpType): string
    {
        return match ($phpType) {
            'int', 'float' => '\d+',
            default => '[^/]+',
        };
    }

    /**
     * Whether a PHP type's regex (per {@see typeToRegex()}) is capable of
     * matching a `/` and therefore spanning more than one path segment.
     *
     * Derived from the actual regex output rather than hardcoded type names,
     * so the ambiguity checker below stays correct even if typeToRegex() ever
     * gains a new type mapping.
     */
    private function paramCanSpanSegments(string $phpType): bool
    {
        return !in_array($this->typeToRegex($phpType), ['\d+', '[^/]+'], true);
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


