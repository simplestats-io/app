import {defineConfig} from 'vitepress'

// https://vitepress.dev/reference/site-config
export default defineConfig({
    base: '/docs/',
    title: "SimpleStats",
    description: "Revenue attribution analytics for Laravel apps",
    head: [
        ['link', { rel: 'icon', href: '/favicon.ico' }],
    ],
    transformPageData(pageData) {
        const canonicalUrl = `https://simplestats.io/docs/${pageData.relativePath}`
            .replace(/index\.md$/, '')
            .replace(/\.md$/, '.html')

        pageData.frontmatter.head ??= []
        pageData.frontmatter.head.push([
            'link',
            { rel: 'canonical', href: canonicalUrl }
        ])
    },
    themeConfig: {
        logo: '../svg/app_logo.svg',
        logoLink: { link: '/', target: '_self' },
        search: {
            provider: 'local'
        },
        outline: 'deep',
        nav: [
            { text: 'Help', link: 'https://tawk.to/chat/6601f04ea0c6737bd124880f/1hprp5d8g' },
            { text: 'Demo', link: 'https://demo.simplestats.io' },
        ],
        socialLinks: [
            { icon: 'github', link: 'https://github.com/simplestats-io/laravel-client' },
            { icon: 'twitter', link: 'https://twitter.com/SimpleStats_io' },
        ],
        sidebar: [
            {
                text: 'Getting Started',
                collapsed: false,
                items: [
                    {text: 'Introduction', link: '/introduction'},
                    {text: 'Installation', link: '/installation'},
                ]
            },
            {
                text: 'Tracking',
                collapsed: false,
                items: [
                    {text: 'Visitors', link: '/how-to-track-a-new-visitor'},
                    {text: 'Page Views', link: '/how-to-track-page-views'},
                    {text: 'Users', link: '/how-to-track-a-new-user'},
                    {text: 'Logins', link: '/how-to-track-a-new-login'},
                    {text: 'Payments', link: '/how-to-track-a-new-payment'},
                    {text: 'Payment Providers', link: '/payment-providers'},
                    {text: 'Funnel Attribution', link: '/from-visitor-to-user-to-revenue'},
                ]
            },
            {
                text: 'Advanced Tracking',
                collapsed: false,
                items: [
                    {text: 'Custom Events', link: '/how-to-track-a-custom-event'},
                    {text: 'Custom Properties', link: '/how-to-track-custom-properties'},
                    {text: 'Update a Payment', link: '/how-to-update-a-payment'},
                    {text: 'Stripe Without a Payment Model', link: '/how-to-track-stripe-payments-without-a-model'},
                    {text: 'Import Existing Data', link: '/how-to-import-existing-data'},
                    {text: 'Tracking Codes', link: '/tracking-codes'},
                ]
            },
            {
                text: 'Configuration',
                collapsed: false,
                items: [
                    {text: 'Queue', link: '/queue'},
                    {text: 'Bot Detection', link: '/bot-detection'},
                    {text: 'Headless, SPA & Stateless', link: '/headless-stateless-spa'},
                    {text: 'Static Caching & CDNs', link: '/static-caching'},
                ]
            },
            {
                text: 'Integrations',
                collapsed: false,
                items: [
                    {text: 'Filament', link: '/filament-plugin'},
                    {text: 'Statamic', link: '/statamic-addon'},
                    {text: 'PHP Client', link: '/php-client'},
                ]
            },
            {
                text: 'Self-Hosted',
                collapsed: false,
                items: [
                    {text: 'Overview', link: '/self-hosted'},
                ]
            },
            {
                text: 'Resources',
                collapsed: false,
                items: [
                    {text: 'KPIs Reference', link: '/kpis'},
                    {text: 'Plans and Limits', link: '/plans-and-limits'},
                    {text: 'Counting Visitors', link: '/counting-visitors'},
                    {text: 'Counting Page Views', link: '/counting-page-views'},
                    {text: 'Compliance & Security', link: 'https://simplestats.io/compliance'},
                    {text: 'Troubleshooting', link: '/troubleshooting'},
                    {text: 'Contribution', link: '/contribution'},
                ]
            },
            {
                text: 'API',
                collapsed: false,
                items: [
                    {text: 'Ingest API', link: '/ingest-api'},
                    {text: 'Query API', link: '/query-api'},
                    {text: 'MCP Server', link: '/mcp-server'},
                ]
            },
        ],
    }
})
