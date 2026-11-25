<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LifecycleMailOptOutController extends Controller
{
    public function create(Request $request, User $user): View
    {
        return view('lifecycle-mails.opt-out', [
            'optedOut' => $user->lifecycle_mails_opted_out_at !== null,
            'action' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, User $user): RedirectResponse
    {
        if ($user->lifecycle_mails_opted_out_at === null) {
            $user->touch('lifecycle_mails_opted_out_at');
        }

        return redirect()->to($request->fullUrl());
    }
}
