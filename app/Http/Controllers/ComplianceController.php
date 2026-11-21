<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ComplianceController extends Controller
{
    /** @var array<string, array{title: string, description: string}> */
    public const array PAGES = [
        'index' => [
            'title' => 'Compliance',
            'description' => 'GDPR, CCPA and PECR compliant analytics without a cookie banner. An overview of how SimpleStats handles personal data, for you and your legal team.',
        ],
        'gdpr' => [
            'title' => 'GDPR Compliance',
            'description' => 'How SimpleStats works under the GDPR and the ePrivacy Directive: no cookies, no stored IP addresses, EU hosting and a DPA that applies automatically.',
        ],
        'ccpa' => [
            'title' => 'CCPA Compliance',
            'description' => 'How SimpleStats works under the CCPA and CPRA: no selling or sharing of personal information, service provider terms and no cross-site tracking.',
        ],
        'pecr' => [
            'title' => 'PECR Compliance',
            'description' => 'How SimpleStats works under the UK PECR and UK GDPR: no cookies and nothing stored on the visitor device, so no cookie banner for analytics.',
        ],
    ];

    public function show(string $page = 'index'): Response
    {
        return Inertia::render('MarkdownPage', [
            'content' => Str::markdown(file_get_contents(resource_path("markdown/compliance/{$page}.md"))),
            'meta' => self::PAGES[$page],
        ]);
    }
}
