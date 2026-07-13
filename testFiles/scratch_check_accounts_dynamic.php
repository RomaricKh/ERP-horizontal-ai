<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

// Mock AuthContext
class MockAuthContext {
    public $business_id = 3;
    public $tenant_id = 1;
    public $user_id = 1;
}
\App\Support\AuthContext::set(new MockAuthContext());

$service = new \App\Services\AccountService($db);
$accounts = $service->list();
print_r($accounts);
