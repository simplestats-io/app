---
description: "Connect the hosted SimpleStats MCP server to Claude Code, Cursor or Claude Desktop and ask questions about your analytics in plain language."
---

# MCP Server

_SimpleStats_ ships a hosted [MCP](https://modelcontextprotocol.io) (Model Context Protocol) server. Connect it to AI tools like Claude Code, Cursor, or Claude Desktop and ask questions about your analytics in plain language: "How many registrations did I get last month?", "Which referrer brings the most revenue?", "How did my MRR develop this year?".

::: tip
Prefer chatting inside the app? The same assistant powers the **Ask AI** panel on your dashboard.
:::

## Available Tools

| Tool | Purpose |
|------|---------|
| `list-stats-capabilities` | Lists your projects, breakdown dimensions, date presets, and a KPI legend |
| `get-kpi-stats` | KPI time series (visitors, registrations, conversion, revenue, active users, page views) with totals |
| `get-grouped-breakdown` | Breakdowns by dimension (referrer, source, campaign, country, device, top pages, ...) |
| `get-recurring-revenue-stats` | MRR/ARR snapshots, movements, churn, NRR/GRR, subscription economics |
| `get-retention-cohorts` | Registration retention cohorts |
| `get-campaign-performance` | Per-campaign profitability: spend, revenue, net profit, ROAS, CAC, LTV:CAC, payback |
| `search-docs` | Searches this documentation |
| `list-app-pages` | Lists the in-app pages with descriptions and links |

## Endpoint & Authentication

The MCP server is available via streamable HTTP at:

`https://simplestats.io/mcp`

Authenticate with your **project API token** as a bearer token, the same token you use for the [Ingest API](ingest-api.md) and [Query API](query-api.md):

`Authorization: Bearer API_TOKEN_HERE`

::: tip
You'll receive an API token after creating a new project, or you can find your existing API tokens inside the app on the project page.
:::

A token only grants access to the data of its own project. To analyze several projects in one agent, add the server once per project with a distinct name (e.g. `simplestats-shop` and `simplestats-blog`), each with its project's token. The **Ask AI** panel in the app covers all projects of your team.

## Let Your Agent Set It Up

Copy this prompt into your coding agent (Claude Code, Codex, Cursor, OpenCode, ...) and it configures the server for you. It asks you for your API token along the way. You'll find the same prompt in the **Ask AI** panel of the app.

```
Fetch and follow the instructions from https://simplestats.io/docs/agent-setup.md to connect the SimpleStats MCP server to this agent.
```

Prefer to do it by hand? Pick your tool below.

## Claude Code

Add the server with one command. `--scope user` makes it available in all your projects and keeps the token out of your repository:

```bash
claude mcp add --transport http --scope user simplestats https://simplestats.io/mcp \
  --header "Authorization: Bearer API_TOKEN_HERE"
```

## Codex

Add the server to `~/.codex/config.toml`:

```toml
[mcp_servers.simplestats]
url = "https://simplestats.io/mcp"
http_headers = { "Authorization" = "Bearer API_TOKEN_HERE" }
```

To keep the token out of the file, use `bearer_token_env_var = "SIMPLESTATS_API_TOKEN"` instead of `http_headers` and export the token in your shell.

## Cursor

Add the server to `~/.cursor/mcp.json` (or `.cursor/mcp.json` in a project):

```json
{
    "mcpServers": {
        "simplestats": {
            "url": "https://simplestats.io/mcp",
            "headers": {
                "Authorization": "Bearer API_TOKEN_HERE"
            }
        }
    }
}
```

## VS Code (GitHub Copilot)

Add the server to `.vscode/mcp.json`, or run **MCP: Open User Configuration** to add it for all workspaces. VS Code asks for the token on first use and stores it securely, so the file is safe to commit:

```json
{
    "inputs": [
        {
            "type": "promptString",
            "id": "simplestats-token",
            "description": "SimpleStats API token",
            "password": true
        }
    ],
    "servers": {
        "simplestats": {
            "type": "http",
            "url": "https://simplestats.io/mcp",
            "headers": {
                "Authorization": "Bearer ${input:simplestats-token}"
            }
        }
    }
}
```

## OpenCode

Add the server to `~/.config/opencode/opencode.json` under `"mcp"`. `"oauth": false` tells OpenCode to use the token instead of starting an OAuth flow:

```json
{
    "$schema": "https://opencode.ai/config.json",
    "mcp": {
        "simplestats": {
            "type": "remote",
            "url": "https://simplestats.io/mcp",
            "enabled": true,
            "oauth": false,
            "headers": {
                "Authorization": "Bearer API_TOKEN_HERE"
            }
        }
    }
}
```

## Claude Desktop

Claude Desktop connectors currently expect OAuth for remote servers. Use the [mcp-remote](https://www.npmjs.com/package/mcp-remote) shim to bridge with your bearer token instead. Add this to your `claude_desktop_config.json`:

```json
{
    "mcpServers": {
        "simplestats": {
            "command": "npx",
            "args": [
                "mcp-remote",
                "https://simplestats.io/mcp",
                "--header",
                "Authorization: Bearer API_TOKEN_HERE"
            ]
        }
    }
}
```

## Rate Limit

MCP requests are limited to 60 requests per minute per project. If you exceed the limit, the server responds with `429 Too Many Requests`.

## Self-Hosted

On self-hosted installations the MCP server is available on your own domain at `https://your-domain.example/mcp`, authenticated with your project API tokens the same way. Replace `https://simplestats.io/mcp` in the examples above with your own URL. The setup prompt in the **Ask AI** panel already contains it.
