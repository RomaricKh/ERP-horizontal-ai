<?php

namespace Tests\Property;

use App\Services\TenantOwnerService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Property 3: Pagination envelope structure
 *
 * For any valid page number (>= 1) and per_page value (1-100),
 * getBusinessSummaries() SHALL return an array containing exactly the keys
 * {data, total, current_page, last_page, per_page}, where last_page equals
 * max(1, (int)ceil(total / per_page)) and current_page equals the requested page,
 * and per_page equals the requested perPage.
 *
 * **Validates: Requirements 7.1**
 */
class PaginationEnvelopePropertyTest extends TestCase
{
    private const ITERATIONS = 100;

    private PDO $db;
    private int $tenantId;

    protected function setUp(): void
    {
        $this->db = new PDO(
            'mysql:host=localhost;dbname=erp;charset=utf8mb4',
            'root',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Use existing tenant
        $stmt = $this->db->query("SELECT id FROM tenants LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->markTestSkipped('No tenant found in the database for testing.');
        }
        $this->tenantId = (int) $row['id'];
    }

    /**
     * Property 3: The envelope SHALL contain exactly the keys {data, total, current_page, last_page, per_page}.
     *
     * **Validates: Requirements 7.1**
     */
    public function testEnvelopeContainsExactKeys(): void
    {
        $service = new TenantOwnerService($this->db);
        $expectedKeys = ['data', 'total', 'current_page', 'last_page', 'per_page'];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $page = mt_rand(1, 50);
            $perPage = mt_rand(1, 100);

            $result = $service->getBusinessSummaries($this->tenantId, $page, $perPage);

            // Must contain exactly the expected keys (no more, no less)
            $actualKeys = array_keys($result);
            sort($actualKeys);
            sort($expectedKeys);

            $this->assertEquals($expectedKeys, $actualKeys, sprintf(
                'Iteration %d (page=%d, perPage=%d): Envelope must contain exactly keys {data, total, current_page, last_page, per_page}. Got: {%s}',
                $i, $page, $perPage, implode(', ', $actualKeys)
            ));
        }
    }

    /**
     * Property 3: last_page SHALL equal max(1, (int)ceil(total / per_page)).
     *
     * **Validates: Requirements 7.1**
     */
    public function testLastPageComputation(): void
    {
        $service = new TenantOwnerService($this->db);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $page = mt_rand(1, 50);
            $perPage = mt_rand(1, 100);

            $result = $service->getBusinessSummaries($this->tenantId, $page, $perPage);

            $total = $result['total'];
            $expectedLastPage = max(1, (int) ceil($total / $perPage));

            $this->assertSame($expectedLastPage, $result['last_page'], sprintf(
                'Iteration %d (page=%d, perPage=%d, total=%d): last_page should be max(1, ceil(%d / %d)) = %d, got %d',
                $i, $page, $perPage, $total, $total, $perPage, $expectedLastPage, $result['last_page']
            ));
        }
    }

    /**
     * Property 3: current_page SHALL equal the requested page.
     *
     * **Validates: Requirements 7.1**
     */
    public function testCurrentPageEqualsRequestedPage(): void
    {
        $service = new TenantOwnerService($this->db);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $page = mt_rand(1, 50);
            $perPage = mt_rand(1, 100);

            $result = $service->getBusinessSummaries($this->tenantId, $page, $perPage);

            $this->assertSame($page, $result['current_page'], sprintf(
                'Iteration %d: current_page should equal requested page %d, got %d',
                $i, $page, $result['current_page']
            ));
        }
    }

    /**
     * Property 3: per_page SHALL equal the requested perPage.
     *
     * **Validates: Requirements 7.1**
     */
    public function testPerPageEqualsRequestedPerPage(): void
    {
        $service = new TenantOwnerService($this->db);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $page = mt_rand(1, 50);
            $perPage = mt_rand(1, 100);

            $result = $service->getBusinessSummaries($this->tenantId, $page, $perPage);

            $this->assertSame($perPage, $result['per_page'], sprintf(
                'Iteration %d: per_page should equal requested perPage %d, got %d',
                $i, $perPage, $result['per_page']
            ));
        }
    }

    /**
     * Property 3: data SHALL be an array (the paginated business list).
     *
     * **Validates: Requirements 7.1**
     */
    public function testDataIsArray(): void
    {
        $service = new TenantOwnerService($this->db);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $page = mt_rand(1, 50);
            $perPage = mt_rand(1, 100);

            $result = $service->getBusinessSummaries($this->tenantId, $page, $perPage);

            $this->assertIsArray($result['data'], sprintf(
                'Iteration %d (page=%d, perPage=%d): data must be an array',
                $i, $page, $perPage
            ));
        }
    }

    /**
     * Property 3: total SHALL be a non-negative integer.
     *
     * **Validates: Requirements 7.1**
     */
    public function testTotalIsNonNegativeInteger(): void
    {
        $service = new TenantOwnerService($this->db);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $page = mt_rand(1, 50);
            $perPage = mt_rand(1, 100);

            $result = $service->getBusinessSummaries($this->tenantId, $page, $perPage);

            $this->assertIsInt($result['total'], sprintf(
                'Iteration %d: total must be an integer',
                $i
            ));
            $this->assertGreaterThanOrEqual(0, $result['total'], sprintf(
                'Iteration %d: total must be non-negative, got %d',
                $i, $result['total']
            ));
        }
    }
}
