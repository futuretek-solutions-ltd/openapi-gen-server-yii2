<?php

declare(strict_types=1);

namespace futuretek\openapi;

use futuretek\openapi\Generator\AbstractControllerGenerator;
use futuretek\openapi\Generator\ControllerInterfaceGenerator;
use futuretek\openapi\Generator\EnumGenerator;
use futuretek\openapi\Generator\SchemaGenerator;
use futuretek\openapi\Generator\Yii2RouteGenerator;
use futuretek\openapi\Parser\OpenApiParser;

/**
 * Main generator orchestrator.
 *
 * Parses the OpenAPI spec and delegates to individual generators.
 */
final class Generator
{
    public function __construct(
        private readonly Config $config,
    ) {}

    /**
     * Run the full generation pipeline.
     *
     * In strict mode any warning collected during directory cleanup or spec parsing
     * will abort the run before any files are written.
     */
    public function generate(bool $strict = false): GeneratorResult
    {
        $result = new GeneratorResult();

        // Ensure base directory exists
        if (!is_dir($this->config->baseDir) && !mkdir($this->config->baseDir, 0755, true) && !is_dir($this->config->baseDir)) {
            $result->addError("Failed to create base directory: {$this->config->baseDir}");
            return $result;
        }

        // Clean target directories if requested
        if ($this->config->cleanTargetDirs) {
            $this->cleanDirectory($this->config->enumDir(), $result);
            $this->cleanDirectory($this->config->schemaDir(), $result);
            $this->cleanDirectory($this->config->controllerDir(), $result);
        }

        // Parse OpenAPI spec
        $parser = new OpenApiParser($this->config, $result);

        try {
            $parser->parse();
        } catch (\Throwable $e) {
            $result->addError("Failed to parse OpenAPI spec: {$e->getMessage()}");
            return $result;
        }

        // In strict mode, abort before writing any files if warnings were produced
        if ($strict && $result->hasWarnings()) {
            return $result;
        }

        $schemas = $parser->getSchemas();
        $enums = $parser->getEnums();
        $operations = $parser->getOperations();

        // Generate enums
        $enumGenerator = new EnumGenerator($this->config, $result);
        $enumGenerator->generate($enums);

        // Generate schemas (DTOs)
        $schemaGenerator = new SchemaGenerator($this->config, $result);
        $schemaGenerator->generate($schemas);

        // Generate controller interfaces
        $interfaceGenerator = new ControllerInterfaceGenerator($this->config, $result);
        $interfaceGenerator->generate($operations);

        // Generate abstract controllers
        $abstractGenerator = new AbstractControllerGenerator($this->config, $result);
        $abstractGenerator->generate($operations, $schemas);

        // Generate Yii2 routes
        $routeGenerator = new Yii2RouteGenerator($this->config, $result);
        $routeGenerator->generate($operations);

        return $result;
    }

    /**
     * Remove all .php files from a directory.
     *
     * Only removes files in the immediate directory (non-recursive) to avoid
     * accidentally deleting user code in subdirectories.
     */
    private function cleanDirectory(string $dir, GeneratorResult $result): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file) && !unlink($file)) {
                $result->addWarning("Failed to delete file during cleanup: $file");
            }
        }
    }
}



