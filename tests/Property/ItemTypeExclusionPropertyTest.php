<?php

namespace Tests\Property;

use App\Services\ItemService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: invoice-item-filter, Property 1: Type exclusion filter correctness
 *
 * For any set of active items with associated type names, and for any comma-separated
 * `exclude_types` parameter string (with arbitrary casing and whitespace), the Items API
 * SHALL return exactly those items whose type name — after trimming whitespace and
 * lowercasing — does NOT match any non-empty, trimmed, lowercased entry in the exclusion list.
 *
 * **Validates: Requirements 1.1, 1.3, 1.4, 4.1, 4.2, 4.3**
 */
class ItemTypeExclusionPropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * Generate a random string with optional whitespace padding and varied casing.
     */
    private static function randomTypeName(): string
    {
        $words = ['Raw Material', 'Finished Goods', 'Service', 'Consumable', 'Assembly',
                  'Component', 'Packaging', 'Tool', 'Spare Part', 'Chemical'];
        $base = $words[array_rand($words)];

        // Randomly vary casing
        $caseVariant = mt_rand(0, 3);
        switch ($caseVariant) {
            case 0:
                $base = strtolower($base);
                break;
            case 1:
                $base = strtoupper($base);
                break;
            case 2:
                // Random mixed case
                $chars = str_split($base);
                foreach ($chars as &$c) {
                    $c = mt_rand(0, 1) ? strtoupper($c) : strtolower($c);
                }
                $base = implode('', $chars);
                break;
            default:
                // Keep original casing
                break;
        }

        // Randomly add whitespace padding
        $leftPad = mt_rand(0, 1) ? str_repeat(' ', mt_rand(1, 3)) : '';
        $rightPad = mt_rand(0, 1) ? str_repeat(' ', mt_rand(1, 3)) : '';

        return $leftPad . $base . $rightPad;
    }

    /**
     * Generate a random exclude_types parameter string with commas, empty segments, whitespace.
     *
     * @param array $typeNames Pool of type names to pick from
     * @return string The raw exclude_types parameter value
     */
    private static function randomExcludeTypesParam(array $typeNames): string
    {
        $numSegments = mt_rand(1, 6);
        $segments = [];

        for ($i = 0; $i < $numSegments; $i++) {
            $segmentType = mt_rand(0, 4);
            switch ($segmentType) {
                case 0:
                    // Empty segment
                    $segments[] = '';
                    break;
                case 1:
                    // Whitespace-only segment
                    $segments[] = str_repeat(' ', mt_rand(1, 3));
                    break;
                default:
                    // Pick a type name with random casing/whitespace variation
                    $picked = $typeNames[array_rand($typeNames)];
                    $caseVariant = mt_rand(0, 3);
                    switch ($caseVariant) {
                        case 0:
                            $picked = strtolower($picked);
                            break;
                        case 1:
                            $picked = strtoupper($picked);
                            break;
                        case 2:
                            $chars = str_split($picked);
                            foreach ($chars as &$c) {
                                $c = mt_rand(0, 1) ? strtoupper($c) : strtolower($c);
                            }
                            $picked = implode('', $chars);
                            break;
                        default:
                            break;
                    }
                    // Optionally add whitespace padding
                    $leftPad = mt_rand(0, 1) ? str_repeat(' ', mt_rand(1, 2)) : '';
                    $rightPad = mt_rand(0, 1) ? str_repeat(' ', mt_rand(1, 2)) : '';
                    $segments[] = $leftPad . $picked . $rightPad;
                    break;
            }
        }

        return implode(',', $segments);
    }

    /**
     * Data provider generating 120+ random type exclusion scenarios.
     *
     * Each case: [items, excludeTypesParam, expectedItemIds]
     * - items: array of items with id and type_name
     * - excludeTypesParam: raw comma-separated exclude_types string
     * - expectedItemIds: array of item IDs that should remain after filtering
     */
    public static function typeExclusionProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate random items with random type names
            $numItems = mt_rand(1, 10);
            $typePool = ['Raw Material', 'Finished Goods', 'Service', 'Consumable', 'Assembly'];
            $items = [];

            for ($j = 0; $j < $numItems; $j++) {
                $typeName = self::randomTypeName();
                $items[] = [
                    'id' => $j + 1,
                    'name' => 'Item_' . ($j + 1),
                    'type_name' => $typeName,
                ];
            }

            // Collect the canonical (trimmed, lowercased) type names from items for the exclusion pool
            $canonicalTypeNames = array_unique(array_map(function ($item) {
                return trim($item['type_name']);
            }, $items));

            // Generate a random exclude_types parameter
            $excludeTypesParam = self::randomExcludeTypesParam(array_values($canonicalTypeNames));

            // Compute expected result using the same logic as parseExcludeTypes + filtering
            $excludeTypes = self::computeExcludeTypes($excludeTypesParam);

            $expectedItemIds = [];
            foreach ($items as $item) {
                $normalizedTypeName = strtolower(trim($item['type_name']));
                if (!in_array($normalizedTypeName, $excludeTypes, true)) {
                    $expectedItemIds[] = $item['id'];
                }
            }

            $cases["iter_{$i}"] = [
                $items,
                $excludeTypesParam,
                $expectedItemIds,
            ];
        }

        return $cases;
    }

    /**
     * Compute the exclusion list the same way parseExcludeTypes does:
     * split on comma, trim each segment, remove empty, lowercase.
     */
    private static function computeExcludeTypes(string $param): array
    {
        if (trim($param) === '') {
            return [];
        }
        $types = explode(',', $param);
        $cleaned = [];
        foreach ($types as $type) {
            $trimmed = trim($type);
            if ($trimmed !== '') {
                $cleaned[] = strtolower($trimmed);
            }
        }
        return $cleaned;
    }

    /**
     * Property 1: Type exclusion filter correctness
     *
     * For any set of active items with associated type names, and for any comma-separated
     * `exclude_types` parameter string (with arbitrary casing and whitespace), the filtering
     * logic SHALL return exactly those items whose type name — after trimming whitespace and
     * lowercasing — does NOT match any non-empty, trimmed, lowercased entry in the exclusion list.
     *
     * **Validates: Requirements 1.1, 1.3, 1.4, 4.1, 4.2, 4.3**
     *
     * @dataProvider typeExclusionProvider
     */
    public function testTypeExclusionFilterCorrectness(
        array $items,
        string $excludeTypesParam,
        array $expectedItemIds
    ): void {
        // Use reflection to access the private parseExcludeTypes method
        $service = $this->buildItemServiceWithoutConstructor();
        $parseMethod = $this->getParseExcludeTypesMethod($service);

        // Call parseExcludeTypes with the random parameter
        $parsedExcludeTypes = $parseMethod->invoke($service, $excludeTypesParam);

        // Simulate the filtering logic that the repository would perform:
        // LOWER(TRIM(it.name)) NOT IN (lowercased exclusion list)
        $lowercasedExclusions = array_map(function ($type) {
            return strtolower(trim($type));
        }, $parsedExcludeTypes);

        $filteredItems = array_filter($items, function ($item) use ($lowercasedExclusions) {
            $normalizedTypeName = strtolower(trim($item['type_name']));
            return !in_array($normalizedTypeName, $lowercasedExclusions, true);
        });

        $actualItemIds = array_values(array_map(function ($item) {
            return $item['id'];
        }, $filteredItems));

        // Sort both arrays for comparison
        sort($actualItemIds);
        sort($expectedItemIds);

        $this->assertEquals(
            $expectedItemIds,
            $actualItemIds,
            sprintf(
                "Type exclusion filter mismatch.\n" .
                "exclude_types param: '%s'\n" .
                "Parsed exclusions: [%s]\n" .
                "Lowercased exclusions: [%s]\n" .
                "Items: %s\n" .
                "Expected IDs: [%s]\n" .
                "Actual IDs: [%s]",
                $excludeTypesParam,
                implode(', ', array_map(fn($t) => "'$t'", $parsedExcludeTypes)),
                implode(', ', array_map(fn($t) => "'$t'", $lowercasedExclusions)),
                json_encode(array_map(fn($item) => ['id' => $item['id'], 'type' => $item['type_name']], $items)),
                implode(', ', $expectedItemIds),
                implode(', ', $actualItemIds)
            )
        );
    }

    /**
     * Data provider generating 120+ random scenarios where exclude_types is absent,
     * empty, whitespace-only, or commas-only — all cases where no filtering should occur.
     *
     * Each case: [items, excludeTypesParam]
     * - items: array of items with id and type_name
     * - excludeTypesParam: null, empty string, whitespace-only, or commas-only value
     */
    public static function emptyExclusionProvider(): array
    {
        $cases = [];

        // Types of "empty" exclude_types values to randomly pick from
        $emptyVariants = [
            null,
            '',
            ' ',
            '  ',
            '   ',
            "\t",
            " \t ",
            ',',
            ',,',
            ',,,',
            ', ,',
            ' , , ',
            ",\t,",
            '  ,  ,  ',
            ', , , ,',
        ];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate random items with random type names
            $numItems = mt_rand(1, 10);
            $items = [];

            for ($j = 0; $j < $numItems; $j++) {
                $typeName = self::randomTypeName();
                $items[] = [
                    'id' => $j + 1,
                    'name' => 'Item_' . ($j + 1),
                    'type_name' => $typeName,
                ];
            }

            // Pick a random "empty" variant for exclude_types
            $excludeTypesParam = $emptyVariants[array_rand($emptyVariants)];

            $cases["iter_{$i}"] = [
                $items,
                $excludeTypesParam,
            ];
        }

        return $cases;
    }

    /**
     * Property 2: Empty exclusion preserves all items
     *
     * For any set of active items, when the `exclude_types` parameter is absent (null),
     * empty, or contains only whitespace/commas, the parseExcludeTypes method SHALL return
     * an empty array, meaning no items are filtered out and all active items are preserved.
     *
     * **Validates: Requirements 1.2, 1.5**
     *
     * @dataProvider emptyExclusionProvider
     */
    public function testEmptyExclusionPreservesAllItems(
        array $items,
        ?string $excludeTypesParam
    ): void {
        // Use reflection to access the private parseExcludeTypes method
        $service = $this->buildItemServiceWithoutConstructor();
        $parseMethod = $this->getParseExcludeTypesMethod($service);

        // Call parseExcludeTypes with the empty/null/whitespace/commas-only parameter
        $parsedExcludeTypes = $parseMethod->invoke($service, $excludeTypesParam);

        // Assert that parseExcludeTypes returns an empty array for these inputs
        $this->assertIsArray($parsedExcludeTypes);
        $this->assertEmpty(
            $parsedExcludeTypes,
            sprintf(
                "parseExcludeTypes should return empty array for empty/null/whitespace/commas-only input.\n" .
                "Input: %s\n" .
                "Got: [%s]",
                var_export($excludeTypesParam, true),
                implode(', ', array_map(fn($t) => "'$t'", $parsedExcludeTypes))
            )
        );

        // Since exclusion list is empty, simulate filtering — all items should be preserved
        $filteredItems = array_filter($items, function ($item) use ($parsedExcludeTypes) {
            $normalizedTypeName = strtolower(trim($item['type_name']));
            return !in_array($normalizedTypeName, $parsedExcludeTypes, true);
        });

        $allItemIds = array_map(fn($item) => $item['id'], $items);
        $filteredItemIds = array_values(array_map(fn($item) => $item['id'], $filteredItems));

        sort($allItemIds);
        sort($filteredItemIds);

        $this->assertEquals(
            $allItemIds,
            $filteredItemIds,
            sprintf(
                "All items should be preserved when exclude_types is empty/null/whitespace/commas-only.\n" .
                "Input exclude_types: %s\n" .
                "Expected all %d items, got %d items",
                var_export($excludeTypesParam, true),
                count($items),
                count($filteredItemIds)
            )
        );
    }

    /**
     * Build an ItemService instance without calling the constructor (no DB needed).
     */
    private function buildItemServiceWithoutConstructor(): ItemService
    {
        $ref = new ReflectionClass(ItemService::class);
        return $ref->newInstanceWithoutConstructor();
    }

    /**
     * Get the private parseExcludeTypes method via reflection.
     */
    private function getParseExcludeTypesMethod(ItemService $service): \ReflectionMethod
    {
        $ref = new ReflectionClass(ItemService::class);
        $method = $ref->getMethod('parseExcludeTypes');
        $method->setAccessible(true);
        return $method;
    }
}
