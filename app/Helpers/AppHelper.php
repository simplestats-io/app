<?php

namespace App\Helpers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

class AppHelper
{
    public static function setTeamContextFromAuth(?Authenticatable $authModel): void
    {
        if ($authModel instanceof Authenticatable) {
            // important check first team_id then current_team_id
            // on api the user model is the project and else its normal user
            Context::add('team_id', $authModel->team_id ?? $authModel->current_team_id);
        }
    }

    public static function getTeamId(): ?int
    {
        return Context::get('team_id')
            ?? auth()->user()?->team_id
            ?? auth()->user()?->current_team_id;
    }

    /**
     * The project ids the authenticated user may access in the current team, or
     * null when nothing is restricted. API and MCP requests authenticate as a
     * Project (already limited to itself), console code without a user and
     * unrestricted members all resolve to null.
     *
     * Resolved from auth instead of Context on purpose: route model binding runs
     * before SetTeamContext, and console loops switch users via auth()->setUser().
     *
     * @return array<int, int>|null
     */
    public static function accessibleProjectIds(): ?array
    {
        $user = auth()->user();

        if (! $user instanceof User || ! ($teamId = self::getTeamId())) {
            return null;
        }

        return $user->accessibleProjectIds($teamId);
    }

    /**
     * Maps a public uuid route/filter value back to the internal id. Accepts
     * legacy numeric values as-is; unknown uuids resolve to 0 (= "all"), and
     * team scoping applies through the model's global scope.
     *
     * @param  class-string<Model>  $modelClass
     */
    public static function resolvePublicId(string $modelClass, mixed $value): int
    {
        if (is_string($value) && Str::isUuid($value)) {
            return (int) ($modelClass::where('uuid', $value)->value('id') ?? 0);
        }

        return (int) $value;
    }

    public static function ensureTeamTimezone(): void
    {
        $user = auth()->user();
        $teamTimezone = $user?->loadMissing('team')->team?->timezone ?: 'UTC';

        if (config('app.timezone') !== $teamTimezone) {
            Config::set('app.timezone', $teamTimezone);
            date_default_timezone_set($teamTimezone);
        }
    }

    public static function getDBDriver(): string
    {
        $connectionName = config('database.default');

        return config("database.connections.$connectionName.driver");
    }

    /**
     * A unique hash that identifies a visit on a given day.
     *
     * Must stay identical to SimpleStatsIo\LaravelClient\SimplestatsClient::createVisitorHash
     * so v2 clients (server-side fallback) and v3+ clients (payload) produce the same hash.
     */
    public static function createVisitorHash(?string $time, ?string $ip, ?string $userAgent): string
    {
        $time = CarbonImmutable::parse($time);
        $visitorHash = hash('sha256', $ip.$userAgent.$time->format('Y-m-d').config('app.key'));

        return substr($visitorHash, 0, 32);
    }
}
