<?php

namespace App\Repository;

use PDO;

class BusinessTypeRepository {
	private $db;

	public function __construct(PDO $db) {
		$this->db = $db;
	}

	/**
	 * Get all business types.
	 */
	public function getAll(): array {
		$stmt = $this->db->query("
			SELECT id, tenant_id, code, name, description, is_system, is_default, created_at
			FROM business_types
			ORDER BY is_system DESC, created_at ASC
		");
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Get all business types for a tenant with correct ordering:
	 * system-defined types first, then custom types sorted by created_at ASC.
	 *
	 * Returns system types (is_system = 1 OR tenant_id IS NULL) and
	 * custom types belonging to the specified tenant (is_system = 0 AND tenant_id = :tenant_id).
	 */
	public function findAllForTenant(int $tenantId): array {
		$stmt = $this->db->prepare("
			SELECT id, tenant_id, code, name, description, is_system, is_default, created_at
			FROM business_types
			WHERE is_system = 1 OR tenant_id IS NULL OR tenant_id = :tenant_id
			ORDER BY is_system DESC, created_at ASC
		");
		$stmt->execute(['tenant_id' => $tenantId]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Get a business type by ID, or null if not found.
	 */
	public function getById(int $id): ?array {
		$stmt = $this->db->prepare("
			SELECT id, tenant_id, code, name, description, is_system, is_default, created_at
			FROM business_types
			WHERE id = :id
		");
		$stmt->execute(['id' => $id]);
		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}

	/**
	 * Get a business type by code, or null if not found.
	 */
	public function getByCode(string $code): ?array {
		$stmt = $this->db->prepare("
			SELECT id, tenant_id, code, name, description, is_system, is_default, created_at
			FROM business_types
			WHERE code = :code
		");
		$stmt->execute(['code' => $code]);
		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}

	/**
	 * Get the default business type (is_default = 1).
	 */
	public function getDefault(): array {
		$stmt = $this->db->query("
			SELECT id, tenant_id, code, name, description, is_system, is_default, created_at
			FROM business_types
			WHERE is_default = 1
			LIMIT 1
		");
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	/**
	 * Get module codes for a business type (flat array of code strings).
	 */
	public function getModuleCodesForType(int $businessTypeId): array {
		$stmt = $this->db->prepare("
			SELECT m.code
			FROM business_type_modules btm
			INNER JOIN modules m ON m.id = btm.module_id
			WHERE btm.business_type_id = :business_type_id
			ORDER BY m.sort_order ASC
		");
		$stmt->execute(['business_type_id' => $businessTypeId]);
		return $stmt->fetchAll(PDO::FETCH_COLUMN);
	}

	// =========================================================================
	// REQ-011: Tenant-owned business types — new methods
	// =========================================================================

	/**
	 * Copy a system type to create a tenant-owned type.
	 * Copies all business_type_modules rows from the system type.
	 *
	 * @param int $systemTypeId The system type to copy from
	 * @param int $tenantId The tenant that will own the new type
	 * @param string $name The name for the new tenant type
	 * @return int The new business type ID
	 * @throws \RuntimeException If the source type is not a system type
	 */
	public function copyFromSystem(int $systemTypeId, int $tenantId, string $name): int {
		// Verify the source is a system type
		$source = $this->getById($systemTypeId);
		if (!$source || !$source['is_system']) {
			throw new \RuntimeException('Source type must be a system type');
		}

		$this->db->beginTransaction();
		try {
			// Insert the new tenant-owned type
			$stmt = $this->db->prepare("
				INSERT INTO business_types (tenant_id, code, name, description, is_system, is_default, created_at)
				VALUES (:tenant_id, :code, :name, :description, 0, 0, NOW())
			");
			$stmt->execute([
				'tenant_id'   => $tenantId,
				'code'        => $source['code'] . '_t' . $tenantId . '_' . time(),
				'name'        => $name,
				'description' => $source['description'],
			]);
			$newTypeId = (int) $this->db->lastInsertId();

			// Copy all module associations
			$stmt = $this->db->prepare("
				INSERT INTO business_type_modules (business_type_id, module_id, created_at)
				SELECT :new_type_id, module_id, NOW()
				FROM business_type_modules
				WHERE business_type_id = :source_type_id
			");
			$stmt->execute([
				'new_type_id'    => $newTypeId,
				'source_type_id' => $systemTypeId,
			]);

			$this->db->commit();
			return $newTypeId;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Replace the module set for a tenant-owned business type.
	 * Guards against modifying system types.
	 *
	 * @param int $businessTypeId The business type to update
	 * @param array $moduleIds Array of module IDs to set
	 * @throws \RuntimeException If the type is a system type
	 */
	public function setModules(int $businessTypeId, array $moduleIds): void {
		$type = $this->getById($businessTypeId);
		if (!$type) {
			throw new \RuntimeException('Business type not found');
		}
		if ($type['is_system']) {
			throw new \RuntimeException('Cannot modify modules on a system type');
		}

		$this->db->beginTransaction();
		try {
			// Delete existing module associations
			$stmt = $this->db->prepare("
				DELETE FROM business_type_modules WHERE business_type_id = :type_id
			");
			$stmt->execute(['type_id' => $businessTypeId]);

			// Insert new module associations
			foreach ($moduleIds as $moduleId) {
				$stmt = $this->db->prepare("
					INSERT INTO business_type_modules (business_type_id, module_id, created_at)
					VALUES (:type_id, :module_id, NOW())
				");
				$stmt->execute([
					'type_id'   => $businessTypeId,
					'module_id' => (int) $moduleId,
				]);
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Find all tenant-owned business types for a tenant (is_system = 0).
	 * Returns types with their module lists.
	 *
	 * @param int $tenantId The tenant ID
	 * @return array Array of business types with 'modules' key containing module details
	 */
	public function findByTenant(int $tenantId): array {
		$stmt = $this->db->prepare("
			SELECT id, tenant_id, code, name, description, is_system, is_default, created_at
			FROM business_types
			WHERE is_system = 0 AND tenant_id = :tenant_id
			ORDER BY created_at ASC
		");
		$stmt->execute(['tenant_id' => $tenantId]);
		$types = $stmt->fetchAll(PDO::FETCH_ASSOC);

		foreach ($types as &$type) {
			$type['modules'] = $this->getModulesForType((int) $type['id']);
		}

		return $types;
	}

	/**
	 * Get system business types with their associated modules.
	 * Extracted from TenantBusinessController::listSystemBusinessTypes().
	 *
	 * Returns system types (is_system = 1) with a 'modules' key containing
	 * each type's associated module code and name.
	 *
	 * @return array Array of system business types with 'modules' sub-array
	 */
	public function getSystemTypesWithModules(): array {
		$stmt = $this->db->prepare("
			SELECT bt.id, bt.code, bt.name, bt.description
			FROM business_types bt
			WHERE bt.is_system = 1
			ORDER BY bt.id ASC
		");
		$stmt->execute();
		$types = $stmt->fetchAll(PDO::FETCH_ASSOC);

		foreach ($types as &$type) {
			$modStmt = $this->db->prepare("
				SELECT m.code, m.name
				FROM business_type_modules btm
				JOIN modules m ON m.id = btm.module_id
				WHERE btm.business_type_id = :type_id
				ORDER BY m.name ASC
			");
			$modStmt->execute(['type_id' => $type['id']]);
			$type['modules'] = $modStmt->fetchAll(PDO::FETCH_ASSOC);
		}

		return $types;
	}

	/**
	 * Find all system types (for use as copy templates).
	 *
	 * @return array Array of system business types with their module lists
	 */
	public function findSystemTypes(): array {
		$stmt = $this->db->query("
			SELECT id, tenant_id, code, name, description, is_system, is_default, created_at
			FROM business_types
			WHERE is_system = 1
			ORDER BY id ASC
		");
		$types = $stmt->fetchAll(PDO::FETCH_ASSOC);

		foreach ($types as &$type) {
			$type['modules'] = $this->getModulesForType((int) $type['id']);
		}

		return $types;
	}

	/**
	 * Check if a business type has a specific module by code.
	 *
	 * @param int $businessTypeId The business type ID
	 * @param string $moduleCode The module code to check
	 * @return bool True if the type has the module
	 */
	public function hasModule(int $businessTypeId, string $moduleCode): bool {
		$stmt = $this->db->prepare("
			SELECT COUNT(*)
			FROM business_type_modules btm
			INNER JOIN modules m ON m.id = btm.module_id
			WHERE btm.business_type_id = :type_id AND m.code = :module_code
		");
		$stmt->execute([
			'type_id'     => $businessTypeId,
			'module_code' => $moduleCode,
		]);
		return (int) $stmt->fetchColumn() > 0;
	}

	/**
	 * Get modules for a business type with full details (id, code, name).
	 *
	 * @param int $businessTypeId The business type ID
	 * @return array Array of module details
	 */
	public function getModulesForType(int $businessTypeId): array {
		$stmt = $this->db->prepare("
			SELECT m.id, m.code, m.name
			FROM business_type_modules btm
			INNER JOIN modules m ON m.id = btm.module_id
			WHERE btm.business_type_id = :business_type_id
			ORDER BY m.sort_order ASC
		");
		$stmt->execute(['business_type_id' => $businessTypeId]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Get the business_type_id for a given business.
	 *
	 * @param int $businessId The business ID
	 * @return int|null The business type ID or null if not found
	 */
	public function getBusinessTypeId(int $businessId): ?int {
		$stmt = $this->db->prepare("SELECT business_type_id FROM businesses WHERE id = :id");
		$stmt->execute(['id' => $businessId]);
		$result = $stmt->fetchColumn();
		return $result !== false ? (int) $result : null;
	}

	/**
	 * Delete a tenant-owned business type.
	 * Guards against deleting system types and types with assigned businesses.
	 *
	 * @param int $businessTypeId The business type ID to delete
	 * @param int $tenantId The tenant context
	 * @throws \RuntimeException If the type is system, not found, or has businesses
	 */
	public function deleteTenantType(int $businessTypeId, int $tenantId): void {
		$type = $this->getById($businessTypeId);
		if (!$type) {
			throw new \RuntimeException('Business type not found');
		}
		if ($type['is_system']) {
			throw new \RuntimeException('Cannot delete a system type');
		}
		if ((int)$type['tenant_id'] !== $tenantId) {
			throw new \RuntimeException('Business type not found');
		}

		// Check if any businesses are assigned
		$stmt = $this->db->prepare("SELECT COUNT(*) FROM businesses WHERE business_type_id = :type_id");
		$stmt->execute(['type_id' => $businessTypeId]);
		$count = (int) $stmt->fetchColumn();
		if ($count > 0) {
			throw new \RuntimeException("Cannot delete: type is assigned to {$count} business(es)");
		}

		$this->db->beginTransaction();
		try {
			// Delete module associations (CASCADE should handle this, but be explicit)
			$stmt = $this->db->prepare("DELETE FROM business_type_modules WHERE business_type_id = :type_id");
			$stmt->execute(['type_id' => $businessTypeId]);

			// Delete the type
			$stmt = $this->db->prepare("DELETE FROM business_types WHERE id = :id AND tenant_id = :tenant_id");
			$stmt->execute(['id' => $businessTypeId, 'tenant_id' => $tenantId]);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
