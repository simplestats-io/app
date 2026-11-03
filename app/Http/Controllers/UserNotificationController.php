<?php

namespace App\Http\Controllers;

use App\Enums\UserNotificationFrequency;
use App\Http\DataProviders\UserNotificationIndexDataProvider;
use App\Http\Requests\UpdateUserNotificationRequest;
use App\Models\UserNotification;
use App\Notifications\ProjectReportNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

use function Illuminate\Support\defer;

class UserNotificationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(UserNotificationIndexDataProvider $dataProvider)
    {
        return Inertia::render('Notifications/Index', $dataProvider->toArray());
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserNotificationRequest $request, UserNotification $notification)
    {
        $notification->update($request->validated());

        return back()->with('flash', [
            'banner' => 'Setting updated',
        ]);
    }

    /**
     * Trigger the report manually.
     */
    public function trigger(Request $request, UserNotification $notification)
    {
        Gate::allowIf($notification->user()->is($request->user()));

        if ($notification->frequency === UserNotificationFrequency::DISABLED) {
            return back()->with('flash', [
                'bannerStyle' => 'danger',
                'banner' => 'You must choose a frequency to send a report.',
            ]);
        }

        if (! array_filter($notification->settings['tracking'] ?? [])) {
            return back()->with('flash', [
                'bannerStyle' => 'danger',
                'banner' => 'You must enable at least one tracking option to send a report.',
            ]);
        }

        $notification->load('user', 'notifiable');

        defer(fn () => $notification->user->notify(new ProjectReportNotification($notification, now(), auth()->user()->currentTeam)));

        return back()->with('flash', [
            'banner' => 'Report sent',
        ]);
    }
}
