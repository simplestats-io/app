<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('MarkdownPage', [
            'content' => Str::markdown(file_get_contents(resource_path('markdown/security.md'))),
            'meta' => [
                'title' => 'Security',
                'description' => 'How SimpleStats protects your analytics data: hosting in Germany, encryption, daily encrypted backups, access control and an open source code base.',
            ],
        ]);
    }
}
