<?php
namespace App\Support;

final class AuthContext {
    private static ?object $user = null;

    public static function set(object $user): void {
        self::$user = $user;
    }

    public static function get(): object {
        if (!self::$user) {
            throw new \RuntimeException('Auth context missing');
        }
        return self::$user;
    }

    public static function tenantId(): int {
        return self::get()->tenant_id;
    }

    public static function businessId(): int {
        return self::get()->business_id;
    }

    public static function userId(): int {
        return self::get()->user_id;
    }

    public static function businessIdOrNull(): ?int {
        $ctx = self::get();
        return $ctx->business_id ?? null;
    }

    public static function isTenantOwner(): bool {
        return self::$user && !empty(self::$user->is_tenant_owner);
    }
}
