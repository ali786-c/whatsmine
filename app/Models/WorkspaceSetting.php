<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-workspace settings stored in the system_settings table with a
 * "ws:{workspaceId}:{key}" key convention. Avoids a dedicated table for the
 * handful of workspace-level switches the platform needs.
 */
class WorkspaceSetting extends Model
{
    public static function key(int $workspaceId, string $key): string
    {
        return "ws:{$workspaceId}:{$key}";
    }

    public static function get(int $workspaceId, string $key, $default = null)
    {
        return SystemSetting::get(self::key($workspaceId, $key), $default);
    }

    public static function set(int $workspaceId, string $key, $value, ?string $group = null): void
    {
        SystemSetting::set(self::key($workspaceId, $key), $value, false, $group ?? 'workspace');
    }

    public static function flag(int $workspaceId, string $key, bool $default = false): bool
    {
        $raw = self::get($workspaceId, $key);

        return $raw === null ? $default : in_array($raw, [true, 1, '1', 'true', 'on'], true);
    }
}
