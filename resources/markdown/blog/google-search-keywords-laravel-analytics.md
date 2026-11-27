---
title: "Which Google Keywords Bring Paying Customers to Your Laravel App?"
slug: "google-search-keywords-laravel-analytics"
date: "2026-11-27"
excerpt: "Google hides the search term of every visitor. SimpleStats now connects to Google Search Console and shows your keywords next to your other channels: clicks, ranking, and an estimate of the revenue each keyword brings."
tags:
  - SEO
  - Google Search Console
  - Laravel
  - Analytics
published: false
author_id: 1
---

"google.com" is the top referrer of most Laravel apps. And it is the least helpful one. You know that Google sends you people, but not what they searched for. Was it "laravel analytics"? "google analytics alternative"? Your brand name?

Google stopped passing the search term in 2011 ("not provided"). No analytics tool can read it from the visit, no matter how it tracks. The only place the keywords still exist is **Google Search Console**.

So we brought Search Console into SimpleStats.

## Your keywords, next to your other channels

Connect a project to Search Console and the Tracking card of your dashboard gets a new **Keywords** tab, right next to Referrer, Source and Campaign:

- **Clicks:** how many people came from Google with this keyword
- **Impressions:** how often you showed up in the results
- **CTR:** how many of those impressions turned into a click
- **Position:** where you rank on average
- **Est. Revenue:** how much revenue the keyword brings (more on that below)

It follows the date range and comparison of your dashboard. Pick "Last 30 days" with "compare to previous period" and you see right away which keywords grow and which drop.

## From keyword to revenue

Search Console alone tells you what people searched for. It does not tell you whether they paid. Your analytics knows who paid, but not what they searched for. SimpleStats has both sides, so it can bring them together.

Every keyword leads to one or more landing pages. SimpleStats knows how much revenue the people who entered on each page made, and which share of that page's traffic came from a keyword. Multiply the two and you get an estimate of what a keyword is worth.

An example: your `/pricing` page made 1,000 € last month from 400 visitors. 100 of them came from Google with "laravel analytics". That keyword gets 250 €.

It is an estimate, and we label it as one. Google does not tell which visitor searched for what, so nobody can give you the exact number. But it answers the question that matters for your content and SEO work: **which keywords are worth more of my time?**

A keyword with 5,000 impressions and no revenue is a vanity win. A keyword with 300 impressions, rank 8 and a few hundred euros behind it is the one to push to page one.

## Keywords per landing page

Click an entry page in the Pages card and the Keywords tab only shows the keywords that lead there. Useful for questions like:

- "What do people search for before they land on my pricing page?"
- "Does my new blog post rank for what I wrote it for?"
- "Which docs page brings the most search traffic, and for what?"

## Ask the AI assistant

Your keywords are also available to the **Ask AI** assistant in your dashboard and to the [MCP server](/docs/mcp-server.html) in Claude Code, Cursor or Claude Desktop:

- "Which Google keywords brought the most clicks last month?"
- "Which keywords rank between 5 and 15 and already bring revenue?"
- "How did our ranking for 'laravel analytics' change compared to the previous month?"

If a project is not connected yet, the assistant tells you where to connect it and walks you through the setup.

## Setup in two minutes

You need a verified property for your site in [Google Search Console](https://search.google.com/search-console). Then:

1. Open **Projects**, pick your project and find **Google Search Console**.
2. Click **Connect Google Search Console** and sign in with a Google account that has access to the property. SimpleStats only asks for read access.
3. Pick the property and click **Link property**.

That's it. SimpleStats imports up to **16 months of history** right away and then updates your keywords every day. Search Console data is about two days behind, so the newest days follow a little later.

The Google account does not have to be the one you sign in to SimpleStats with. A shared webmaster account works just as well. Admins and editors of your team can connect it, everyone in the team sees the keywords.

## A few things worth knowing

- **Filters:** Search Console reports totals per keyword and page, not single visitors. So the Keywords tab follows the project, the date range and the entry page filter, but not filters like country or device.
- **Privacy:** Google only reports keywords that enough people searched for. Very rare searches are left out by Google itself, so your keyword list never adds up to all of your Google clicks.
- **Self-hosted:** the integration is part of the open source version too. You only need your own Google OAuth client, the [docs](/docs/google-search-console.html) walk you through it.

## Get started

Open your project, connect Search Console, and see which keywords bring you paying customers. The full setup and the details of the revenue estimate are in the docs: [Google Search Console](/docs/google-search-console.html).

No SimpleStats account yet? [Start for free](/register) and connect your first project in a few minutes.
