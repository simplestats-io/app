<script setup>

defineProps({
    position: String,
});

</script>

<template>
    <svg :class="`${position} top-0 hidden sm:block pointer-events-none will-change-transform`" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" version="1.1" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:svgjs="http://svgjs.dev/svgjs" viewBox="0 0 800 450" opacity="0.72"><defs><filter id="bbblurry-filter" x="-100%" y="-100%" width="400%" height="400%" filterUnits="objectBoundingBox" primitiveUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
        <feGaussianBlur stdDeviation="74" x="0%" y="0%" width="100%" height="100%" in="SourceGraphic" edgeMode="none" result="blur"></feGaussianBlur></filter></defs><g filter="url(#bbblurry-filter)"><ellipse rx="46.5" ry="46" cx="74.30684384432709" cy="22.376124642112046" fill="hsl(239, 84%, 67%)"></ellipse><ellipse rx="46.5" ry="46" cx="47.061669956554056" cy="107.51420593261719" fill="hsl(239, 84%, 67%)"></ellipse><ellipse rx="46.5" ry="46" cx="726.6835826526988" cy="29.679329091852367" fill="hsl(239, 84%, 67%)"></ellipse><ellipse rx="46.5" ry="46" cx="19.663832751187414" cy="197.89784934303975" fill="hsl(239, 84%, 67%)"></ellipse><ellipse rx="46.5" ry="46" cx="757.1947243430398" cy="110.35478487881744" fill="hsl(239, 84%, 67%)"></ellipse><ellipse rx="46.5" ry="46" cx="785.0749678178267" cy="201.9151736172763" fill="hsl(239, 84%, 67%)"></ellipse></g>
    </svg>
</template>
