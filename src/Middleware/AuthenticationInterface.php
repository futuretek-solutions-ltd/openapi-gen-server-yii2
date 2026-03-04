<?php

declare(strict_types=1);

namespace futuretek\openapi\Middleware;

/**
 * Authentication middleware interface.
 *
 * Called before action execution to authenticate the request.
 * Default implementation passes through (no-op).
 *
 * Users can override to implement token validation, session checking, etc.
 */
interface AuthenticationInterface
{
    /**
     * Authenticate the current request.
     *
     * @param string $operationId The OpenAPI operation ID being executed
     * @param string[] $securitySchemes Security scheme names required by the operation
     * @return mixed Authenticated identity (user object, ID, etc.) or null if no auth required
     * @throws \RuntimeException If authentication fails
     */
    public function authenticate(string $operationId, array $securitySchemes): mixed;
}

