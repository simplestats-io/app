<?php

namespace App\Services\Ai;

use App\Helpers\AppHelper;
use App\Models\DataImport;
use App\Models\Project;
use Illuminate\Support\Facades\Route;

class AppPageCatalog
{
    /**
     * Curated map of in-app pages the AI assistant may point users to. A
     * hand-picked catalog beats deriving from the route list: route names
     * carry no intent semantics and most routes are not navigable pages.
     * Entries are filtered by team tracking usage and plan options, so the
     * assistant never links a page the current team cannot see.
     *
     * @return array<int, array{title: string, description: string, url: string}>
     */
    public function pages(): array
    {
        $team = auth()->user()?->team;
        $teamUrl = route('teams.show', AppHelper::getTeamId());
        $planOptions = $team?->owner?->sparkPlan()->options ?? [];

        $pages = [
            [
                'title' => 'Dashboard',
                'description' => 'KPI overview with charts and the stats filter (project, date range, channel, location, device).',
                'url' => route('dashboard'),
            ],
            [
                'title' => 'Revenue',
                'description' => 'Recurring revenue: MRR/ARR, movements, churn, NRR/GRR and subscription economics.',
                'url' => route('revenue'),
                'when' => fn () => $team?->tracksUsage(Project::USAGE_SUBSCRIPTION) ?? false,
            ],
            [
                'title' => 'Retention',
                'description' => 'Registration retention cohorts, stickiness and engagement.',
                'url' => route('retention'),
                'when' => fn () => $team?->tracksUsage(Project::USAGE_USERS) ?? false,
            ],
            [
                'title' => 'Users',
                'description' => 'Most active and highest paying users (power users).',
                'url' => route('power-users'),
                'when' => fn () => $team?->tracksUsage(Project::USAGE_USERS) ?? false,
            ],
            [
                'title' => 'Campaigns',
                'description' => 'Campaign performance, ad spend, ROAS and CAC.',
                'url' => route('campaigns'),
                'when' => fn () => $team?->tracksUsage(Project::USAGE_PAYMENTS) ?? false,
            ],
            [
                'title' => 'Projects',
                'description' => 'List of all projects. Create a new project here; open a project to edit its settings, tracking configuration and API token, or to connect Google Search Console (Google search keywords on the dashboard).',
                'url' => route('projects.index'),
            ],
            [
                'title' => 'Team settings',
                'description' => 'Team name, currency and timezone.',
                'url' => $teamUrl.'#team-details',
            ],
            [
                'title' => 'Team members',
                'description' => 'Invite co-workers to the team and manage their roles and permissions.',
                'url' => $teamUrl.'#team-members',
            ],
            [
                'title' => 'Event usage',
                'description' => 'Monthly event usage per project against the plan limit.',
                'url' => $teamUrl.'#event-usage',
            ],
            [
                'title' => 'AI settings',
                'description' => 'Bring your own AI provider and API key for this assistant.',
                'url' => $teamUrl.'#ai-assistant',
                'when' => fn (): bool => (bool) ($planOptions['AiAssistant'] ?? false),
            ],
            [
                'title' => 'Data import',
                'description' => 'Import existing visitors, users and payments from CSV files.',
                'url' => route('data-import.index'),
                'when' => fn (): bool => (bool) ($planOptions[DataImport::class] ?? false),
            ],
            [
                'title' => 'Billing',
                'description' => 'Subscription plan, upgrades and invoices.',
                'url' => Route::has('spark.portal') ? route('spark.portal') : '',
                'when' => fn () => Route::has('spark.portal'),
            ],
            [
                'title' => 'Profile',
                'description' => 'Personal profile: name, email, password, two-factor authentication, browser sessions.',
                'url' => route('profile.show'),
            ],
            [
                'title' => 'Notifications',
                'description' => 'Notification settings, e.g. scheduled report emails.',
                'url' => route('notifications.index'),
            ],
            [
                'title' => 'Documentation',
                'description' => 'Full documentation: installation, tracking setup, API and MCP server.',
                'url' => url('docs/introduction'),
            ],
        ];

        return collect($pages)
            ->filter(fn (array $page): bool => ! isset($page['when']) || $page['when']())
            ->map(fn (array $page) => collect($page)->except('when')->all())
            ->values()
            ->all();
    }
}
