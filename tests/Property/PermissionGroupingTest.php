<?php

namespace Tests\Property;

use PHPUnit\Framework\TestCase;

/**
 * Feature: user-roles-permissions, Property 2: Permission Grouping by Module Prefix
 *
 * For any set of permissions with dot-notation codes, grouping by the substring before
 * the first dot SHALL produce groups where:
 * 1. Every permission within a group shares the same prefix
 * 2. No permission appears in more than one group
 * 3. All permissions are accounted for (no permission is lost)
 *
 * **Validates: Requirements 2.1, 7.1**
 */
class PermissionGroupingTest extends TestCase
{
    private const ITERATIONS = 100;

    /**
     * Reimplement the groupPermissions logic from roleForm.modal.js in PHP.
     *
     * The JS logic:
     *   const dotIndex = perm.code.indexOf('.');
     *   const prefix = dotIndex > -1 ? perm.code.substring(0, dotIndex) : perm.code;
     *   groups[prefix].push(perm);
     *
     * @param array $permissions Array of permission objects with 'id' and 'code' keys
     * @return array Associative array: prefix => array of permissions
     */
    private function groupPermissions(array $permissions): array
    {
        $groups = [];
        foreach ($permissions as $perm) {
            $dotIndex = strpos($perm['code'], '.');
            $prefix = $dotIndex !== false ? substr($perm['code'], 0, $dotIndex) : $perm['code'];
            if (!isset($groups[$prefix])) {
                $groups[$prefix] = [];
            }
            $groups[$prefix][] = $perm;
        }
        return $groups;
    }

    /**
     * Generate a random lowercase alpha string of given length (for module prefixes).
     */
    private static function randomAlpha(int $length): string
    {
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= chr(mt_rand(ord('a'), ord('z')));
        }
        return $str;
    }

    /**
     * Generate a random permission code in dot-notation.
     * Formats: "module.action", "module.sub.action", or "module" (no dot).
     */
    private static function generatePermissionCode(): string
    {
        $type = mt_rand(0, 9);

        // Prefix: 2-10 lowercase alpha chars
        $prefix = self::randomAlpha(mt_rand(2, 10));

        if ($type === 0) {
            // ~10% chance: no dot (code is just the prefix)
            return $prefix;
        } elseif ($type <= 7) {
            // ~70% chance: single dot "module.action"
            $action = self::randomAlpha(mt_rand(2, 10));
            return $prefix . '.' . $action;
        } else {
            // ~20% chance: multiple dots "module.sub.action"
            $sub = self::randomAlpha(mt_rand(2, 6));
            $action = self::randomAlpha(mt_rand(2, 8));
            return $prefix . '.' . $sub . '.' . $action;
        }
    }

    /**
     * Generate a random set of permissions (1-30 permissions per set).
     */
    private static function generatePermissionSet(): array
    {
        $count = mt_rand(1, 30);
        $permissions = [];
        for ($i = 0; $i < $count; $i++) {
            $permissions[] = [
                'id' => $i + 1,
                'code' => self::generatePermissionCode(),
                'name' => 'perm_' . ($i + 1),
            ];
        }
        return $permissions;
    }

    /**
     * Data provider generating 100 random permission sets.
     */
    public static function permissionSetProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $cases["perm_set_iter_{$i}"] = [self::generatePermissionSet()];
        }
        return $cases;
    }

    /**
     * Property 2: Every permission within a group shares the same prefix.
     *
     * For any randomly generated set of permissions, after grouping, every permission
     * in a group must have a code whose prefix (text before the first dot, or the
     * entire code if no dot) matches the group key.
     *
     * **Validates: Requirements 2.1, 7.1**
     *
     * @dataProvider permissionSetProvider
     */
    public function testEveryPermissionInGroupSharesSamePrefix(array $permissions): void
    {
        $grouped = $this->groupPermissions($permissions);

        foreach ($grouped as $prefix => $perms) {
            foreach ($perms as $perm) {
                $dotIndex = strpos($perm['code'], '.');
                $actualPrefix = $dotIndex !== false
                    ? substr($perm['code'], 0, $dotIndex)
                    : $perm['code'];

                $this->assertSame(
                    $prefix,
                    $actualPrefix,
                    sprintf(
                        'Permission "%s" has prefix "%s" but was placed in group "%s"',
                        $perm['code'],
                        $actualPrefix,
                        $prefix
                    )
                );
            }
        }
    }

    /**
     * Property 2: No permission appears in more than one group.
     *
     * For any randomly generated set of permissions, after grouping, each permission
     * ID must appear in exactly one group (no duplicates across groups).
     *
     * **Validates: Requirements 2.1, 7.1**
     *
     * @dataProvider permissionSetProvider
     */
    public function testNoPermissionAppearsInMoreThanOneGroup(array $permissions): void
    {
        $grouped = $this->groupPermissions($permissions);

        $seenIds = [];
        foreach ($grouped as $prefix => $perms) {
            foreach ($perms as $perm) {
                $id = $perm['id'];
                $this->assertArrayNotHasKey(
                    $id,
                    $seenIds,
                    sprintf(
                        'Permission id=%d (code="%s") appears in group "%s" but was already seen in group "%s"',
                        $id,
                        $perm['code'],
                        $prefix,
                        $seenIds[$id] ?? 'unknown'
                    )
                );
                $seenIds[$id] = $prefix;
            }
        }
    }

    /**
     * Property 2: All permissions are accounted for (no permission is lost).
     *
     * For any randomly generated set of permissions, the total number of permissions
     * across all groups must equal the original input count.
     *
     * **Validates: Requirements 2.1, 7.1**
     *
     * @dataProvider permissionSetProvider
     */
    public function testAllPermissionsAreAccountedFor(array $permissions): void
    {
        $grouped = $this->groupPermissions($permissions);

        $totalInGroups = 0;
        foreach ($grouped as $perms) {
            $totalInGroups += count($perms);
        }

        $this->assertSame(
            count($permissions),
            $totalInGroups,
            sprintf(
                'Input had %d permissions but grouped result contains %d total. Some permissions were lost or duplicated.',
                count($permissions),
                $totalInGroups
            )
        );

        // Also verify all original IDs are present in the grouped output
        $originalIds = array_column($permissions, 'id');
        $groupedIds = [];
        foreach ($grouped as $perms) {
            foreach ($perms as $perm) {
                $groupedIds[] = $perm['id'];
            }
        }
        sort($originalIds);
        sort($groupedIds);

        $this->assertSame(
            $originalIds,
            $groupedIds,
            'The set of permission IDs in grouped output does not match the original input set'
        );
    }

    /**
     * Property 2: Edge case — codes with multiple dots use only the first dot for prefix.
     *
     * For any permission code with multiple dots (e.g., "module.sub.action"),
     * the prefix SHALL be the text before the FIRST dot only ("module").
     *
     * **Validates: Requirements 2.1, 7.1**
     */
    public function testMultipleDotCodesUseFirstDotForPrefix(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $prefix = self::randomAlpha(mt_rand(2, 10));
            $sub = self::randomAlpha(mt_rand(2, 6));
            $action = self::randomAlpha(mt_rand(2, 8));
            $code = $prefix . '.' . $sub . '.' . $action;

            $permissions = [
                ['id' => 1, 'code' => $code, 'name' => 'test_perm'],
            ];

            $grouped = $this->groupPermissions($permissions);

            $this->assertArrayHasKey(
                $prefix,
                $grouped,
                sprintf(
                    'Code "%s" should produce group key "%s" (first dot prefix) but got keys: [%s]',
                    $code,
                    $prefix,
                    implode(', ', array_keys($grouped))
                )
            );

            $this->assertCount(
                1,
                $grouped[$prefix],
                sprintf('Group "%s" should contain exactly 1 permission for code "%s"', $prefix, $code)
            );
        }
    }

    /**
     * Property 2: Edge case — codes with no dot use the entire code as the prefix.
     *
     * For any permission code without a dot, the prefix SHALL be the entire code.
     *
     * **Validates: Requirements 2.1, 7.1**
     */
    public function testNoDotCodesUseEntireCodeAsPrefix(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $code = self::randomAlpha(mt_rand(2, 15));

            $permissions = [
                ['id' => 1, 'code' => $code, 'name' => 'test_perm'],
            ];

            $grouped = $this->groupPermissions($permissions);

            $this->assertArrayHasKey(
                $code,
                $grouped,
                sprintf(
                    'Code "%s" (no dot) should produce group key "%s" but got keys: [%s]',
                    $code,
                    $code,
                    implode(', ', array_keys($grouped))
                )
            );

            $this->assertCount(
                1,
                $grouped[$code],
                sprintf('Group "%s" should contain exactly 1 permission for code "%s"', $code, $code)
            );
        }
    }
}
