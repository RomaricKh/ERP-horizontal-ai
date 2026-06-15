<?php

namespace App\Services;

use PDO;

/**
 * SetupGuard
 *
 * Detects whether the system is in a fresh-install state by checking that no
 * rows exist in the `tenants` table. The result is cached on the instance for
 * the duration of the current HTTP request to avoid repeated database queries
 * (per Requirement 1.4).
 */
class SetupGuard
{
    private PDO $db;

    /**
     * Cached fresh-install result for the current request.
     *
     * Null means "not yet computed". Once computed, subsequent calls within
     * the same request will return the cached value without re-querying.
     */
    private ?bool $cachedIsFreshInstall = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Returns true if zero tenants exist in the database (fresh install).
     *
     * Executes a single `SELECT COUNT(*) FROM tenants` query (no joins, no
     * subqueries) and caches the result on this instance for the remainder of
     * the request.
     */
    public function isFreshInstall(): bool
    {
        if ($this->cachedIsFreshInstall !== null) {
            return $this->cachedIsFreshInstall;
        }

        $stmt = $this->db->query('SELECT COUNT(*) FROM tenants');
        $count = (int) $stmt->fetchColumn();

        $this->cachedIsFreshInstall = ($count === 0);

        return $this->cachedIsFreshInstall;
    }
}
