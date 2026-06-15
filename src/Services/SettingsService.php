<?php
namespace App\Services;

use App\Repository\SettingsRepository;
use App\Support\AuthContext;
use PDO;

class SettingsService {
    private SettingsRepository $repo;
    private AuditService       $audit;
    private PDO                $db;

    public function __construct(PDO $db) {
        $this->db    = $db;
        $this->repo  = new SettingsRepository($db);
        $this->audit = new AuditService($db);
    }

    /**
     * Return the regional settings for the current business.
     * Falls back to a default array if no row exists yet.
     */
    public function get(): array {
        $ctx  = AuthContext::get();
        $row  = $this->repo->findByBusiness($ctx->business_id);

        if ($row !== null) {
            return $row;
        }

        return [
            'business_id'     => $ctx->business_id,
            'language'        => 'en',
            'timezone'        => 'UTC',
            'date_format'     => 'Y-m-d',
            'number_format'   => '1,234.56',
            'currency_format' => 'USD',
            'phone_format'    => '+1 (###) ###-####',
            'direction'       => 'LTR',
        ];
    }

    /**
     * Validate and persist the regional settings for the current business.
     *
     * Required keys: language, timezone, date_format,
     *                number_format, currency_format, phone_format
     *
     * @throws \InvalidArgumentException if any required field is missing or empty
     */
    public function save(array $data): void {
        $ctx = AuthContext::get();

        $required = ['language', 'timezone', 'date_format', 'number_format', 'currency_format', 'phone_format'];
        foreach ($required as $field) {
            if (empty(trim($data[$field] ?? ''))) {
                throw new \InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . ' is required');
            }
        }

        $before = $this->repo->findByBusiness($ctx->business_id);

        $payload = [
            'language'        => trim($data['language']),
            'timezone'        => trim($data['timezone']),
            'date_format'     => trim($data['date_format']),
            'number_format'   => trim($data['number_format']),
            'currency_format' => trim($data['currency_format']),
            'phone_format'    => trim($data['phone_format']),
        ];

        $this->repo->upsert($ctx->business_id, $payload);

        $this->audit->log(
            'SETTINGS',
            'settings_regional',
            $ctx->business_id,
            'UPSERT',
            $before,
            $payload,
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );
    }
}
