<?php

namespace App\Console\Commands;

use App\Models\StatsAggregation;
use App\Models\StatsLocationCity;
use App\Models\StatsLocationCountry;
use App\Models\StatsLocationRegion;
use App\Models\StatsUser;
use App\Models\StatsVisitor;
use App\Models\Team;
use App\Services\LocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class UnifyLocations extends Command
{
    protected $signature = 'app:unify-locations';

    protected $description = 'Merge country rows that differ only in the provider spelling and drop nameless region/city rows.';

    public function handle(LocationService $locationService): void
    {
        Team::query()->each(function (Team $team) use ($locationService): void {
            try {
                $changed = DB::transaction(function () use ($team, $locationService): bool {
                    $countriesChanged = $this->unifyCountries($team, $locationService);

                    return $this->dropNamelessRows($team) || $countriesChanged;
                });
            } catch (Throwable $e) {
                $this->error("Team {$team->id}: {$e->getMessage()}");

                return;
            }

            if ($changed) {
                StatsAggregation::withoutGlobalScopes()->where('team_id', $team->id)->delete();
                $this->info("Team {$team->id}: unified");
            }
        });
    }

    protected function unifyCountries(Team $team, LocationService $locationService): bool
    {
        $changed = false;

        StatsLocationCountry::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->get()
            ->each(function (StatsLocationCountry $country) use ($locationService, &$changed): void {
                if (! preg_match('/\(([A-Za-z]{2})\)$/', $country->name, $matches)) {
                    return;
                }

                $code = Str::upper($matches[1]);
                $canonical = $locationService->countryName($code)." ($code)";

                if ($canonical === $country->name) {
                    return;
                }

                $changed = true;

                $target = StatsLocationCountry::withoutGlobalScopes()
                    ->where('team_id', $country->team_id)
                    ->where('name', $canonical)
                    ->first();

                if (! $target) {
                    $country->forceFill(['name' => $canonical])->save();

                    return;
                }

                foreach ([StatsUser::class, StatsVisitor::class] as $statsModel) {
                    $statsModel::withoutGlobalScopes()
                        ->where('team_id', $country->team_id)
                        ->where('stats_location_country_id', $country->id)
                        ->update(['stats_location_country_id' => $target->id]);
                }

                $country->delete();
            });

        return $changed;
    }

    /**
     * Rows like " (NL)" stem from providers that returned no region/city name,
     * " ()" from ones that returned neither country name nor code. Deleting them
     * nulls the references (ON DELETE SET NULL), which is what a missing name
     * means anyway.
     */
    protected function dropNamelessRows(Team $team): bool
    {
        $deleted = StatsLocationCountry::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('name', ' ()')
            ->delete();

        foreach ([StatsLocationRegion::class, StatsLocationCity::class] as $model) {
            $deleted += $model::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('name', 'like', ' (%')
                ->delete();
        }

        return $deleted > 0;
    }
}
