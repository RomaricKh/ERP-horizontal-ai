<?php

use App\Controllers\BusinessesController;
use App\Controllers\BusinessTypesController;
use App\Support\AuthContext;

/* =====================================================================
 * BUSINESS ROUTES
 *
 * All business CRUD endpoints now require Tenant Owner privileges.
 * Non-Tenant-Owners calling these endpoints receive HTTP 403.
 * Business management has been relocated to /tenant/businesses (see tenant.php).
 * These legacy routes are kept for backward compatibility but enforce TENANT_OWNER.
 *
 * Requirements: 4.1, 12.2
 * ===================================================================== */

$businessTenantGuard = function (): bool {
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

/* ========= BUSINESSES ========= */

$router->get('/businesses',
    function () use ($businessTenantGuard) {
        if (!$businessTenantGuard()) return;
        (new BusinessesController())->index();
    },
    ['businesses.view']
);

$router->get('/businesses/{id}',
    function ($id) use ($businessTenantGuard) {
        if (!$businessTenantGuard()) return;
        (new BusinessesController())->viewById((int)$id);
    },
    ['businesses.view']
);

$router->post('/businesses',
    function () use ($businessTenantGuard) {
        if (!$businessTenantGuard()) return;
        (new BusinessesController())->store();
    },
    ['businesses.create']
);

$router->put('/businesses/{id}',
    function ($id) use ($businessTenantGuard) {
        if (!$businessTenantGuard()) return;
        (new BusinessesController())->update((int)$id);
    },
    ['businesses.edit']
);

$router->delete('/businesses/{id}',
    function ($id) use ($businessTenantGuard) {
        if (!$businessTenantGuard()) return;
        (new BusinessesController())->delete((int)$id);
    },
    ['businesses.delete']
);

$router->patch('/businesses/{id}/active',
    function ($id) use ($businessTenantGuard) {
        if (!$businessTenantGuard()) return;
        (new BusinessesController())->toggleActive((int)$id);
    },
    ['businesses.manage']
);

/* ========= BUSINESS TYPES ========= */

// GET /business-types
$router->get('/business-types',
    function () use ($businessTenantGuard) {
        if (!$businessTenantGuard()) return;
        (new BusinessTypesController())->index();
    },
    ['businesses.view']
);

/* ========= TENANT BUSINESS TYPES (REQ-011) ========= */

// GET /tenant/business-types — list tenant-owned types
$router->get('/tenant/business-types',
    function () use ($businessTenantGuard) {
        if (!$businessTenantGuard()) return;
        (new BusinessTypesController())->listTenantTypes();
    },
    ['tenant.manage']
);

// GET /tenant/business-types/system — list system types (templates)
$router->get('/tenant/business-types/system',
    function () use ($businessTenantGuard) {
        if (!$businessTenantGuard()) return;
        (new BusinessTypesController())->listSystemTypes();
    },
    ['tenant.manage']
);

// POST /tenant/business-types — create by copying a system type
$router->post('/tenant/business-types',
    function () use ($businessTenantGuard) {
        if (!$businessTenantGuard()) return;
        (new BusinessTypesController())->createFromSystem();
    },
    ['tenant.manage']
);

// PUT /tenant/business-types/{id}/modules — replace module set
$router->put('/tenant/business-types/{id}/modules',
    function ($id) use ($businessTenantGuard) {
        if (!$businessTenantGuard()) return;
        (new BusinessTypesController())->updateModules((int)$id);
    },
    ['tenant.manage']
);

// DELETE /tenant/business-types/{id} — delete tenant type
$router->delete('/tenant/business-types/{id}',
    function ($id) use ($businessTenantGuard) {
        if (!$businessTenantGuard()) return;
        (new BusinessTypesController())->deleteTenantType((int)$id);
    },
    ['tenant.manage']
);
