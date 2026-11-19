<?php

namespace App\Http\Controllers;

use App\Services\ChangelogService;
use Inertia\Inertia;
use Inertia\Response;

class ChangelogController extends Controller
{
    public function index(ChangelogService $changelog): Response
    {
        return Inertia::render('Changelog', [
            'releases' => $changelog->releases(),
        ]);
    }
}
