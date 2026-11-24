---
title: "Ask Your Laravel Analytics Anything: AI Assistant & MCP Server"
slug: "ask-your-laravel-analytics-anything-ai-assistant-and-mcp-server"
date: "2026-07-08"
excerpt: "Which referrer brought the most revenue last month? How did MRR develop this year? Instead of clicking through filters, you can now simply ask. SimpleStats ships an AI assistant right in your dashboard, plus a hosted MCP server that brings your analytics into Claude Code, Cursor, and Claude Desktop."
tags:
  - AI
  - MCP
  - Laravel
  - Analytics
published: true
author_id: 1
---

Some questions are easy to answer with a dashboard: "How many visitors did I get this week?" is one glance at a chart. Other questions take more clicking: "Which referrer brought the most paying users last quarter?" means picking a date range, choosing a breakdown, sorting by the right column, and maybe comparing a second dimension.

Now you can just ask.

SimpleStats ships two new ways to talk to your analytics: an **AI assistant built into your dashboard**, and a **hosted MCP server** that connects your analytics to AI tools like Claude Code, Cursor, and Claude Desktop.

## Ask AI, right in your dashboard

Look for the sparkle button in your dashboard. It opens a chat panel next to your stats where you can ask questions in plain language:

- "How many registrations did I get last month, and how does that compare to the month before?"
- "Which traffic source has the best conversion rate this year?"
- "How did my MRR develop over the last 6 months?"
- "Do users from Google stick around longer than users from Twitter?"

The assistant answers with your real numbers. Every figure comes from an actual query against your analytics data, never from an estimate. It always tells you which date range and project the numbers refer to, and if there is no data for what you asked, it says so instead of guessing.

It handles follow-ups too. Ask for last month's revenue, then ask "and how much of that came from campaigns?", and it keeps the context. Conversations are saved, so you can pick up where you left off.

Since the assistant knows the SimpleStats docs as well, it also answers setup questions: how to configure tracking, how the client package works, what a specific KPI means. Docs answers include a link to the relevant page.

## What it can answer

The assistant has access to the same data your dashboard shows:

- **KPI time series**: visitors, registrations, conversion rate, revenue, active users (DAU/WAU/MAU), ARPU and friends, for any date range.
- **Breakdowns**: by referrer, source, medium, campaign, country, device, browser, entry page, and more.
- **Subscription metrics**: MRR, ARR, expansion, contraction, churn, NRR/GRR, and subscription economics.
- **Retention**: registration retention cohorts, so "do users come back?" gets a real answer.

## Your analytics in Claude Code, Cursor, and Claude Desktop

The same assistant capabilities are available outside the app through our hosted [MCP](https://modelcontextprotocol.io) (Model Context Protocol) server. Connect it once, and your AI coding tool can query your analytics whenever you ask.

That unlocks workflows a dashboard can't do:

- **Check impact while you work.** "Did conversion change since the deploy on Tuesday?" without leaving your editor.
- **Generate reports.** "Write a short weekly summary of visitors, registrations, and revenue, and compare it to the previous week."
- **Combine with your codebase.** Ask Claude Code to look at your pricing page *and* your conversion numbers in one conversation.

Setup takes one command in Claude Code:

```bash
claude mcp add --transport http simplestats https://simplestats.io/mcp
```

For Cursor or VS Code, add it to your `mcp.json`:

```json
{
    "mcpServers": {
        "simplestats": {
            "url": "https://simplestats.io/mcp"
        }
    }
}
```

Authentication runs via OAuth: your AI tool opens the browser, you sign in to SimpleStats and approve the connection. The agent then works with your role and project access in the team you have selected in the app. Claude Desktop and claude.ai connect the same way via **Settings → Connectors**, the [MCP server docs](/docs/mcp-server.html) cover every client.

## Bring your own AI key

By default the in-app assistant runs on our AI provider account, and you don't have to configure anything. If your team prefers its own provider, you can bring your own API key: head to your team settings and pick a provider (Anthropic, OpenAI, Gemini, Groq, Mistral, DeepSeek, xAI, or OpenRouter), optionally choose a specific model, and paste your key. Keys are stored encrypted and are never shown again after saving.

That gives you full control over which model reads your questions and lets heavy users scale usage on their own account.

## Availability

The AI assistant and the MCP server are included in **all paid plans**. MCP requests are rate limited to 60 requests per minute per user, which is plenty for interactive use. Self-hosted installations get the MCP server on their own domain, working the same way.

Ready to try it? Open your [dashboard](https://simplestats.io) and hit the sparkle button, or connect [the MCP server](/docs/mcp-server.html) to your editor and ask your analytics something you've always been too lazy to click together.
