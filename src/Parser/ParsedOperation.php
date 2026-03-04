<?php

declare(strict_types=1);

namespace futuretek\openapi\Parser;

/**
 * Normalized representation of an OpenAPI operation (endpoint method).
 */
final class ParsedOperation
{
    /**
     * @param string $operationId Unique operation identifier
     * @param string $httpMethod HTTP method (GET, POST, PUT, DELETE, PATCH)
     * @param string $path OpenAPI path (e.g., /pets/{petId})
     * @param string $controllerName Resolved controller name
     * @param string|null $controllerNamespace Resolved controller namespace override (x-ns)
     * @param string $actionName Resolved action method name
     * @param string|null $requestBodyClass DTO class name for request body (null if no body)
     * @param string|null $requestBodyMediaType Media type for request body
     * @param bool $requestBodyRequired Whether request body is required
     * @param ParsedParameter[] $parameters All parameters (path, query, header, cookie)
     * @param array<int, string> $responses Map of HTTP status code => DTO class name
     * @param string|null $successResponseClass Primary success response DTO class name
     * @param string|null $description Operation description
     * @param string[] $tags Operation tags
     * @param string[] $security Security scheme names
     */
    public function __construct(
        public readonly string $operationId,
        public readonly string $httpMethod,
        public readonly string $path,
        public readonly string $controllerName,
        public readonly ?string $controllerNamespace,
        public readonly string $actionName,
        public readonly ?string $requestBodyClass = null,
        public readonly ?string $requestBodyMediaType = null,
        public readonly bool $requestBodyRequired = false,
        public readonly array $parameters = [],
        public readonly array $responses = [],
        public readonly ?string $successResponseClass = null,
        public readonly ?string $description = null,
        public readonly array $tags = [],
        public readonly array $security = [],
    ) {}
}

