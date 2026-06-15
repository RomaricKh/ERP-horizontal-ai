<?php

use App\Controllers\TenantController;
use App\Support\AuthContext;

/* =====================================================================
 * TENANT OWNER ROUTES
 *
 * All routes under /tenant require Tenant Owner verification.
 * The guard closure checks AuthContext::isTenantOwner() and returns
 * HTTP 403 if the user is not a verified Tenant Owner.
 * ===================================================================== */

$tenantGuard = function (): bool {
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

/* ========= TENANT CRUD ========= */

// GET /tenant/tenants
$router->get('/tenant/tenants',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->listTenants();
    }
);

// POST /tenant/tenants
$router->post('/tenant/tenants',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->createTenant();
    }
);

/* ========= BUSINESS MANAGEMENT ========= */

// GET /tenant/businesses
$router->get('/tenant/businesses',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->listBusinesses();
    }
);

// GET /tenant/businesses/{id}
$router->get('/tenant/businesses/{id}',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->viewBusiness((int) $id);
    }
);

// POST /tenant/businesses
$router->post('/tenant/businesses',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->createBusiness();
    }
);

// PUT /tenant/businesses/{id}
$router->put('/tenant/businesses/{id}',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->updateBusiness((int) $id);
    }
);

// PUT /tenant/businesses/{id}/type
$router->put('/tenant/businesses/{id}/type',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->changeBusinessType((int) $id);
    }
);

// DELETE /tenant/businesses/{id}
$router->delete('/tenant/businesses/{id}',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->deleteBusiness((int) $id);
    }
);

/* ========= BUSINESS TYPE MANAGEMENT ========= */

// GET /tenant/modules
$router->get('/tenant/modules',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->listModules();
    }
);

// GET /tenant/business-types — handled by businesses.php (BusinessTypesController)
// POST /tenant/business-types — handled by businesses.php (BusinessTypesController)
// PUT /tenant/business-types/{id} — handled by businesses.php (BusinessTypesController)
// DELETE /tenant/business-types/{id} — handled by businesses.php (BusinessTypesController)

/* ========= USER MANAGEMENT ========= */

// GET /tenant/users
$router->get('/tenant/users',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->listUsers();
    }
);

// GET /tenant/users/{id}
$router->get('/tenant/users/{id}',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->viewUser((int) $id);
    }
);

// POST /tenant/users
$router->post('/tenant/users',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->createUser();
    }
);

// POST /tenant/users/reassign
$router->post('/tenant/users/reassign',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->reassignUser();
    }
);

// PUT /tenant/users/{id}/deactivate
$router->put('/tenant/users/{id}/deactivate',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->deactivateUser((int) $id);
    }
);

/* ========= ROLE MANAGEMENT ========= */

// GET /tenant/businesses/{id}/roles — fetch roles for a specific business
$router->get('/tenant/businesses/{id}/roles',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->listRolesForBusiness((int) $id);
    }
);

// POST /tenant/roles
$router->post('/tenant/roles',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->createRole();
    }
);

// POST /tenant/roles/assign
$router->post('/tenant/roles/assign',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->assignRole();
    }
);

// POST /tenant/roles/template
$router->post('/tenant/roles/template',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->createTemplateRole();
    }
);

/* ========= TENANT OWNERS ========= */

// GET /tenant/owners
$router->get('/tenant/owners',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->listOwners();
    }
);

// POST /tenant/owners
$router->post('/tenant/owners',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->designateOwner();
    }
);

// DELETE /tenant/owners/{id}
$router->delete('/tenant/owners/{id}',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->revokeOwner((int) $id);
    }
);

/* ========= DASHBOARD & REPORTS ========= */

// GET /tenant/dashboard
$router->get('/tenant/dashboard',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->dashboard();
    }
);

// POST /tenant/reports
$router->post('/tenant/reports',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantController())->generateReport();
    }
);
