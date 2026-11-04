<?php

use App\Http\Controllers\AdSpendController;
use App\Http\Controllers\AiChatController;
use App\Http\Controllers\AiConversationController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CurrentTeamController;
use App\Http\Controllers\CustomStatsRangePresetController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\DpaController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImpersonateController;
use App\Http\Controllers\ImprintController;
use App\Http\Controllers\LoginSummaryController;
use App\Http\Controllers\PowerUserController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SetupInstallController;
use App\Http\Controllers\SetupProjectController;
use App\Http\Controllers\SetupTeamController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\TeamAiSettingsController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\TimezoneController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\TrademarkController;
use App\Http\Controllers\UserNotificationController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\UserSettingsController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;
use Spatie\Health\Http\Controllers\HealthCheckResultsController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Localized public routes (registered FIRST, no route names to avoid overwriting English names)
$locales = collect(array_keys(config('locales.supported')))
    ->reject(fn ($l): bool => $l === config('locales.default'))
    ->implode('|');

$publicRoutes = function (): void {
    Route::get('/', HomeController::class);
    Route::get('/imprint', [ImprintController::class, 'show']);
};

Route::prefix('{locale}')
    ->where(['locale' => $locales])
    ->group(function () use ($publicRoutes): void {
        $publicRoutes();
    });

// Localized Fortify view routes (GET only, forms POST to English endpoints)
Route::prefix('{locale}')
    ->where(['locale' => $locales])
    ->middleware(['guest:web'])
    ->group(function (): void {
        Route::get('/login', [AuthenticatedSessionController::class, 'create']);
        Route::get('/register', [RegisteredUserController::class, 'create']);
        Route::get('/forgot-password', [PasswordResetLinkController::class, 'create']);
        Route::get('/reset-password/{token}', [NewPasswordController::class, 'create']);
    });

// English (default) public routes - registered LAST so named routes point to English
Route::get('/', HomeController::class)->name('home');
Route::get('/imprint', [ImprintController::class, 'show'])->name('imprint');
Route::get('/dpa', [DpaController::class, 'show'])->name('dpa');
Route::get('/trademark', [TrademarkController::class, 'show'])->name('trademark');

// Blog (not localized)
Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');
Route::permanentRedirect('/docs/api-documentation.html', '/docs/ingest-api.html');
Route::get('/docs/{path?}', DocsController::class)->where('path', '.*');
Route::resource('timezones', TimezoneController::class)->only(['index']);

// Tracking
Route::prefix('tracking')->name('tracking.')->group(function (): void {
    Route::post('get-started-click', [TrackingController::class, 'getStartedClick'])->name('get-started-click');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
])->group(function (): void {
    Route::get('/user/profile', [UserProfileController::class, 'show'])->name('profile.show');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function (): void {
    Route::middleware(['admin'])->group(function (): void {
        Route::get('health', HealthCheckResultsController::class);
    });

    Route::impersonate();
    Route::get('/impersonate/leave-custom', [ImpersonateController::class, 'leave'])->name('impersonate.leave.custom');

    Route::resource('setup/team', SetupTeamController::class)->only(['index', 'store'])->names('setup.team');
    Route::resource('setup/project', SetupProjectController::class)->only(['index', 'store'])->names('setup.project');
    Route::resource('setup/installation', SetupInstallController::class)->only(['index'])->names('setup.installation');

    Route::put('/current-team', [CurrentTeamController::class, 'update'])->name('current-team.update');
    // The {team} routes override their Jetstream counterparts, which cannot
    // resolve the public uuid route key (see TeamController/TeamMemberController).
    Route::get('/teams/{team}', [TeamController::class, 'show'])->name('teams.show');
    Route::put('/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
    Route::delete('/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
    Route::post('/teams/{team}/members', [TeamMemberController::class, 'store'])->name('team-members.store');
    Route::put('/teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('team-members.update');
    Route::delete('/teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('team-members.destroy');
    Route::put('/teams/{team}/ai-settings', [TeamAiSettingsController::class, 'update'])->name('teams.ai-settings.update');
    // Overrides the Jetstream accept route so the invitation's project access is applied.
    Route::get('/team-invitations/{invitation}', [TeamInvitationController::class, 'accept'])->middleware(['signed'])->name('team-invitations.accept');

    Route::get('dashboard', [StatsController::class, 'index'])->name('dashboard');
    Route::get('dashboard/metric/{kpi}', [StatsController::class, 'metric'])->name('dashboard.metric');
    Route::get('revenue', [StatsController::class, 'revenue'])->name('revenue');
    Route::get('retention', [StatsController::class, 'index'])->name('retention');
    Route::get('power-users', [PowerUserController::class, 'index'])->name('power-users');
    Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns');
    Route::post('campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
    Route::patch('campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
    Route::post('campaigns/{campaign}/track-campaigns', [CampaignController::class, 'linkTrackCampaign'])->name('campaigns.track-campaigns.link');
    Route::delete('campaigns/{campaign}/track-campaigns/{trackCampaign}', [CampaignController::class, 'unlinkTrackCampaign'])->name('campaigns.track-campaigns.unlink');
    Route::resource('ad-spends', AdSpendController::class)->only(['store', 'update', 'destroy']);
    Route::get('stats/grouped', [StatsController::class, 'groups'])->name('stats.grouped');
    Route::get('stats/status', [StatsController::class, 'status'])->name('stats.status');

    // AI chat assistant
    Route::post('ai/chat', [AiChatController::class, 'store'])->middleware('throttle:30,1')->name('ai.chat.store');
    Route::get('ai/conversations', [AiConversationController::class, 'index'])->name('ai.conversations.index');
    Route::get('ai/conversations/{conversation}', [AiConversationController::class, 'show'])->name('ai.conversations.show');
    Route::post('stats/hide-free-plan-warning', [StatsController::class, 'hideFreePlanWarning'])->name('stats.hide-free-plan-warning');

    Route::resource('custom-presets', CustomStatsRangePresetController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('custom-presets/builtin-visibility', [CustomStatsRangePresetController::class, 'updateBuiltinVisibility'])->name('custom-presets.builtin-visibility');

    // Data Import
    Route::resource('data-import', DataImportController::class)->only(['index', 'store']);
    Route::post('data-import/{data_import}/cancel', [DataImportController::class, 'cancel'])->name('data-import.cancel');
    Route::post('data-import/{data_import}/rollback', [DataImportController::class, 'rollback'])->name('data-import.rollback');

    // Projects
    Route::resource('projects', ProjectController::class)->except(['edit', 'create']);
    Route::get('projects/{project}/token-view', [ProjectController::class, 'tokenView'])->name('projects.token-view');
    Route::get('projects/{project}/token-regenerate', [ProjectController::class, 'tokenRegenerate'])->name('projects.token-regenerate');
    Route::put('projects/{project}/user-link-url', [ProjectController::class, 'updateUserLinkUrl'])->name('projects.user-link-url.update');

    // User Settings
    Route::post('user-settings/dashboard-preset', [UserSettingsController::class, 'setDashboardPreset'])->name('user-settings.set-dashboard-preset');
    Route::post('user-settings/complete-tour', [UserSettingsController::class, 'completeTour'])->name('user-settings.complete-tour');
    Route::post('user-settings/property-lifetime', [UserSettingsController::class, 'setPropertyLifetime'])->name('user-settings.property-lifetime');
    Route::post('user-settings/login-summary', [UserSettingsController::class, 'setLoginSummary'])->name('user-settings.login-summary');

    // Login Summary
    Route::get('login-summary', LoginSummaryController::class)->name('login-summary');

    // Feedback
    Route::post('feedback', FeedbackController::class)->name('feedback.store');

    // User Notification Settings
    Route::resource('user/notifications', UserNotificationController::class)->only(['index', 'update']);
    Route::post('user/notifications/{notification}/trigger', [UserNotificationController::class, 'trigger'])->name('notifications.trigger');
});

require __DIR__.'/socialstream.php';
