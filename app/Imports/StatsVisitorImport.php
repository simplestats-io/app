<?php

namespace App\Imports;

use App\Models\DataImport;
use App\Models\StatsVisitor;
use App\Validators\StatsVisitorValidator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\WithSkipDuplicates;

/**
 * Skips duplicates instead of upserting: a visitor_hash that is already known (live-tracked
 * or imported before) keeps its row, so a re-import never rewrites live data.
 */
class StatsVisitorImport extends BaseStatsImport implements WithSkipDuplicates
{
    public function __construct(DataImport $dataImport)
    {
        parent::__construct(
            $dataImport,
            StatsVisitor::class,
            resolve(StatsVisitorValidator::class)
        );
    }

    /**
     * Custom properties are left out: their tables are written per model event, which batch
     * inserts skip. The location/device columns are import-only, the API derives them from ip/user_agent.
     */
    public function rules(): array
    {
        return array_merge(Arr::except(parent::rules(), ['properties', 'properties.*', 'client_version']), [
            'location_region' => ['nullable', 'string'],
            'location_city' => ['nullable', 'string'],
            'device_type' => ['nullable', 'string'],
            'device_platform' => ['nullable', 'string'],
            'device_browser' => ['nullable', 'string'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function model(array $row)
    {
        // without a hash the visitor cannot get page views, but is still counted
        if (blank($row['visitor_hash'] ?? null)) {
            $row['visitor_hash'] = Str::random(32);
        }

        return parent::model($row);
    }
}
