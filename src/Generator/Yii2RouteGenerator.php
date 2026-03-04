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
            $yiiPath = $this->convertPath($op->path);
            $controllerId = $this->toControllerId($op->controllerName);
            $actionId = $this->toActionId($op->operationId);
            $method = strtoupper($op->httpMethod);

            $route = "$controllerId/$actionId";

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
     * Convert OpenAPI path to Yii2 URL rule pattern.
     * e.g., /pets/{petId}/toys/{toyId} => pets/<petId>/toys/<toyId>
     */
    private function convertPath(string $path): string
    {
        // Remove leading slash
        $path = ltrim($path, '/');

        // Convert {param} to <param>
        return preg_replace('/\{([^}]+)}/', '<$1>', $path);
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


