<?php
require 'bootstrap.php';

$_GET['q'] = 'Mr';

// Mock Auth
$ctx = new class {
    public $tenant_id = 1;
    public $business_id = 1;
    public $user_id = 1;
};

// Mock the controller method to not use $_SESSION or exit
class TestController extends \App\Controllers\PeopleController {
    protected function auth(): object {
        global $ctx;
        return $ctx;
    }
    
    protected function json(bool $success, ?array $data = null, ?string $error = null, int $status = 200): void {
        echo json_encode([
            'success' => $success,
            'data'    => $data,
            'error'   => $error
        ]);
    }
    
    protected function serverError(string $message): void {
        echo json_encode(['success' => false, 'error' => $message]);
    }
}

(new TestController())->searchTenant();
