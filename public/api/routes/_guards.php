<?php

use App\Support\AuthContext;

/**
 * Shared Tenant Owner guard closure.
 * Checks AuthContext::isTenantOwner() and returns HTTP 403 JSON on failure.
 * Imported by tenant.php and businesses.php.
 */
return function (): bool {
    if (!AuthContext::isTenantOwner()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'data'    => null,
            'message' => 'Access denied: Tenant Owner privileges required'
        ]);
        return false;
    }
    return true;
};
