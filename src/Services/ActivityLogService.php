<?php
namespace App\Services;

use App\Repository\AuditRepository;
use App\Support\AuthContext;
use PDO;

class ActivityLogService {
    private AuditRepository $repo;

    public function __construct(PDO $db) {
        $this->repo = new AuditRepository($db);
    }

    /**
     * Return a paginated list of audit log entries for the current business.
     *
     * Accepted filter keys:
     *   - event_type  (string, optional)
     *   - from_date   (string, optional)
     *   - to_date     (string, optional)
     *   - page        (int, default 1)
     *   - per_page    (int, default 25)
     *
     * @param  array $filters
     * @return array{entries: array, total: int}
     */
    public function getLog(array $filters = []): array {
        $ctx = AuthContext::get();

        $page    = isset($filters['page'])     ? max(1, (int) $filters['page'])     : 1;
        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 25;

        $limit  = $perPage;
        $offset = ($page - 1) * $perPage;

        $repoFilters = [];

        if (!empty($filters['event_type'])) {
            $repoFilters['event_type'] = $filters['event_type'];
        }
        if (!empty($filters['from_date'])) {
            $repoFilters['from_date'] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $repoFilters['to_date'] = $filters['to_date'];
        }

        return $this->repo->findForBusiness($ctx->business_id, $repoFilters, $limit, $offset);
    }
}
