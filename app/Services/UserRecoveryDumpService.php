<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Reads every row belonging to a single user from the *current default* database
 * connection (locally pointed at a restored backup DB) and renders it as an
 * ordered, executable PostgreSQL INSERT script that can be replayed on production.
 *
 * Original primary keys are preserved verbatim: a previously hard-deleted
 * user/team/project leaves its IDs below the live Postgres sequence value, so the
 * sequences never hand them out again and re-inserting them cannot collide. That
 * lets the whole FK graph be restored 1:1 without remapping.
 *
 * Reading happens through the query builder (no Eloquent models) so HasTeamScope
 * and the StatsObserver never run; the generated raw SQL likewise bypasses them on
 * the import side.
 */
class UserRecoveryDumpService
{
    private const int CHUNK = 1000;

    /** Lookup/dimension tables (team-scoped only), inserted before the projects that reference them. */
    private const array DIMENSION_TABLES = [
        'stats_device_browsers',
        'stats_device_platforms',
        'stats_device_types',
        'stats_location_cities',
        'stats_location_countries',
        'stats_location_regions',
        'stats_page_entries',
        'stats_pages',
        'stats_track_campaigns',
        'stats_track_contents',
        'stats_track_media',
        'stats_track_referers',
        'stats_track_sources',
        'stats_track_terms',
    ];

    /**
     * @return array{sql: string, user_id: int, email: string, summary: array<string, int>}
     */
    public function dump(string $identifier, bool $withBilling = false, bool $onConflictDoNothing = false): array
    {
        $user = $this->resolveUser($identifier);

        $teamIds = DB::table('teams')->where('user_id', $user->id)->pluck('id')->all();
        $projectIds = DB::table('projects')->whereIn('team_id', $teamIds)->pluck('id')->all();

        $sections = $this->collectSections($user, $teamIds, $projectIds, $withBilling);

        return $this->render($user, $sections, $onConflictDoNothing);
    }

    private function resolveUser(string $identifier): object
    {
        $user = is_numeric($identifier)
            ? DB::table('users')->where('id', $identifier)->first()
            : DB::table('users')->where('email', $identifier)->first();

        if (! $user) {
            throw new RuntimeException("No user found for identifier [{$identifier}] in the current database.");
        }

        return $user;
    }

    /**
     * Ordered list of [table, rows, overrides] sections, parents before children.
     *
     * @param  array<int, int>  $teamIds
     * @param  array<int, int>  $projectIds
     * @return array<int, array{table: string, rows: Collection<int, object>, overrides: array<string, string>}>
     */
    private function collectSections(object $user, array $teamIds, array $projectIds, bool $withBilling): array
    {
        $byTeam = fn (string $table) => DB::table($table)->whereIn('team_id', $teamIds)->get();
        $byUser = fn (string $table) => DB::table($table)->where('user_id', $user->id)->get();

        $sections = [];

        // The restored user must not immediately re-enter the inactivity pipeline.
        $sections[] = $this->section('users', collect([$user]), [
            'last_login_at' => 'NOW()',
            'inactive_since' => 'NULL',
            'deleted_at' => 'NULL',
        ]);

        $sections[] = $this->section('teams', DB::table('teams')->whereIn('id', $teamIds)->get());

        // Memberships of owned teams (restores co-members too) plus this user's
        // memberships on foreign teams. Referenced foreign users/teams must already
        // exist on production.
        $sections[] = $this->section('team_user', DB::table('team_user')
            ->where(function (Builder $query) use ($teamIds, $user): void {
                $query->whereIn('team_id', $teamIds)->orWhere('user_id', $user->id);
            })->get());

        $sections[] = $this->section('team_invitations', $byTeam('team_invitations'));

        foreach (self::DIMENSION_TABLES as $table) {
            $sections[] = $this->section($table, $byTeam($table));
        }

        $sections[] = $this->section('projects', DB::table('projects')->whereIn('team_id', $teamIds)->get());

        $sections[] = $this->section('stats_custom_event_names', $byTeam('stats_custom_event_names'));
        $sections[] = $this->section('stats_custom_property_names', $byTeam('stats_custom_property_names'));
        $sections[] = $this->section('stats_custom_property_values', $byTeam('stats_custom_property_values'));

        $sections[] = $this->section('stats_visitors', $byTeam('stats_visitors'));
        $sections[] = $this->section('stats_visitor_views', $byTeam('stats_visitor_views'));
        $sections[] = $this->section('stats_visitor_pages', $byTeam('stats_visitor_pages'));
        $sections[] = $this->section('stats_users', $byTeam('stats_users'));
        $sections[] = $this->section('stats_logins', $byTeam('stats_logins'));
        $sections[] = $this->section('stats_payments', $byTeam('stats_payments'));
        $sections[] = $this->section('stats_custom_events', $byTeam('stats_custom_events'));
        $sections[] = $this->section('stats_custom_properties', $byTeam('stats_custom_properties'));

        $sections[] = $this->section('data_imports', $byTeam('data_imports'));
        $sections[] = $this->section('user_notifications', $byUser('user_notifications'));
        $sections[] = $this->section('custom_stats_range_presets', $byUser('custom_stats_range_presets'));
        $sections[] = $this->section('connected_accounts', $byUser('connected_accounts'));

        $userMorph = (new User)->getMorphClass();
        $projectMorph = (new Project)->getMorphClass();
        $sections[] = $this->section('personal_access_tokens', DB::table('personal_access_tokens')
            ->where(function (Builder $query) use ($userMorph, $user, $projectMorph, $projectIds): void {
                $query->where(fn (Builder $q) => $q->where('tokenable_type', $userMorph)->where('tokenable_id', $user->id))
                    ->orWhere(fn (Builder $q) => $q->where('tokenable_type', $projectMorph)->whereIn('tokenable_id', $projectIds));
            })->get());

        if ($withBilling) {
            $billableType = $userMorph;
            $billable = fn (string $table) => DB::table($table)
                ->where('billable_type', $billableType)->where('billable_id', $user->id)->get();

            $sections[] = $this->section('customers', $billable('customers'));

            $subscriptions = $billable('subscriptions');
            $sections[] = $this->section('subscriptions', $subscriptions);
            $sections[] = $this->section('subscription_items', DB::table('subscription_items')
                ->whereIn('subscription_id', $subscriptions->pluck('id')->all())->get());

            $sections[] = $this->section('transactions', $billable('transactions'));
        }

        return $sections;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  array<string, string>  $overrides
     * @return array{table: string, rows: Collection<int, object>, overrides: array<string, string>}
     */
    private function section(string $table, Collection $rows, array $overrides = []): array
    {
        return ['table' => $table, 'rows' => $rows, 'overrides' => $overrides];
    }

    /**
     * @param  array<int, array{table: string, rows: Collection<int, object>, overrides: array<string, string>}>  $sections
     * @return array{sql: string, user_id: int, email: string, summary: array<string, int>}
     */
    private function render(object $user, array $sections, bool $onConflictDoNothing): array
    {
        $summary = [];
        $body = [];

        foreach ($sections as $section) {
            $count = $section['rows']->count();
            $summary[$section['table']] = $count;

            if ($count === 0) {
                continue;
            }

            $body[] = $this->buildTableInserts(
                $section['table'],
                $section['rows'],
                $section['overrides'],
                $onConflictDoNothing,
            );
        }

        $header = implode("\n", [
            '-- SimpleStats user recovery dump',
            '-- User: '.$user->email.' (id '.$user->id.')',
            '-- Generated at: '.now()->toDateTimeString(),
            '-- Replay on production with: psql <db> -f '.basename($this->defaultFilename($user)),
            '',
            'BEGIN;',
            '',
        ]);

        $footer = implode("\n", [
            '',
            'COMMIT;',
            '',
            '-- After import, regenerate derived data (not included above):',
            '--   php artisan app:clear-old-stats-aggregations   (stats_aggregations is a regenerable cache)',
            '--   php artisan app:check-logins-and-payments-counter   (recompute counters / events_count_*)',
        ]);

        return [
            'sql' => $header.implode("\n", $body).$footer."\n",
            'user_id' => (int) $user->id,
            'email' => $user->email,
            'summary' => $summary,
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  array<string, string>  $overrides
     */
    private function buildTableInserts(string $table, Collection $rows, array $overrides, bool $onConflictDoNothing): string
    {
        $columns = Schema::getColumnListing($table);
        $columnSql = collect($columns)->map(fn ($c): string => $this->quoteIdentifier($c))->implode(', ');
        $suffix = $onConflictDoNothing ? ' ON CONFLICT DO NOTHING' : '';

        $statements = ["-- {$table} ({$rows->count()} rows)"];

        foreach ($rows->chunk(self::CHUNK) as $chunk) {
            $valueRows = $chunk->map(function (object $row) use ($columns, $overrides): string {
                $values = collect($columns)->map(fn (string $column): string => $this->literal($row->{$column} ?? null, $overrides[$column] ?? null))->implode(', ');

                return '    ('.$values.')';
            })->implode(",\n");

            $statements[] = 'INSERT INTO '.$this->quoteIdentifier($table)." ({$columnSql}) VALUES\n".$valueRows.$suffix.';';
        }

        return implode("\n", $statements)."\n";
    }

    /**
     * Renders a single value as a Postgres literal. Non-null scalars are emitted as
     * quoted text literals and rely on Postgres' implicit cast from the unknown type
     * into the target column (works for ints, timestamps, json and booleans alike);
     * booleans are pinned explicitly because PHP casts false to an empty string.
     */
    private function literal(mixed $value, ?string $override): string
    {
        if ($override !== null) {
            return $override;
        }

        if (is_null($value)) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? "'t'" : "'f'";
        }

        return "'".str_replace("'", "''", (string) $value)."'";
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    public function defaultFilename(object $user): string
    {
        return 'recovery-user-'.$user->id.'.sql';
    }
}
