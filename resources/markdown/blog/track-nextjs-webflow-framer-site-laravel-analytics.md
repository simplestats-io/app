---
title: "Track Your Next.js, Webflow or Framer Site with Your Laravel Analytics"
slug: "track-nextjs-webflow-framer-site-laravel-analytics"
date: "2026-11-27"
excerpt: "Your landing page runs on Next.js, Webflow, Framer or WordPress, your app on Laravel? One script tag now brings those visitors into SimpleStats, and every signup and payment in your app is attributed to the campaign that brought them to your marketing site."
tags:
  - Laravel
  - Next.js
  - Attribution
  - Product Update
published: true
author_id: 1
---

Many Laravel apps don't start with Laravel. The landing page, the blog and the pricing page live on Webflow, Framer, WordPress or Next.js, and only signup, login and payments run in the Laravel app.

That is exactly the traffic attribution is about. The visitor who found you on Google, read your pricing page and signed up a day later should count for Google, not for "came from my own marketing site".

**Now it does.** Since version 6.3 of the Laravel client, your marketing site is one script tag away from SimpleStats, and since 6.4 the same works for a single page app that talks to your Laravel API.

---

## One Script Tag

Enable the beacon in your Laravel app:

```dotenv
SIMPLESTATS_BEACON_ENABLED=true
```

List your marketing domain in `config/simplestats-client.php`:

```php
'own_domains' => [
    'www.my-app.com',
],
```

And add the script to every page of your marketing site, served by your Laravel app:

```html
<script src="https://app.my-app.com/assets/v.js" defer></script>
```

That's it. Visitors of your marketing site show up in your dashboard with their real source, campaign and landing page. When they sign up or pay in your app later, SimpleStats attributes it to that first visit.

---

## First Party, Not a Third Party Tracker

The script is served by your own app, under your own domain. There is no third party tracker host for ad blockers to match, and your API token never reaches the browser.

SimpleStats recognizes a visitor by a daily anonymous hash, the same way it always has. A visitor who moves from your marketing site into your app on the same day is the same visitor, even across domains. Your marketing domain is never recorded as a referrer of your own app, so your channels report the real sources.

---

## Single Page Apps on Your Laravel API

Your whole frontend is a Next.js, Nuxt, React or Vue app, and Laravel is only the API behind it? Use the same script. It forwards the landing page, the referrer and the campaign parameters, and it reports every route change inside the app as a page view, so you get Top Pages without writing a line of tracking code.

The [Headless, SPA & Stateless guide](/docs/headless-stateless-spa.html) shows the two config lines your API needs.

---

## Guides for Your Platform

Where the script goes depends on your platform. We wrote a short guide for each:

- [Next.js](/docs/nextjs.html)
- [Nuxt](/docs/nuxt.html)
- [Astro](/docs/astro.html)
- [Webflow](/docs/webflow.html)
- [Framer](/docs/framer.html)
- [WordPress](/docs/wordpress.html)

Blade, Livewire and Inertia need none of this: the client sees those visitors anyway.

---

## Or Let Your AI Agent Do It

New in the setup: next to the manual steps, you get a prompt for Claude Code, Cursor or Codex. Paste it into your agent, opened in your Laravel app, and it installs the client, picks the setup for your frontend and adds registration and payment tracking. You review the changes before anything is committed.

---

Ready to see where your visitors really come from? Update the client to the latest version and add the script. All details are in [Works with Any Frontend](/docs/frontend-frameworks.html).
