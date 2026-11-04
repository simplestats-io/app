<?php

namespace App\Http\Controllers;

use App\Http\DataProviders\RevenueDataProvider;
use App\Http\DataProviders\StatsGroupsDataProvider;
use App\Http\DataProviders\StatsIndexDataProvider;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StatsController extends Controller
{
    public function index(StatsIndexDataProvider $dataProvider)
    {
        return Inertia::render('Dashboard/Index', $dataProvider->toArray());
    }

    public function metric(StatsIndexDataProvider $dataProvider, string $kpi)
    {
        return Inertia::render('Dashboard/Metric', array_merge($dataProvider->toArray(), [
            'kpi' => $kpi,
        ]));
    }

    public function revenue(RevenueDataProvider $dataProvider)
    {
        return Inertia::render('Revenue/Index', $dataProvider->toArray());
    }

    public function groups(StatsGroupsDataProvider $dataProvider): array
    {
        return $dataProvider->toArray();
    }

    /**
     * @return array{hasData: bool}
     */
    public function status(Request $request): array
    {
        /** @var Team $team */
        $team = $request->user()->currentTeam;

        return ['hasData' => $team->hasReceivedData()];
    }

    public function hideFreePlanWarning(Request $request): RedirectResponse
    {
        $request->user()->updateSettings('hideFreePlanWarning', true);

        return back();
    }
}
