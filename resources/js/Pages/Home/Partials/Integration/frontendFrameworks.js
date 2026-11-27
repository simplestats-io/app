import LaravelLogo from "@/Pages/Home/Partials/Integration/Logos/laravel.svg?component";
import LivewireLogo from "@/Pages/Home/Partials/Integration/Logos/livewire.svg?component";
import InertiaLogo from "@/Pages/Home/Partials/Integration/Logos/inertia.svg?component";
import NextjsLogo from "@/Pages/Home/Partials/Integration/Logos/nextjs.svg?component";
import NuxtLogo from "@/Pages/Home/Partials/Integration/Logos/nuxt.svg?component";
import AstroLogo from "@/Pages/Home/Partials/Integration/Logos/astro.svg?component";
import WebflowLogo from "@/Pages/Home/Partials/Integration/Logos/webflow.svg?component";
import FramerLogo from "@/Pages/Home/Partials/Integration/Logos/framer.svg?component";
import WordpressLogo from "@/Pages/Home/Partials/Integration/Logos/wordpress.svg?component";

export const frontendFrameworksDocs = '/docs/frontend-frameworks.html';

export const frontendFrameworks = [
    { name: 'Blade', logo: LaravelLogo, docs: frontendFrameworksDocs },
    { name: 'Livewire', logo: LivewireLogo, docs: frontendFrameworksDocs },
    { name: 'Inertia', logo: InertiaLogo, docs: frontendFrameworksDocs },
    { name: 'Next.js', logo: NextjsLogo, docs: '/docs/nextjs.html' },
    { name: 'Nuxt', logo: NuxtLogo, docs: '/docs/nuxt.html' },
    { name: 'Astro', logo: AstroLogo, docs: '/docs/astro.html' },
    { name: 'Webflow', logo: WebflowLogo, docs: '/docs/webflow.html' },
    { name: 'Framer', logo: FramerLogo, docs: '/docs/framer.html' },
    { name: 'WordPress', logo: WordpressLogo, docs: '/docs/wordpress.html' },
];
