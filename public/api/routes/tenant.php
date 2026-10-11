<?php

use App\Controllers\Tenant\TenantCoreController;
use App\Controllers\Tenant\TenantBusinessController;
use App\Controllers\Tenant\TenantUserController;
use App\Controllers\Tenant\TenantRoleController;
use App\Controllers\Tenant\TenantOwnerController;

/* =====================================================================
 * TENANT OWNER ROUTES
 *
 * All routes under /tenant require Tenant Owner verification.
 * The guard closure checks AuthContext::isTenantOwner() and returns
 * HTTP 403 if the user is not a verified Tenant Owner.
 * ===================================================================== */

$tenantGuard = require __DIR__ . '/_guards.php';

/* ========= TENANT CRUD ========= */

// GET /tenant/tenants
$router->get('/tenant/tenants',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantCoreController())->listTenants();
    }
);

// POST /tenant/tenants
$router->post('/tenant/tenants',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantCoreController())->createTenant();
    }
);

// GET /tenant/tenants/{id}
$router->get('/tenant/tenants/{id}',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantCoreController())->viewTenant((int) $id);
    }
);

// PUT /tenant/tenants/{id}
$router->put('/tenant/tenants/{id}',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantCoreController())->updateTenant((int) $id);
    }
);

// PATCH /tenant/tenants/{id}/active
$router->patch('/tenant/tenants/{id}/active',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantCoreController())->toggleTenantActive((int) $id);
    }
);

/* ========= BUSINESS MANAGEMENT ========= */

// GET /tenant/businesses
$router->get('/tenant/businesses',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantBusinessController())->listBusinesses();
    }
);

// GET /tenant/businesses/{id}
$router->get('/tenant/businesses/{id}',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantBusinessController())->viewBusiness((int) $id);
    }
);

// POST /tenant/businesses
$router->post('/tenant/businesses',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantBusinessController())->createBusiness();
    }
);

// PUT /tenant/businesses/{id}
$router->put('/tenant/businesses/{id}',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantBusinessController())->updateBusiness((int) $id);
    }
);

// PUT /tenant/businesses/{id}/type
$router->put('/tenant/businesses/{id}/type',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantBusinessController())->changeBusinessType((int) $id);
    }
);

// DELETE /tenant/businesses/{id}
$router->delete('/tenant/businesses/{id}',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantBusinessController())->deleteBusiness((int) $id);
    }
);

/* ========= BUSINESS TYPE MANAGEMENT ========= */

// GET /tenant/modules
$router->get('/tenant/modules',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantCoreController())->listModules();
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
        (new TenantUserController())->listUsers();
    }
);

// GET /tenant/users/{id}
$router->get('/tenant/users/{id}',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantUserController())->viewUser((int) $id);
    }
);

// POST /tenant/users
$router->post('/tenant/users',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantUserController())->createUser();
    }
);

// POST /tenant/users/reassign
$router->post('/tenant/users/reassign',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantUserController())->reassignUser();
    }
);

// PUT /tenant/users/{id}/deactivate
$router->put('/tenant/users/{id}/deactivate',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantUserController())->deactivateUser((int) $id);
    }
);

// PUT /tenant/users/{id}/activate
$router->put('/tenant/users/{id}/activate',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantUserController())->reactivateUser((int) $id);
    }
);

/* ========= ROLE MANAGEMENT ========= */

// GET /tenant/businesses/{id}/roles — fetch roles for a specific business
$router->get('/tenant/businesses/{id}/roles',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantRoleController())->listRolesForBusiness((int) $id);
    }
);

// POST /tenant/roles
$router->post('/tenant/roles',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantRoleController())->createRole();
    }
);

// POST /tenant/roles/assign
$router->post('/tenant/roles/assign',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantRoleController())->assignRole();
    }
);

// POST /tenant/roles/template
$router->post('/tenant/roles/template',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantRoleController())->createTemplateRole();
    }
);

/* ========= TENANT OWNERS ========= */

// GET /tenant/owners
$router->get('/tenant/owners',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantOwnerController())->listOwners();
    }
);

// POST /tenant/owners
$router->post('/tenant/owners',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantOwnerController())->designateOwner();
    }
);

// DELETE /tenant/owners/{id}
$router->delete('/tenant/owners/{id}',
    function ($id) use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantOwnerController())->revokeOwner((int) $id);
    }
);

/* ========= DASHBOARD & REPORTS ========= */

// GET /tenant/dashboard
$router->get('/tenant/dashboard',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantCoreController())->dashboard();
    }
);

// POST /tenant/reports
$router->post('/tenant/reports',
    function () use ($tenantGuard) {
        if (!$tenantGuard()) return;
        (new TenantCoreController())->generateReport();
    }
);
