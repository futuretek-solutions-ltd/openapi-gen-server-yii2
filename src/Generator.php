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
     */
    public function generate(): GeneratorResult
    {
        $result = new GeneratorResult();

        // Ensure base directory exists
        if (!is_dir($this->config->baseDir) && !mkdir($this->config->baseDir, 0755, true) && !is_dir($this->config->baseDir)) {
            $result->addError("Failed to create base directory: {$this->config->baseDir}");
            return $result;
        }

        // Parse OpenAPI spec
        $parser = new OpenApiParser($this->config, $result);

        try {
            $parser->parse();
        } catch (\Throwable $e) {
            $result->addError("Failed to parse OpenAPI spec: {$e->getMessage()}");
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
}



