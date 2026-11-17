<script setup>
import {nextTick, onBeforeUnmount, onMounted, ref} from 'vue';
import PlayIcon from 'bootstrap-icons/icons/play-fill.svg?component';
import CloseIcon from 'bootstrap-icons/icons/x-lg.svg?component';

// Hosted in storage (not in git) to keep the repo small.
const VIDEO_URL = '/storage/simplestats-promo.mp4';

const screens = [
    { key: 'dashboard', label: 'Dashboard', alt: 'Web analytics dashboard for Laravel apps', top: '14.8%' },
    { key: 'revenue', label: 'Revenue', alt: 'MRR and revenue analytics for Laravel apps', top: '21.2%' },
    { key: 'retention', label: 'Retention', alt: 'Cohort retention analytics for Laravel apps', top: '27.6%' },
    { key: 'users', label: 'Users', alt: 'Power user analytics for Laravel apps', top: '34.0%' },
    { key: 'campaigns', label: 'Campaigns', alt: 'Campaign ROI analytics for Laravel apps', top: '40.3%' },
];

const themes = [
    { name: 'light', class: 'block dark:hidden' },
    { name: 'dark', class: 'hidden dark:block' },
];

const activeScreen = ref('dashboard');
const videoOpen = ref(false);
const video = ref(null);

async function openVideo() {
    videoOpen.value = true;
    await nextTick();
    video.value?.play();
}

function closeVideo() {
    video.value?.pause();
    videoOpen.value = false;
}

function onKeydown(event) {
    if (event.key === 'Escape' && videoOpen.value) {
        closeVideo();
    }
}

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

// The section is full width minus px-4, capped by max-w-screen-xl.
const SCREENSHOT_SIZES = '(min-width: 1312px) 1280px, calc(100vw - 2rem)';

function screenshotPath(key, theme) {
    return `/images/screenshots/screenshot_${key}_${theme}.webp`;
}

function screenshotSrcset(key, theme) {
    const base = `/images/screenshots/screenshot_${key}_${theme}`;

    return `${base}_768w.webp 768w, ${base}_1280w.webp 1280w, ${base}.webp 2560w`;
}

// The theme is only known in the browser (dark_mode.js), so SSR cannot pick the right
// variant. Light gets the priority; the dark one stays lazy, which keeps it from being
// fetched while it is display:none in light mode.
function isLcpImage(screen, theme) {
    return screen.key === 'dashboard' && theme.name === 'light';
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);

    // Warm the cache for the other screens once the browser is idle, so the
    // first hotspot click doesn't show a blank frame while the image loads.
    const preloadScreens = () => {
        const theme = document.documentElement.classList.contains('dark') ? 'dark' : 'light';

        screens
            .filter((screen) => screen.key !== activeScreen.value)
            .forEach((screen) => {
                const image = new Image();
                image.sizes = SCREENSHOT_SIZES;
                image.srcset = screenshotSrcset(screen.key, theme);
            });
    };

    if ('requestIdleCallback' in window) {
        requestIdleCallback(preloadScreens);
    } else {
        setTimeout(preloadScreens, 2000);
    }
});
</script>

<template>
    <section class="animationElement mt-20 flex justify-center px-4">
        <div class="w-full max-w-screen-xl">
            <div class="relative">
                <template v-for="screen in screens" :key="screen.key">
                    <img
                        v-for="theme in themes"
                        v-show="screen.key === activeScreen"
                        :key="`${screen.key}-${theme.name}`"
                        :class="theme.class"
                        width="1280"
                        height="832"
                        :src="screenshotPath(screen.key, theme.name)"
                        :srcset="screenshotSrcset(screen.key, theme.name)"
                        :sizes="SCREENSHOT_SIZES"
                        :alt="screen.alt"
                        :loading="isLcpImage(screen, theme) ? 'eager' : 'lazy'"
                        :fetchpriority="isLcpImage(screen, theme) ? 'high' : 'low'"
                        decoding="async"
                    >
                </template>
                <button
                    v-for="screen in screens"
                    v-show="screen.key !== activeScreen && !videoOpen"
                    :key="`hotspot-${screen.key}`"
                    class="absolute hidden -translate-x-1/2 -translate-y-1/2 lg:block"
                    :style="{ top: screen.top, left: '6.65%' }"
                    :aria-label="`Show ${screen.label} screenshot`"
                    @click="activeScreen = screen.key"
                >
                    <span class="relative flex size-3">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-indigo-300 opacity-75"></span>
                        <span class="relative inline-flex size-3 rounded-full bg-indigo-400"></span>
                    </span>
                </button>

                <!-- Below 420px the five labels don't fit in one row, so the tab bar is hidden entirely. -->
                <div v-show="!videoOpen" class="absolute inset-x-0 bottom-3 hidden justify-center px-3 min-[420px]:flex lg:hidden">
                    <div class="flex max-w-full justify-center gap-1.5 rounded-full border border-gray-200/70 bg-white/80 p-1.5 shadow-lg backdrop-blur dark:border-gray-700/70 dark:bg-gray-900/80">
                        <button
                            v-for="screen in screens"
                            :key="`tab-${screen.key}`"
                            type="button"
                            class="rounded-full px-3 py-1 text-xs font-medium transition sm:text-sm"
                            :class="screen.key === activeScreen
                                ? 'bg-indigo-500 text-white'
                                : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'"
                            :aria-pressed="screen.key === activeScreen"
                            @click="activeScreen = screen.key"
                        >
                            {{ screen.label }}
                        </button>
                    </div>
                </div>

                <button
                    v-if="!videoOpen"
                    type="button"
                    class="group absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2"
                    aria-label="Play video"
                    @click="openVideo"
                >
                    <span class="relative flex size-16 sm:size-24">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-indigo-400 opacity-40 [animation-duration:2s]"></span>
                        <span class="relative inline-flex size-full items-center justify-center rounded-full bg-indigo-600 text-white shadow-xl shadow-indigo-600/40 ring-8 ring-white/60 transition-transform duration-200 group-hover:scale-110 dark:ring-white/10">
                            <PlayIcon class="ml-1 size-8 sm:size-12" />
                        </span>
                    </span>
                </button>

                <Transition
                    enter-active-class="transition duration-300 ease-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition duration-200 ease-in"
                    leave-to-class="opacity-0"
                >
                    <!-- Insets match the viewport of the browser window drawn into every screenshot
                         (measured on the 2560x1664 source), so the video plays inside that window.
                         The slight scale-up hides the 1px seam Chrome leaves when it snaps the video
                         layer to whole pixels; the background matches the video's edge colour. -->
                    <div
                        v-if="videoOpen"
                        class="absolute overflow-hidden bg-[#f7f8fd]"
                        :style="{
                            top: '7.51%',
                            bottom: '6.19%',
                            left: '3.05%',
                            right: '3.05%',
                            borderRadius: '0 0 1.66% 1.66% / 0 0 2.79% 2.79%',
                        }"
                    >
                        <video
                            ref="video"
                            :src="VIDEO_URL"
                            class="absolute inset-0 block size-full scale-[1.02] object-cover"
                            controls
                            playsinline
                            @ended="closeVideo"
                        ></video>
                        <button
                            type="button"
                            class="absolute right-2 top-2 flex size-8 items-center justify-center rounded-full bg-gray-900/10 text-gray-700 backdrop-blur transition hover:bg-gray-900/20 sm:right-3 sm:top-3"
                            aria-label="Close video"
                            @click="closeVideo"
                        >
                            <CloseIcon class="size-4" />
                        </button>
                    </div>
                </Transition>
            </div>
        </div>
    </section>
</template>

<style scoped>
.animationElement {
  animation: slideInUp 0.8s ease forwards;
}

/* Transform only: an element that starts at opacity 0 is not an LCP candidate until it
   becomes visible, which pushed the hero text (mobile) and screenshot (desktop) LCP back. */
@keyframes slideInUp {
  from {
    transform: translateY(1rem);
  }
  to {
    transform: translateY(0);
  }
}

@media (prefers-reduced-motion: reduce) {
  .animationElement {
    animation: none;
  }
}
</style>
