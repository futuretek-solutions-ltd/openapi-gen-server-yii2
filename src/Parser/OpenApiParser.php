<?php

declare(strict_types=1);

namespace futuretek\openapi\Parser;

use cebe\openapi\Reader;
use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\PathItem;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;
use cebe\openapi\ReferenceContext;
use futuretek\openapi\Config;
use futuretek\openapi\GeneratorResult;

/**
 * Parses an OpenAPI 3.0.x specification into normalized structures for code generation.
 */
final class OpenApiParser
{
    private OpenApi $openApi;

    /** @var ParsedSchema[] */
    private array $schemas = [];

    /** @var ParsedEnum[] */
    private array $enums = [];

    /** @var ParsedOperation[] */
    private array $operations = [];

    /** @var array<string, true> Track operation IDs for duplicate detection */
    private array $operationIds = [];

    /** @var array<string, string> Known component schema names by document position pointer */
    private array $knownSchemaPointers = [];

    /** @var array<string, string> Known component enum names by document position pointer */
    private array $knownEnumPointers = [];

    public function __construct(
        private readonly Config $config,
        private readonly GeneratorResult $result,
    ) {}

    /**
     * Parse the OpenAPI spec file and return normalized structures.
     */
    public function parse(): void
    {
        $specPath = $this->config->specPath;

        if (str_ends_with($specPath, '.json')) {
            $this->openApi = Reader::readFromJsonFile($specPath, OpenApi::class, ReferenceContext::RESOLVE_MODE_ALL);
        } else {
            $this->openApi = Reader::readFromYamlFile($specPath, OpenApi::class, ReferenceContext::RESOLVE_MODE_ALL);
        }

        // Pre-index all component schemas/enums by their document position
        $this->indexComponentSchemas();
        $this->parseSchemas();
        $this->parsePaths();
    }

    /** @return ParsedSchema[] */
    public function getSchemas(): array
    {
        return $this->schemas;
    }

    /** @return ParsedEnum[] */
    public function getEnums(): array
    {
        return $this->enums;
    }

    /** @return ParsedOperation[] */
    public function getOperations(): array
    {
        return $this->operations;
    }

    /**
     * Build an index of all component schema pointers so we can detect resolved $ref.
     */
    private function indexComponentSchemas(): void
    {
        if (!isset($this->openApi->components) || !isset($this->openApi->components->schemas)) {
            return;
        }

        foreach ($this->openApi->components->schemas as $name => $schema) {
            if ($schema instanceof Reference) {
                continue;
            }

            $pointer = '/components/schemas/' . $name;

            if (!empty($schema->enum)) {
                $this->knownEnumPointers[$pointer] = $name;
            } else {
                $this->knownSchemaPointers[$pointer] = $name;
            }
        }
    }

    /**
     * Check if a schema is actually a resolved $ref to a known component schema.
     * Returns the component name if it is, null otherwise.
     */
    private function getComponentSchemaName(Schema $schema): ?string
    {
        $pos = $schema->getDocumentPosition();
        if ($pos === null) {
            return null;
        }

        $pointer = $pos->getPointer();

        return $this->knownSchemaPointers[$pointer] ?? null;
    }

    /**
     * Check if a schema is actually a resolved $ref to a known component enum.
     * Returns the enum name if it is, null otherwise.
     */
    private function getComponentEnumName(Schema $schema): ?string
    {
        $pos = $schema->getDocumentPosition();
        if ($pos === null) {
            return null;
        }

        $pointer = $pos->getPointer();

        return $this->knownEnumPointers[$pointer] ?? null;
    }

    /**
     * Parse all component schemas.
     */
    private function parseSchemas(): void
    {
        if (!isset($this->openApi->components) || !isset($this->openApi->components->schemas)) {
            return;
        }

        foreach ($this->openApi->components->schemas as $name => $schema) {
            if ($schema instanceof Reference) {
                continue;
            }

            // Top-level enum schema
            if (!empty($schema->enum)) {
                $this->parseEnumSchema($name, $schema);
                continue;
            }

            // Object schema
            if ($schema->type === 'object' || $schema->properties !== null || $schema->allOf !== null) {
                $this->parseObjectSchema($name, $schema);
            }
        }
    }

    /**
     * Parse a top-level enum schema.
     */
    private function parseEnumSchema(string $name, Schema $schema): void
    {
        $backingType = match ($schema->type) {
            'integer' => 'int',
            default => 'string',
        };

        $descriptions = [];
        $extensions = $this->getExtensions($schema);
        if (isset($extensions['x-enum-descriptions']) && is_array($extensions['x-enum-descriptions'])) {
            $descriptions = $extensions['x-enum-descriptions'];
        }

        $this->enums[$name] = new ParsedEnum(
            name: $name,
            backingType: $backingType,
            values: $schema->enum,
            descriptions: $descriptions,
            description: $schema->description,
        );
    }

    /**
     * Parse an object schema into a ParsedSchema with properties.
     */
    private function parseObjectSchema(string $name, Schema $schema): void
    {
        // Skip if already parsed
        if (isset($this->schemas[$name])) {
            return;
        }

        $properties = [];
        $requiredFields = $schema->required ?? [];
        $parentClass = null;
        $allOfRefs = [];

        // Handle allOf (composition/inheritance)
        if ($schema->allOf !== null) {
            $merged = $this->mergeAllOf($schema->allOf, $requiredFields);
            $properties = $merged['properties'];
            $parentClass = $merged['parentClass'];
            $allOfRefs = $merged['refs'];
            $requiredFields = $merged['required'];
        }

        // Handle direct properties
        if ($schema->properties !== null) {
            foreach ($schema->properties as $propName => $propSchema) {
                if ($propSchema instanceof Reference) {
                    continue;
                }
                $properties[$propName] = $this->parseProperty($propName, $propSchema, in_array($propName, $requiredFields, true));
            }
        }

        // Handle discriminator
        $discriminator = null;
        if ($schema->discriminator !== null) {
            $propertyName = $schema->discriminator->propertyName;
            $mapping = [];

            if (!empty($schema->discriminator->mapping)) {
                // Explicit mapping: convert $ref paths to class names
                foreach ($schema->discriminator->mapping as $value => $ref) {
                    $mapping[$value] = $this->extractRefName($ref);
                }
            } elseif ($schema->oneOf !== null || $schema->anyOf !== null) {
                // Auto-derive mapping from oneOf/anyOf: use schema name as discriminator value
                $unionSchemas = $schema->oneOf ?? $schema->anyOf;
                foreach ($unionSchemas as $item) {
                    if ($item instanceof Reference) {
                        $refName = $this->extractRefName($item->getJsonReference()->getJsonPointer()->getPointer());
                        $mapping[lcfirst($refName)] = $refName;
                    } elseif ($item instanceof Schema) {
                        $componentName = $this->getComponentSchemaName($item);
                        if ($componentName !== null) {
                            $mapping[lcfirst($componentName)] = $componentName;
                        }
                    }
                }
            }

            $discriminator = [
                'propertyName' => $propertyName,
                'mapping' => $mapping,
            ];
        }

        $this->schemas[$name] = new ParsedSchema(
            name: $name,
            properties: array_values($properties),
            description: $schema->description,
            parentClass: $parentClass,
            allOfRefs: $allOfRefs,
            discriminator: $discriminator,
        );
    }

    /**
     * Merge allOf schemas into a single property list.
     *
     * @param array $allOf
     * @param array $requiredFields
     * @return array{properties: ParsedProperty[], parentClass: ?string, refs: string[], required: string[]}
     */
    private function mergeAllOf(array $allOf, array $requiredFields): array
    {
        $properties = [];
        $parentClass = null;
        $refs = [];

        foreach ($allOf as $item) {
            if ($item instanceof Reference) {
                $refName = $this->extractRefName($item->getJsonReference()->getJsonPointer()->getPointer());
                $refs[] = $refName;
                $parentClass ??= $refName;
                continue;
            }

            if ($item instanceof Schema) {
                // Check if this is a resolved $ref to a known component
                $componentName = $this->getComponentSchemaName($item);
                if ($componentName !== null) {
                    $refs[] = $componentName;
                    $parentClass ??= $componentName;
                    continue;
                }

                $itemRequired = $item->required ?? [];
                $requiredFields = array_merge($requiredFields, $itemRequired);

                if ($item->properties !== null) {
                    foreach ($item->properties as $propName => $propSchema) {
                        if ($propSchema instanceof Reference) {
                            continue;
                        }
                        $properties[$propName] = $this->parseProperty(
                            $propName,
                            $propSchema,
                            in_array($propName, $requiredFields, true),
                        );
                    }
                }
            }
        }

        return [
            'properties' => $properties,
            'parentClass' => $parentClass,
            'refs' => $refs,
            'required' => $requiredFields,
        ];
    }

    /**
     * Parse a single schema property.
     */
    private function parseProperty(string $name, Schema $schema, bool $required): ParsedProperty
    {
        $nullable = $schema->nullable ?? false;
        $format = $schema->format;
        $description = $schema->description;
        $default = $schema->default;
        $ref = null;
        $arrayItemType = null;
        $mapValueType = null;
        $enumRef = null;
        $isFile = false;

        // Check if this property is a resolved $ref to a known component enum
        $componentEnumName = $this->getComponentEnumName($schema);
        if ($componentEnumName !== null) {
            return new ParsedProperty(
                name: $name,
                phpType: $componentEnumName,
                required: $required,
                nullable: $nullable,
                description: $description,
                enumRef: $componentEnumName,
            );
        }

        // Check if this property is a resolved $ref to a known component schema
        $componentSchemaName = $this->getComponentSchemaName($schema);
        if ($componentSchemaName !== null) {
            return new ParsedProperty(
                name: $name,
                phpType: $componentSchemaName,
                required: $required,
                nullable: $nullable,
                description: $description,
                ref: $componentSchemaName,
            );
        }

        // Detect file upload (binary format)
        if ($schema->type === 'string' && $format === 'binary') {
            return new ParsedProperty(
                name: $name,
                phpType: '\\Psr\\Http\\Message\\UploadedFileInterface',
                required: $required,
                nullable: $nullable,
                format: $format,
                description: $description,
                isFile: true,
            );
        }

        // Inline enum
        if (!empty($schema->enum)) {
            $enumClassName = $this->resolveInlineEnumName($name, $schema);
            $enumRef = $enumClassName;

            return new ParsedProperty(
                name: $name,
                phpType: $enumClassName,
                required: $required,
                nullable: $nullable,
                description: $description,
                enumRef: $enumRef,
            );
        }

        $phpType = $this->resolvePhpType($schema);

        // Array type
        if ($schema->type === 'array' && $schema->items !== null) {
            $items = $schema->items;
            if ($items instanceof Schema) {
                // Check if array items reference a known component
                $itemEnumName = $this->getComponentEnumName($items);
                if ($itemEnumName !== null) {
                    $arrayItemType = $itemEnumName;
                } else {
                    $itemSchemaName = $this->getComponentSchemaName($items);
                    if ($itemSchemaName !== null) {
                        $arrayItemType = $itemSchemaName;
                    } elseif (!empty($items->enum)) {
                        $arrayItemType = $this->resolveInlineEnumName($name . 'Item', $items);
                    } elseif ($items->type === 'object' || !empty($items->properties)) {
                        // Inline object in array — generate schema
                        $inlineName = ucfirst($name) . 'Item';
                        $this->parseObjectSchema($inlineName, $items);
                        $arrayItemType = $inlineName;
                    } elseif ($items->type === 'string' && $items->format === 'binary') {
                        // Array of file uploads
                        $arrayItemType = '\\Psr\\Http\\Message\\UploadedFileInterface';
                        $isFile = true;
                    } else {
                        $arrayItemType = $this->resolveScalarPhpType($items);
                    }
                }
            } elseif ($items instanceof Reference) {
                $arrayItemType = $this->extractRefName($items->getJsonReference()->getJsonPointer()->getPointer());
            }
        }

        // Map type (object with additionalProperties)
        if ($schema->type === 'object' && $schema->additionalProperties !== null && $schema->properties === null) {
            $addProps = $schema->additionalProperties;
            if ($addProps instanceof Schema) {
                $addPropsSchemaName = $this->getComponentSchemaName($addProps);
                if ($addPropsSchemaName !== null) {
                    $mapValueType = $addPropsSchemaName;
                } elseif ($addProps->type === 'object' || !empty($addProps->properties)) {
                    $inlineName = ucfirst($name) . 'Value';
                    $this->parseObjectSchema($inlineName, $addProps);
                    $mapValueType = $inlineName;
                } else {
                    $mapValueType = $this->resolveScalarPhpType($addProps);
                }
            } elseif ($addProps instanceof Reference) {
                $mapValueType = $this->extractRefName($addProps->getJsonReference()->getJsonPointer()->getPointer());
            }
            $phpType = 'array';
        }

        // Reference to another schema (inline object, not a $ref)
        if ($schema->type === 'object' && !empty($schema->properties) && $ref === null
            && $schema->additionalProperties === null) {
            $inlineName = ucfirst($name);
            $this->parseObjectSchema($inlineName, $schema);
            $ref = $inlineName;
            $phpType = $inlineName;
        }

        // oneOf / anyOf — use union type
        if ($schema->oneOf !== null || $schema->anyOf !== null) {
            $unionSchemas = $schema->oneOf ?? $schema->anyOf;
            $phpType = $this->resolveUnionType($unionSchemas);
        }

        return new ParsedProperty(
            name: $name,
            phpType: $phpType,
            required: $required,
            nullable: $nullable,
            format: $format,
            description: $description,
            ref: $ref,
            arrayItemType: $arrayItemType,
            mapValueType: $mapValueType,
            enumRef: $enumRef,
            default: $default,
            isFile: $isFile,
        );
    }

    /**
     * Resolve inline enum name — use x-enum extension or warn.
     */
    private function resolveInlineEnumName(string $fallbackName, Schema $schema): string
    {
        $extensions = $this->getExtensions($schema);

        if (isset($extensions['x-enum'])) {
            $enumName = $extensions['x-enum'];
        } else {
            $enumName = ucfirst($fallbackName);
            $this->result->addWarning("Inline enum '$fallbackName' has no x-enum name override, using '$enumName'");
        }

        // Register the enum if not already registered
        if (!isset($this->enums[$enumName])) {
            $backingType = match ($schema->type) {
                'integer' => 'int',
                default => 'string',
            };

            $descriptions = [];
            if (isset($extensions['x-enum-descriptions']) && is_array($extensions['x-enum-descriptions'])) {
                $descriptions = $extensions['x-enum-descriptions'];
            }

            $this->enums[$enumName] = new ParsedEnum(
                name: $enumName,
                backingType: $backingType,
                values: $schema->enum,
                descriptions: $descriptions,
                description: $schema->description,
            );
        }

        return $enumName;
    }

    /**
     * Parse all paths and operations.
     */
    private function parsePaths(): void
    {
        if ($this->openApi->paths === null) {
            return;
        }

        foreach ($this->openApi->paths as $path => $pathItem) {
            if ($pathItem instanceof Reference) {
                continue;
            }

            $this->parsePathItem($path, $pathItem);
        }
    }

    /**
     * Parse a single path item and its operations.
     */
    private function parsePathItem(string $path, PathItem $pathItem): void
    {
        $pathExtensions = $this->getExtensions($pathItem);
        $pathController = $pathExtensions['x-controller'] ?? null;
        $pathNamespace = $pathExtensions['x-ns'] ?? null;

        // Shared parameters at path level
        $sharedParams = [];
        if ($pathItem->parameters !== null) {
            foreach ($pathItem->parameters as $param) {
                if ($param instanceof Reference) {
                    continue;
                }
                $sharedParams[] = $this->parseParameterObject($param);
            }
        }

        $methods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];

        foreach ($methods as $method) {
            $operation = $pathItem->$method;
            if ($operation === null) {
                continue;
            }

            $this->parseOperation($path, strtoupper($method), $operation, $sharedParams, $pathController, $pathNamespace);
        }
    }

    /**
     * Parse a single operation.
     */
    private function parseOperation(
        string $path,
        string $httpMethod,
        Operation $operation,
        array $sharedParams,
        ?string $pathController,
        ?string $pathNamespace,
    ): void {
        $operationId = $operation->operationId;

        if ($operationId === null) {
            $this->result->addError("Operation $httpMethod $path has no operationId — skipping");
            return;
        }

        // Duplicate detection
        if (isset($this->operationIds[$operationId])) {
            $this->result->addWarning("Duplicate operationId '$operationId' at $httpMethod $path");
        }
        $this->operationIds[$operationId] = true;

        // Resolve controller name: operation x-controller > path x-controller > first tag > Default
        $extensions = $this->getExtensions($operation);
        $controllerName = $extensions['x-controller']
            ?? $pathController
            ?? $this->resolveControllerFromTag($operation, $path);

        $controllerNamespace = $extensions['x-ns'] ?? $pathNamespace;

        // Action name from operationId
        $actionName = 'action' . ucfirst($operationId);

        // Parse parameters (operation-level override shared)
        $parameters = $this->mergeParameters($sharedParams, $operation->parameters ?? []);

        // Parse request body
        $requestBodyClass = null;
        $requestBodyMediaType = null;
        $requestBodyRequired = false;
        $requestBodyIsArray = false;

        if ($operation->requestBody !== null && !($operation->requestBody instanceof Reference)) {
            $requestBody = $operation->requestBody;
            $requestBodyRequired = $requestBody->required ?? false;

            foreach ($requestBody->content as $mediaType => $mediaTypeObj) {
                if ($mediaTypeObj instanceof MediaType && $mediaTypeObj->schema !== null) {
                    $requestBodyMediaType = $mediaType;
                    $schema = $mediaTypeObj->schema;

                    // Handle array request body: resolve the item class instead of returning 'array'
                    if ($schema instanceof Schema && $schema->type === 'array' && $schema->items !== null) {
                        $requestBodyIsArray = true;
                        $items = $schema->items;
                        if ($items instanceof Schema) {
                            $itemComponentName = $this->getComponentSchemaName($items);
                            if ($itemComponentName !== null) {
                                $requestBodyClass = $itemComponentName;
                            } else {
                                $inlineName = ucfirst($operationId) . 'RequestItem';
                                $this->resolveSchemaReference($items, $inlineName);
                                $requestBodyClass = $inlineName;
                            }
                        } elseif ($items instanceof Reference) {
                            $requestBodyClass = $this->extractRefName($items->getJsonReference()->getJsonPointer()->getPointer());
                        }
                    } else {
                        $requestBodyClass = $this->resolveSchemaReference($mediaTypeObj->schema, ucfirst($operationId) . 'Request');
                    }
                    break;
                }
            }
        }

        // Parse responses
        $responses = [];
        $successResponseClass = null;

        if ($operation->responses !== null) {
            foreach ($operation->responses as $statusCode => $response) {
                if ($response instanceof Reference) {
                    continue;
                }

                if ($response->content !== null) {
                    foreach ($response->content as $mediaType => $mediaTypeObj) {
                        if ($mediaTypeObj instanceof MediaType && $mediaTypeObj->schema !== null) {
                            $responseClass = $this->resolveSchemaReference(
                                $mediaTypeObj->schema,
                                ucfirst($operationId) . 'Response' . $statusCode,
                            );
                            $responses[(int)$statusCode] = $responseClass;

                            if ($successResponseClass === null && $statusCode >= 200 && $statusCode < 300) {
                                $successResponseClass = $responseClass;
                            }
                            break;
                        }
                    }
                }
            }
        }

        // Security
        $security = [];
        if ($operation->security !== null) {
            $secData = $operation->security->getSerializableData();
            foreach ($secData as $requirementObj) {
                foreach ($requirementObj as $schemeName => $scopes) {
                    $security[] = $schemeName;
                }
            }
        }

        $this->operations[] = new ParsedOperation(
            operationId: $operationId,
            httpMethod: $httpMethod,
            path: $path,
            controllerName: $controllerName,
            controllerNamespace: $controllerNamespace,
            actionName: $actionName,
            requestBodyClass: $requestBodyClass,
            requestBodyMediaType: $requestBodyMediaType,
            requestBodyRequired: $requestBodyRequired,
            requestBodyIsArray: $requestBodyIsArray,
            parameters: $parameters,
            responses: $responses,
            successResponseClass: $successResponseClass,
            description: $operation->description ?? $operation->summary,
            tags: $operation->tags ?? [],
            security: $security,
        );
    }

    /**
     * Resolve a schema to a class name — either a known component reference or an inline schema.
     */
    private function resolveSchemaReference(Schema|Reference $schema, string $inlineFallbackName): string
    {
        if ($schema instanceof Reference) {
            return $this->extractRefName($schema->getJsonReference()->getJsonPointer()->getPointer());
        }

        // Check if this is a resolved $ref to a known component
        $componentName = $this->getComponentSchemaName($schema);
        if ($componentName !== null) {
            return $componentName;
        }

        $componentEnumName = $this->getComponentEnumName($schema);
        if ($componentEnumName !== null) {
            return $componentEnumName;
        }

        // Inline array response (e.g., type: array, items: $ref)
        // Cannot represent as a single DTO class — return 'array' type
        if ($schema->type === 'array') {
            return 'array';
        }

        // Truly inline schema — generate DTO
        if ($schema->type === 'object' || $schema->properties !== null || $schema->allOf !== null) {
            $this->parseObjectSchema($inlineFallbackName, $schema);
            return $inlineFallbackName;
        }

        // Scalar response type — return as-is (rare)
        return $this->resolvePhpType($schema);
    }

    /**
     * Resolve controller name from first tag or path fallback.
     */
    private function resolveControllerFromTag(Operation $operation, string $path): string
    {
        if (!empty($operation->tags)) {
            return ucfirst($operation->tags[0]);
        }

        // Fallback: derive from first path segment
        $segments = array_filter(explode('/', $path));
        $first = reset($segments);

        if ($first !== false) {
            $name = ucfirst(preg_replace('/[^a-zA-Z0-9]/', '', $first));
            $this->result->addWarning("Operation at $path has no tags, using controller name '$name' from path");
            return $name;
        }

        $this->result->addWarning("Cannot determine controller name for $path, using 'Default'");
        return 'Default';
    }

    /**
     * Merge path-level and operation-level parameters (operation overrides path by name+in).
     *
     * @return ParsedParameter[]
     */
    private function mergeParameters(array $sharedParams, array $operationParams): array
    {
        $merged = [];

        foreach ($sharedParams as $param) {
            $key = $param->in . ':' . $param->name;
            $merged[$key] = $param;
        }

        foreach ($operationParams as $param) {
            if ($param instanceof Reference) {
                continue;
            }
            $parsed = $this->parseParameterObject($param);
            $key = $parsed->in . ':' . $parsed->name;
            $merged[$key] = $parsed;
        }

        return array_values($merged);
    }

    /**
     * Parse an OpenAPI Parameter object.
     */
    private function parseParameterObject(object $param): ParsedParameter
    {
        $schema = $param->schema;
        $phpType = 'string';
        $format = null;
        $nullable = false;
        $enumRef = null;

        if ($schema instanceof Schema) {
            $phpType = $this->resolveScalarPhpType($schema);
            $format = $schema->format;
            $nullable = $schema->nullable ?? false;

            // Check if this is a resolved $ref to a known enum
            $componentEnumName = $this->getComponentEnumName($schema);
            if ($componentEnumName !== null) {
                $enumRef = $componentEnumName;
                $phpType = $componentEnumName;
            } elseif (!empty($schema->enum)) {
                $enumRef = $this->resolveInlineEnumName($param->name, $schema);
                $phpType = $enumRef;
            }
        }

        return new ParsedParameter(
            name: $param->name,
            in: $param->in,
            required: $param->required ?? false,
            type: $phpType,
            nullable: $nullable,
            format: $format,
            default: $param->schema->default ?? null,
            description: $param->description ?? null,
            enumRef: $enumRef,
        );
    }

    /**
     * Resolve a PHP type from a Schema.
     */
    private function resolvePhpType(Schema $schema): string
    {
        if ($schema->oneOf !== null || $schema->anyOf !== null) {
            return $this->resolveUnionType($schema->oneOf ?? $schema->anyOf);
        }

        return match ($schema->type) {
            'integer' => 'int',
            'number' => match ($schema->format) {
                'float' => 'float',
                'double' => 'float',
                default => 'float',
            },
            'boolean' => 'bool',
            'string' => match ($schema->format) {
                'date', 'date-time' => '\\DateTimeInterface',
                'binary' => '\\Psr\\Http\\Message\\UploadedFileInterface',
                default => 'string',
            },
            'array' => 'array',
            'object' => 'object',
            default => 'mixed',
        };
    }

    /**
     * Resolve scalar PHP type (for parameters and simple types).
     */
    private function resolveScalarPhpType(Schema $schema): string
    {
        return match ($schema->type) {
            'integer' => 'int',
            'number' => 'float',
            'boolean' => 'bool',
            default => 'string',
        };
    }

    /**
     * Resolve union type from oneOf/anyOf schemas.
     */
    private function resolveUnionType(array $schemas): string
    {
        $types = [];
        foreach ($schemas as $item) {
            if ($item instanceof Reference) {
                $types[] = $this->extractRefName($item->getJsonReference()->getJsonPointer()->getPointer());
            } elseif ($item instanceof Schema) {
                $componentName = $this->getComponentSchemaName($item) ?? $this->getComponentEnumName($item);
                if ($componentName !== null) {
                    $types[] = $componentName;
                } else {
                    $types[] = $this->resolvePhpType($item);
                }
            }
        }

        return count($types) > 0 ? implode('|', array_unique($types)) : 'mixed';
    }

    /**
     * Extract schema name from a JSON pointer (e.g., /components/schemas/Pet => Pet).
     */
    private function extractRefName(string $pointer): string
    {
        $parts = explode('/', $pointer);
        return end($parts);
    }

    /**
     * Get vendor extensions from any spec object.
     *
     * @return array<string, mixed>
     */
    private function getExtensions(object $specObject): array
    {
        $extensions = [];

        if (method_exists($specObject, 'getExtensions')) {
            return $specObject->getExtensions();
        }

        // Fallback: check for public properties starting with x-
        $data = $specObject->getSerializableData();
        if (is_object($data)) {
            $data = (array)$data;
        }

        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'x-')) {
                $extensions[$key] = $value;
            }
        }

        return $extensions;
    }
}







