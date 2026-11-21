---
description: "How SimpleStats filters bots, crawlers, AI scrapers and headless browsers automatically, so your Laravel analytics only count real humans."
---

# Bot Detection

_SimpleStats_ filters out **bot and crawler traffic** automatically, so your visitor counts reflect real humans, not scrapers or uptime monitors. Bot detection is enabled by default and requires no configuration.

A request is **skipped** (no visitor recorded, no API call dispatched) when:

- the User-Agent matches a **known bot signature** via [matomo/device-detector](https://github.com/matomo-org/device-detector), covering thousands of crawlers, scrapers, AI/LLM crawlers (GPTBot, ClaudeBot, PerplexityBot, etc.), social media previewers, and uptime monitors
- the User-Agent is **not a real browser or mobile app**: HTTP libraries (curl, python-requests, ...), headless browsers, and scripted clients faking a browser User-Agent
- the request is a speculative browser **prefetch/prerender**, since the visitor may never actually see the page

On top of that, visits from **datacenter IPs** (AWS, GCP, Hetzner, and the like) are filtered server-side, since they are practically always bots hiding behind a real browser User-Agent. VPN and iCloud Private Relay users are not affected.

Visits whose referrer is a known **referrer spam domain** are dropped server-side as well. Some bots fake a referrer from an unrelated, legitimate looking site and send a real browser User-Agent from residential IPs, so only the referrer gives them away. The domains come from the community-maintained [Matomo referrer spam list](https://github.com/matomo-org/referrer-spam-list), which SimpleStats refreshes weekly.
