<?php
require_once __DIR__ . '/../../bootstrap.php';

use App\Support\Router;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new Router($config['jwt_secret']);

require __DIR__ . '/routes/auth.php';
require __DIR__ . '/routes/users.php';
require __DIR__ . '/routes/businesses.php';
require __DIR__ . '/routes/roles.php';
require __DIR__ . '/routes/people.php';
require __DIR__ . '/routes/batches.php';
require __DIR__ . '/routes/items.php';
require __DIR__ . '/routes/services.php';
require __DIR__ . '/routes/exchange_rates.php';
require __DIR__ . '/routes/accounts.php';
require __DIR__ . '/routes/expenses.php';
require __DIR__ . '/routes/purchase_orders.php';
require __DIR__ . '/routes/sales_invoices.php';
require __DIR__ . '/routes/payments.php';
require __DIR__ . '/routes/collections.php';
require __DIR__ . '/routes/salaries.php';
require __DIR__ . '/routes/assets.php';
require __DIR__ . '/routes/partner_shares.php';
require __DIR__ . '/routes/partner_equity.php';
require __DIR__ . '/routes/business_profit.php';
require __DIR__ . '/routes/dashboard.php';
require __DIR__ . '/routes/activity_log.php';
require __DIR__ . '/routes/settings.php';
require __DIR__ . '/routes/manufacturing.php';
require __DIR__ . '/routes/admin.php';
require __DIR__ . '/routes/translations.php';
require __DIR__ . '/routes/notifications.php';
require __DIR__ . '/routes/recurrences.php';
require __DIR__ . '/routes/tenant.php';
require __DIR__ . '/routes/reports.php';

$router->dispatch();
