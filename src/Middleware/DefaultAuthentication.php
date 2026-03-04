<?php

declare(strict_types=1);

namespace futuretek\openapi\Middleware;

/**
 * Default pass-through authentication.
 *
 * Does not perform any authentication. Override for real auth logic.
 */
class DefaultAuthentication implements AuthenticationInterface
{
    public function authenticate(string $operationId, array $securitySchemes): mixed
    {
        return null;
    }
}

