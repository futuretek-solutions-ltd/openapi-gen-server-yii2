<?php

declare(strict_types=1);

namespace futuretek\openapi\Middleware;

/**
 * Default pass-through authorization.
 *
 * Always authorizes. Override for real RBAC/scope logic.
 */
class DefaultAuthorization implements AuthorizationInterface
{
    public function authorize(string $operationId, mixed $identity): bool
    {
        return true;
    }
}

