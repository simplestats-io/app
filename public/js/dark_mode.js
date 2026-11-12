// Get user preference from localStorage or use system preference
function getUserThemePreference() {
    return localStorage.getItem('theme') || 'system';
}

const darkModeMediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

// Colors the browser UI (e.g. the status bar of the installed app) like the page
// background, which the manifest's static theme_color cannot follow.
function updateThemeColor() {
    let meta = document.querySelector('meta[name="theme-color"]');

    if (!meta) {
        meta = document.createElement('meta');
        meta.name = 'theme-color';
        document.head.appendChild(meta);
    }

    meta.content = document.documentElement.classList.contains('dark') ? '#1a1a1a' : '#f9fafb';
}

function updateDarkMode() {
    const userPreference = getUserThemePreference();

    if (userPreference === 'dark') {
        document.documentElement.classList.add('dark');
    } else if (userPreference === 'light') {
        document.documentElement.classList.remove('dark');
    } else {
        // System preference
        if (darkModeMediaQuery.matches) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    }

    updateThemeColor();
}

// Update theme when localStorage changes (for cross-tab consistency)
window.addEventListener('storage', function(e) {
    if (e.key === 'theme') {
        updateDarkMode();
    }
});

// Listen for theme changes from Vue components
window.addEventListener('themeChanged', function(e) {
    updateDarkMode();
});

updateDarkMode();
darkModeMediaQuery.addEventListener('change', function() {
    // Only update based on system preference if user has selected 'system'
    if (getUserThemePreference() === 'system') {
        updateDarkMode();
    }
});
