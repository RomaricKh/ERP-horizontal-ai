<?php

namespace App\Controllers;

use App\Support\AuthContext;

abstract class BaseController
{
    protected function json(
        bool $success,
        ?array $data = null,
        ?string $message = null,
        int $status = 200
    ): void {
        http_response_code($status);

        echo json_encode([
            'success' => $success,
            'data'    => $data,
            'message' => $message
        ]);
    }

    protected function input(array $required = []): array
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            $this->json(false, null, 'Invalid JSON body', 400);
            exit;
        }

        foreach ($required as $field) {
            if (!array_key_exists($field, $input)) {
                $this->json(false, null, "Missing field: $field", 400);
                exit;
            }
        }

        return $input;
    }

    protected function auth(): object
    {
        return AuthContext::get();
    }

    protected function forbidden(string $message = 'Forbidden'): void
    {
        $this->json(false, null, $message, 403);
        exit;
    }

    protected function notFound(string $message = 'Not found'): void
    {
        $this->json(false, null, $message, 404);
        exit;
    }

    protected function serverError(string $message = 'Internal server error'): void
    {
        $this->json(false, null, $message, 500);
        exit;
    }

    protected function logError(string $module, string $method, \Throwable $e): void
    {
        try {
            $userId = AuthContext::userId();
        } catch (\Throwable $_) {
            $userId = 'unknown';
        }

        try {
            $businessId = AuthContext::businessId();
        } catch (\Throwable $_) {
            $businessId = 'unknown';
        }

        error_log(sprintf(
            '[%s] %s: %s - %s (user=%s, business=%s)',
            $module,
            $method,
            get_class($e),
            $e->getMessage(),
            $userId,
            $businessId
        ));

        error_log($e->getTraceAsString());
    }
}
