import './bootstrap';
import '../css/app.css';

import { createSSRApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import { i18nVue, I18n } from 'laravel-vue-i18n';
import { initTawk } from './tawk';
let appName = import.meta.env.VITE_APP_NAME;

function whenIdle(callback) {
    const schedule = () => {
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(callback, { timeout: 3000 });
        } else {
            setTimeout(callback, 200);
        }
    };

    if (document.readyState === 'complete') {
        schedule();
    } else {
        window.addEventListener('load', schedule, { once: true });
    }
}

// floating-vue mounts a second Vue app for every page with a tooltip, which ran inside the
// hydration task. Tooltips only matter on hover, so the library is fetched and bound once
// the browser is idle. Bindings that arrive before that are queued per element.
let tooltipDirective = null;
let tooltipLoadScheduled = false;
const pendingTooltips = new Map();

function loadTooltipDirective() {
    if (tooltipLoadScheduled) {
        return;
    }
    tooltipLoadScheduled = true;

    whenIdle(() => {
        import('floating-vue')
            .then(({ vTooltip }) => {
                tooltipDirective = vTooltip;
                pendingTooltips.forEach((binding, el) => vTooltip.beforeMount(el, binding));
                pendingTooltips.clear();
            })
            .catch(() => {
                // A stale chunk after a deploy must not disable tooltips until reload.
                tooltipLoadScheduled = false;
            });
    });
}

const lazyTooltip = {
    beforeMount(el, binding) {
        if (tooltipDirective) {
            tooltipDirective.beforeMount(el, binding);
        } else {
            pendingTooltips.set(el, binding);
            loadTooltipDirective();
        }
    },
    updated(el, binding) {
        if (tooltipDirective) {
            tooltipDirective.updated(el, binding);
        } else {
            pendingTooltips.set(el, binding);
        }
    },
    beforeUnmount(el, binding) {
        if (tooltipDirective) {
            tooltipDirective.beforeUnmount(el, binding);
        } else {
            pendingTooltips.delete(el);
        }
    },
};

// VITE_APP_ENV is read at build time, and a build that does not come from a local
// .env file does not have it. Without the first check the prefix is the string
// "undefined" and every page title reads "undefined SimpleStats - Dashboard".
if (import.meta.env.VITE_APP_ENV && import.meta.env.VITE_APP_ENV !== 'production') {
    appName = import.meta.env.VITE_APP_ENV +' '+import.meta.env.VITE_APP_NAME;
}

createInertiaApp({
    title: (title) => `${appName} - ${title}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const app = createSSRApp({ render: () => h(App, props) });

        app
            .use(plugin)
            .use(ZiggyVue)
            .use(i18nVue, {
                resolve: async (lang) => {
                    const langs = import.meta.glob('../../lang/*.json');
                    return await langs[`../../lang/${lang}.json`]();
                },
                lang: props.initialPage.props.locale || 'en',
                fallbackLang: 'en',
            })
            .directive('tooltip', lazyTooltip);

        const translations = props.initialPage.props.translations;
        if (translations) {
            const locale = props.initialPage.props.locale || 'en';
            const i18n = I18n.getSharedInstance();
            I18n.loaded.push({ lang: locale, messages: translations });
            for (const [key, value] of Object.entries(translations)) {
                i18n.activeMessages[key] = value;
            }
        }

        app.mount(el);

        initTawk(props.initialPage.component);

        if (import.meta.env.VITE_SENTRY_DSN_PUBLIC) {
            // Only the landing page defers the SDK so it does not compete with hydration
            // (early errors there are not reported); app pages keep reporting load errors.
            // The destructured import lets Rollup tree-shake the SDK namespace (about 80 KB instead of 320 KB).
            const runSentry = props.initialPage.component === 'Home/LandingPage' ? whenIdle : (callback) => callback();

            runSentry(() => {
                import('@sentry/vue').then(({ init }) => {
                    init({
                        app,
                        dsn: import.meta.env.VITE_SENTRY_DSN_PUBLIC,
                        environment: import.meta.env.VITE_APP_ENV,
                        // Drop noise from the third-party Tawk.to chat widget. These errors
                        // originate in its bundled scripts (twk-chunk-*), not in our code, and
                        // can never be fixed on our side, so they should not consume quota.
                        ignoreErrors: [
                            'BufferLoader: XHR error for undefined',
                            /\$_Tawk/i,
                            'i18next is not a function',
                            // Microsoft's link scanner (Outlook Safe Links) rejects with this in its headless browser.
                            /Object Not Found Matching Id:\d+, MethodName:\w+, ParamCount:\d+/,
                        ],
                        denyUrls: [/tawk\.to/i, /twk-chunk/i],
                    });
                });
            });
        }

        return app;
    },
    progress: {
        color: '#4B5563',
    },
});
