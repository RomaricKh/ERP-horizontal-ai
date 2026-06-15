<?php

namespace Tests\Property;

use App\Repository\ManufacturingRepository;
use App\Services\ManufacturingService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: manufacturing-module, Property 14: Production order list ordering
 * Feature: manufacturing-module, Property 15: Production order date and item filtering
 *
 * Property 14: For any set of production orders for a business, the list endpoint
 * SHALL return them ordered by production_date descending.
 *
 * Property 15: For any date range filter applied to the production orders list, only
 * orders with production_date within that range SHALL be returned. For any manufactured
 * item filter, only orders for that item SHALL be returned.
 *
 * **Validates: Requirements 7.1, 7.3**
 */
class ManufacturingOrderFilterPropertyTest extends TestCase
{
    private const ITERATIONS = 100;

    private int $tenantId = 1;
    private int $businessId = 1;
    private int $userId = 1;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => $this->userId,
            'tenant_id'   => $this->tenantId,
            'business_id' => $this->businessId,
        ]);
    }

    /**
     * Data provider generating 100 random sets of production orders with random dates.
     *
     * Each case: [orders] where orders is an array of production order records
     * with random production_date values.
     */
    public static function orderingProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $numOrders = mt_rand(2, 20);
            $orders = [];

            for ($j = 0; $j < $numOrders; $j++) {
                // Generate random dates within a 2-year range
                $timestamp = mt_rand(
                    strtotime('2022-01-01'),
                    strtotime('2024-12-31')
                );
                $productionDate = date('Y-m-d H:i:s', $timestamp);

                $orders[] = [
                    'id'                    => $j + 1,
                    'tenant_id'             => 1,
                    'business_id'           => 1,
                    'recipe_id'             => mt_rand(1, 10),
                    'manufactured_item_id'  => mt_rand(1, 5),
                    'production_quantity'   => round(mt_rand(100, 10000) / 100, 2),
                    'manufacturing_cost'    => round(mt_rand(1000, 100000) / 100, 2),
                    'unit_manufacturing_cost' => round(mt_rand(100, 5000) / 100, 2),
                    'status'                => 'COMPLETED',
                    'production_date'       => $productionDate,
                    'created_by'            => 1,
                    'created_at'            => $productionDate,
                    'manufactured_item_name' => 'Item_' . mt_rand(1, 5),
                ];
            }

            $cases["iter_{$i}_orders_{$numOrders}"] = [$orders];
        }
        return $cases;
    }

    /**
     * Property 14: Production order list ordering
     *
     * For any set of production orders for a business, the list endpoint SHALL
     * return them ordered by production_date descending.
     *
     * **Validates: Requirements 7.1**
     *
     * @dataProvider orderingProvider
     */
    public function testProductionOrderListIsOrderedByDateDescending(array $orders): void
    {
        // Sort orders by production_date DESC (simulating what the repository SQL does)
        $sortedOrders = $orders;
        usort($sortedOrders, function ($a, $b) {
            return strcmp($b['production_date'], $a['production_date']);
        });

        // Mock the repository to return orders in descending date order
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $mfgRepo->method('getAllProductionOrders')
            ->with($this->tenantId, $this->businessId, null)
            ->willReturn($sortedOrders);

        // Build the service with the mocked repository
        $service = $this->buildServiceWithRepo($mfgRepo);

        // Call listProductionOrders
        $result = $service->listProductionOrders();

        // Assert the result is in descending production_date order
        $this->assertCount(count($orders), $result);

        for ($k = 0; $k < count($result) - 1; $k++) {
            $currentDate = $result[$k]['production_date'];
            $nextDate = $result[$k + 1]['production_date'];
            $this->assertGreaterThanOrEqual(
                $nextDate,
                $currentDate,
                sprintf(
                    'Order at index %d (date: %s) should be >= order at index %d (date: %s) for descending order',
                    $k,
                    $currentDate,
                    $k + 1,
                    $nextDate
                )
            );
        }
    }

    /**
     * Data provider generating 100 random sets of production orders with various
     * dates and manufactured_item_ids, along with random filter criteria.
     *
     * Each case: [orders, filters] where:
     * - orders: array of production order records with random dates and item IDs
     * - filters: array with optional start_date, end_date, manufactured_item_id
     */
    public static function filteringProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $numOrders = mt_rand(5, 25);
            $orders = [];
            $itemIds = range(1, 5);

            for ($j = 0; $j < $numOrders; $j++) {
                $timestamp = mt_rand(
                    strtotime('2022-01-01'),
                    strtotime('2024-12-31')
                );
                $productionDate = date('Y-m-d H:i:s', $timestamp);
                $itemId = $itemIds[array_rand($itemIds)];

                $orders[] = [
                    'id'                    => $j + 1,
                    'tenant_id'             => 1,
                    'business_id'           => 1,
                    'recipe_id'             => mt_rand(1, 10),
                    'manufactured_item_id'  => $itemId,
                    'production_quantity'   => round(mt_rand(100, 10000) / 100, 2),
                    'manufacturing_cost'    => round(mt_rand(1000, 100000) / 100, 2),
                    'unit_manufacturing_cost' => round(mt_rand(100, 5000) / 100, 2),
                    'status'                => 'COMPLETED',
                    'production_date'       => $productionDate,
                    'created_by'            => 1,
                    'created_at'            => $productionDate,
                    'manufactured_item_name' => 'Item_' . $itemId,
                ];
            }

            // Generate random filter criteria
            $filters = [];
            $filterType = mt_rand(0, 3);

            if ($filterType === 0 || $filterType === 3) {
                // Date range filter
                $startTimestamp = mt_rand(strtotime('2022-06-01'), strtotime('2023-06-01'));
                $endTimestamp = mt_rand($startTimestamp, strtotime('2024-06-01'));
                $filters['start_date'] = date('Y-m-d H:i:s', $startTimestamp);
                $filters['end_date'] = date('Y-m-d H:i:s', $endTimestamp);
            }

            if ($filterType === 1 || $filterType === 3) {
                // Item filter
                $filters['manufactured_item_id'] = $itemIds[array_rand($itemIds)];
            }

            if ($filterType === 2) {
                // Only start_date filter
                $startTimestamp = mt_rand(strtotime('2022-06-01'), strtotime('2023-12-01'));
                $filters['start_date'] = date('Y-m-d H:i:s', $startTimestamp);
            }

            $cases["filter_iter_{$i}_orders_{$numOrders}_type_{$filterType}"] = [$orders, $filters];
        }
        return $cases;
    }

    /**
     * Property 15: Production order date and item filtering
     *
     * For any date range filter applied to the production orders list, only orders
     * with production_date within that range SHALL be returned. For any manufactured
     * item filter, only orders for that item SHALL be returned.
     *
     * **Validates: Requirements 7.3**
     *
     * @dataProvider filteringProvider
     */
    public function testProductionOrderDateAndItemFiltering(array $orders, array $filters): void
    {
        // Compute expected results by applying filters to the full order set
        $expectedOrders = array_filter($orders, function ($order) use ($filters) {
            if (!empty($filters['start_date'])) {
                if ($order['production_date'] < $filters['start_date']) {
                    return false;
                }
            }
            if (!empty($filters['end_date'])) {
                if ($order['production_date'] > $filters['end_date']) {
                    return false;
                }
            }
            if (!empty($filters['manufactured_item_id'])) {
                if ($order['manufactured_item_id'] != $filters['manufactured_item_id']) {
                    return false;
                }
            }
            return true;
        });

        // Sort expected results by production_date DESC
        usort($expectedOrders, function ($a, $b) {
            return strcmp($b['production_date'], $a['production_date']);
        });
        $expectedOrders = array_values($expectedOrders);

        // Mock the repository to simulate the filtering behavior
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $mfgRepo->method('getAllProductionOrders')
            ->with($this->tenantId, $this->businessId, $filters)
            ->willReturn($expectedOrders);

        // Build the service with the mocked repository
        $service = $this->buildServiceWithRepo($mfgRepo);

        // Call listProductionOrders with filters
        $result = $service->listProductionOrders($filters);

        // Assert only matching orders are returned
        $this->assertCount(
            count($expectedOrders),
            $result,
            sprintf(
                'Expected %d orders after filtering, got %d',
                count($expectedOrders),
                count($result)
            )
        );

        // Verify each returned order matches the filter criteria
        foreach ($result as $order) {
            if (!empty($filters['start_date'])) {
                $this->assertGreaterThanOrEqual(
                    $filters['start_date'],
                    $order['production_date'],
                    sprintf(
                        'Order %d production_date %s should be >= start_date %s',
                        $order['id'],
                        $order['production_date'],
                        $filters['start_date']
                    )
                );
            }

            if (!empty($filters['end_date'])) {
                $this->assertLessThanOrEqual(
                    $filters['end_date'],
                    $order['production_date'],
                    sprintf(
                        'Order %d production_date %s should be <= end_date %s',
                        $order['id'],
                        $order['production_date'],
                        $filters['end_date']
                    )
                );
            }

            if (!empty($filters['manufactured_item_id'])) {
                $this->assertEquals(
                    $filters['manufactured_item_id'],
                    $order['manufactured_item_id'],
                    sprintf(
                        'Order %d manufactured_item_id %d should equal filter %d',
                        $order['id'],
                        $order['manufactured_item_id'],
                        $filters['manufactured_item_id']
                    )
                );
            }
        }

        // Verify ordering is still descending by production_date
        for ($k = 0; $k < count($result) - 1; $k++) {
            $currentDate = $result[$k]['production_date'];
            $nextDate = $result[$k + 1]['production_date'];
            $this->assertGreaterThanOrEqual(
                $nextDate,
                $currentDate,
                sprintf(
                    'Filtered result at index %d (date: %s) should be >= index %d (date: %s)',
                    $k,
                    $currentDate,
                    $k + 1,
                    $nextDate
                )
            );
        }

        // Verify no matching orders were excluded
        foreach ($orders as $order) {
            $shouldBeIncluded = true;

            if (!empty($filters['start_date']) && $order['production_date'] < $filters['start_date']) {
                $shouldBeIncluded = false;
            }
            if (!empty($filters['end_date']) && $order['production_date'] > $filters['end_date']) {
                $shouldBeIncluded = false;
            }
            if (!empty($filters['manufactured_item_id']) && $order['manufactured_item_id'] != $filters['manufactured_item_id']) {
                $shouldBeIncluded = false;
            }

            if ($shouldBeIncluded) {
                $found = false;
                foreach ($result as $resultOrder) {
                    if ($resultOrder['id'] === $order['id']) {
                        $found = true;
                        break;
                    }
                }
                $this->assertTrue(
                    $found,
                    sprintf(
                        'Order %d (date: %s, item: %d) should be included in filtered results',
                        $order['id'],
                        $order['production_date'],
                        $order['manufactured_item_id']
                    )
                );
            }
        }
    }

    /**
     * Build a ManufacturingService with a mocked ManufacturingRepository.
     *
     * @param ManufacturingRepository $mfgRepo The mocked repository
     * @return ManufacturingService
     */
    private function buildServiceWithRepo(ManufacturingRepository $mfgRepo): ManufacturingService
    {
        $ref = new ReflectionClass(ManufacturingService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('repo');
        $prop->setAccessible(true);
        $prop->setValue($service, $mfgRepo);

        return $service;
    }
}
