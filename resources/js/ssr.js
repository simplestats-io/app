import '../css/app.css';

import { createInertiaApp } from "@inertiajs/vue3";
import { renderToString } from "@vue/server-renderer";
import createServer from "@inertiajs/vue3/server";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import { ZiggyVue } from "ziggy-js";
import { createSSRApp, h } from "vue";
import { i18nVue } from "laravel-vue-i18n";
import { vTooltip } from 'floating-vue';

createServer((page) => {
    let pageApp;
    let pageHtml;

    // Inertia 2.x embeds render(pageApp) synchronously as innerHTML. Since Vue 3.5.44 stringifies
    // innerHTML, the async renderToString would end up as "[object Promise]". So the page app is
    // rendered upfront and its finished HTML is handed back for the inner call.
    const render = (app) => {
        if (app === pageApp) {
            return pageHtml;
        }

        return renderToString(pageApp).then((html) => {
            pageHtml = html;

            return renderToString(app);
        });
    };

    return createInertiaApp({
        page,
        render,
        title: (title) => (title ? `${import.meta.env.VITE_APP_NAME} - ${title}` : ''),
        resolve: (name) => {
            if (name.startsWith("Home") || name.startsWith("Auth")) {
                return resolvePageComponent(
                    `./Pages/${name}.vue`,
                    import.meta.glob("./Pages/**/*.vue")
                );
            }

            // Fallback: return minimal component, client-side rendering takes over
            return { default: { render: () => '' } };
        },
        setup({ App, props, plugin }) {
            pageApp = createSSRApp({ render: () => h(App, props) });

            return pageApp
                .use(plugin)
                .use(ZiggyVue, props.initialPage.props.ziggy)
                .directive('tooltip', vTooltip)
                .use(i18nVue, {
                    shared: false,
                    resolve: (lang) => {
                        const langs = import.meta.glob("../../lang/*.json", { eager: true });
                        return langs[`../../lang/${lang}.json`].default;
                    },
                    lang: props.initialPage.props.locale || "en",
                    fallbackLang: "en",
                });
        },
    });
});
