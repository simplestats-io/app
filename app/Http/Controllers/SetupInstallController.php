<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class SetupInstallController extends Controller
{
    public function index(Request $request)
    {
        $project = $request->user()->currentTeam->projects()->first();

        return Inertia::render('Setup/Installation', [
            'apiToken' => $project->tokens->first()->token_plain_part,
            'domain' => $project->domain,
            'tracking' => $project->trackedUsageKeys(),
        ]);
    }
}
