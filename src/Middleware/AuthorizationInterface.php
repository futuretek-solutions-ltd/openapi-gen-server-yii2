<?php

declare(strict_types=1);

namespace futuretek\openapi\Middleware;

/**
 * Authorization middleware interface.
 *
 * Called after authentication to check if the user has permission.
 * Default implementation passes through (no-op).
 *
 * Users can override to implement RBAC, scope checking, etc.
 */
interface AuthorizationInterface
{
    /**
     * Authorize the current request.
     *
     * @param string $operationId The OpenAPI operation ID being executed
     * @param mixed $identity The authenticated identity (from AuthenticationInterface)
     * @param string $controller The controller/tag name handling the operation
     * @return bool True if authorized
     * @throws \RuntimeException If authorization fails
     */
    public function authorize(string $operationId, mixed $identity, string $controller): bool;
}

