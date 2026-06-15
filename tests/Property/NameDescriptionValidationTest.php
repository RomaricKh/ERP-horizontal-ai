<?php

namespace Tests\Property;

use App\Repository\RoleRepository;
use App\Services\AuditService;
use App\Services\RoleService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: business-specific-roles, Property 8: Role name validation
 *
 * For any role creation or update (CUSTOM or TEMPLATE), the name must be between 2 and 50
 * characters after trimming. Names shorter than 2 or longer than 50 characters must be rejected.
 * Descriptions exceeding 255 characters must be rejected.
 *
 * **Validates: Requirements 4.1, 5.2**
 */
class NameDescriptionValidationTest extends TestCase
{
    private const ITERATIONS = 100;

    /**
     * Generate a random string of given length using printable ASCII characters.
     */
    private function randomString(int $length): string
    {
        $chars = '';
        for ($i = 0; $i < $length; $i++) {
            $chars .= chr(mt_rand(32, 126));
        }
        return $chars;
    }

    /**
     * Data provider generating 100 random name strings for name validation testing.
     */
    public static function nameValidationProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $length = mt_rand(0, 100);
            $type = mt_rand(0, 4);

            switch ($type) {
                case 0:
                    // All spaces
                    $name = str_repeat(' ', $length);
                    break;
                case 1:
                    // Leading spaces + content
                    $spaceCount = mt_rand(1, max(1, min(10, $length)));
                    $contentLength = max(0, $length - $spaceCount);
                    $content = '';
                    for ($j = 0; $j < $contentLength; $j++) {
                        $content .= chr(mt_rand(33, 126)); // non-space printable
                    }
                    $name = str_repeat(' ', $spaceCount) . $content;
                    break;
                case 2:
                    // Trailing spaces + content
                    $spaceCount = mt_rand(1, max(1, min(10, $length)));
                    $contentLength = max(0, $length - $spaceCount);
                    $content = '';
                    for ($j = 0; $j < $contentLength; $j++) {
                        $content .= chr(mt_rand(33, 126)); // non-space printable
                    }
                    $name = $content . str_repeat(' ', $spaceCount);
                    break;
                default:
                    // Normal random string (printable ASCII)
                    $name = '';
                    for ($j = 0; $j < $length; $j++) {
                        $name .= chr(mt_rand(32, 126));
                    }
                    break;
            }

            $cases["name_iter_{$i}"] = [$name];
        }
        return $cases;
    }

    /**
     * Data provider generating 100 random description strings for description validation testing.
     */
    public static function descriptionValidationProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $length = mt_rand(0, 500);
            $description = '';
            for ($j = 0; $j < $length; $j++) {
                $description .= chr(mt_rand(32, 126));
            }
            $cases["desc_iter_{$i}"] = [$description];
        }
        return $cases;
    }

    /**
     * Property 8: Name Validation
     *
     * For any random string used as a role name:
     * - If the trimmed length is between 2 and 50 (inclusive), the create call should succeed
     * - If the trimmed length is <2 or >50, the create call should throw InvalidArgumentException
     *   with message "Role name must be between 2 and 50 characters"
     *
     * **Validates: Requirements 4.1**
     *
     * @dataProvider nameValidationProvider
     */
    public function testNameValidationProperty(string $name): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        // Mock repository to allow creation to proceed (no duplicate, return dummy ID)
        $repo->method('existsByNameAndBusiness')->willReturn(false);
        $repo->method('findAllPermissions')->willReturn([]);
        $repo->method('insertWithType')->willReturn(999);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $trimmedLength = mb_strlen(trim($name));
        $shouldAccept = ($trimmedLength >= 2 && $trimmedLength <= 50);

        $businessId = mt_rand(1, 10000);
        $tenantId = mt_rand(1, 10000);

        if ($shouldAccept) {
            // Should NOT throw InvalidArgumentException for name
            try {
                $service->create($businessId, $tenantId, $name, null, []);
                $this->assertTrue(true); // Passed without exception
            } catch (InvalidArgumentException $e) {
                if ($e->getMessage() === 'Role name must be between 2 and 50 characters') {
                    $this->fail(sprintf(
                        'Name "%s" (trimmed length=%d) should have been accepted but was rejected with: "%s"',
                        mb_substr($name, 0, 60),
                        $trimmedLength,
                        $e->getMessage()
                    ));
                }
                // Other InvalidArgumentExceptions (e.g., duplicate name) are not name validation failures
                $this->assertTrue(true);
            }
        } else {
            // Should throw InvalidArgumentException with specific message
            $exceptionThrown = false;
            $exceptionMessage = '';

            try {
                $service->create($businessId, $tenantId, $name, null, []);
            } catch (InvalidArgumentException $e) {
                $exceptionThrown = true;
                $exceptionMessage = $e->getMessage();
            }

            $this->assertTrue(
                $exceptionThrown,
                sprintf(
                    'Name "%s" (trimmed length=%d) should have been rejected but no exception was thrown',
                    mb_substr($name, 0, 60),
                    $trimmedLength
                )
            );

            $this->assertSame(
                'Role name must be between 2 and 50 characters',
                $exceptionMessage,
                sprintf(
                    'Expected name validation error message but got "%s" for name "%s" (trimmed length=%d)',
                    $exceptionMessage,
                    mb_substr($name, 0, 60),
                    $trimmedLength
                )
            );
        }
    }

    /**
     * Property 8: Description Validation
     *
     * For any random string used as a role description:
     * - If the length is ≤255, the create call should succeed (no InvalidArgumentException for description)
     * - If the length is >255, the create call should throw InvalidArgumentException
     *   with message "Description must not exceed 255 characters"
     *
     * **Validates: Requirements 4.1**
     *
     * @dataProvider descriptionValidationProvider
     */
    public function testDescriptionValidationProperty(string $description): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        // Mock repository to allow creation to proceed (no duplicate, return dummy ID)
        $repo->method('existsByNameAndBusiness')->willReturn(false);
        $repo->method('findAllPermissions')->willReturn([]);
        $repo->method('insertWithType')->willReturn(999);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $descLength = mb_strlen($description);
        $shouldAccept = ($descLength <= 255);

        $businessId = mt_rand(1, 10000);
        $tenantId = mt_rand(1, 10000);
        // Use a valid name so name validation passes
        $validName = 'ValidRole';

        if ($shouldAccept) {
            // Should NOT throw InvalidArgumentException for description
            try {
                $service->create($businessId, $tenantId, $validName, $description, []);
                $this->assertTrue(true); // Passed without exception
            } catch (InvalidArgumentException $e) {
                if ($e->getMessage() === 'Description must not exceed 255 characters') {
                    $this->fail(sprintf(
                        'Description of length %d should have been accepted but was rejected with: "%s"',
                        $descLength,
                        $e->getMessage()
                    ));
                }
                // Other InvalidArgumentExceptions are not description validation failures
                $this->assertTrue(true);
            }
        } else {
            // Should throw InvalidArgumentException with specific message
            $exceptionThrown = false;
            $exceptionMessage = '';

            try {
                $service->create($businessId, $tenantId, $validName, $description, []);
            } catch (InvalidArgumentException $e) {
                $exceptionThrown = true;
                $exceptionMessage = $e->getMessage();
            }

            $this->assertTrue(
                $exceptionThrown,
                sprintf(
                    'Description of length %d should have been rejected but no exception was thrown',
                    $descLength
                )
            );

            $this->assertSame(
                'Description must not exceed 255 characters',
                $exceptionMessage,
                sprintf(
                    'Expected description validation error message but got "%s" for description of length %d',
                    $exceptionMessage,
                    $descLength
                )
            );
        }
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    private function buildServiceWithDeps(RoleRepository $repo, AuditService $audit): RoleService
    {
        $ref = new ReflectionClass(RoleService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $repoProp = $ref->getProperty('repo');
        $repoProp->setAccessible(true);
        $repoProp->setValue($service, $repo);

        $auditProp = $ref->getProperty('audit');
        $auditProp->setAccessible(true);
        $auditProp->setValue($service, $audit);

        return $service;
    }
}
