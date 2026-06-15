<?php
namespace App\Repository;

use PDO;

class ModuleRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /** Get all modules ordered by sort_order ASC */
    public function getAll(): array {
        $stmt = $this->db->prepare("
            SELECT id, code, name, icon, route_prefix, permission_prefixes, category, sort_order, created_at
            FROM modules
            ORDER BY sort_order ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Get a single module by its unique code, or null if not found */
    public function getByCode(string $code): ?array {
        $stmt = $this->db->prepare("
            SELECT id, code, name, icon, route_prefix, permission_prefixes, category, sort_order, created_at
            FROM modules
            WHERE code = :code
        ");
        $stmt->execute(['code' => $code]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** Get a module by ID */
    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT id, code, name, icon, route_prefix, permission_prefixes, category, sort_order, created_at
            FROM modules
            WHERE id = :id
        ");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /** Get modules for a business type (via junction table) */
    public function getModulesForBusinessType(int $businessTypeId): array {
        $stmt = $this->db->prepare("
            SELECT m.id, m.code, m.name, m.icon, m.route_prefix, m.permission_prefixes, m.category, m.sort_order, m.created_at
            FROM modules m
            INNER JOIN business_type_modules btm ON btm.module_id = m.id
            WHERE btm.business_type_id = :business_type_id
            ORDER BY m.sort_order ASC
        ");
        $stmt->execute(['business_type_id' => $businessTypeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Get the route_prefix → module_code mapping for all modules */
    public function getRoutePrefixMap(): array {
        $stmt = $this->db->prepare("
            SELECT route_prefix, code
            FROM modules
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[$row['route_prefix']] = $row['code'];
        }
        return $map;
    }

    /** Get all permission_prefixes grouped by module code */
    public function getPermissionPrefixMap(): array {
        $stmt = $this->db->prepare("
            SELECT code, permission_prefixes
            FROM modules
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $prefixes = json_decode($row['permission_prefixes'], true);
            $map[$row['code']] = is_array($prefixes) ? $prefixes : [];
        }
        return $map;
    }
}
