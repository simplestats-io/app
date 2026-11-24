---
description: "Instructions for AI agents to connect the SimpleStats MCP server, so you can ask about visitors, revenue and campaigns in plain language."
---

# Agent Setup

These are the official instructions from _SimpleStats_ for AI agents to connect the SimpleStats MCP server. Once connected, the user can ask questions about their analytics (visitors, registrations, revenue, MRR, campaigns, ...) in plain language. For humans, the same setup is documented in [MCP Server](mcp-server.md).

Complete the steps yourself by running the commands or editing the config files directly. The only step the user does is signing in to SimpleStats in the browser when the agent asks for it.

## 1. Gather the Details

- **MCP endpoint:** use the endpoint from the user's prompt. If there is none, use `https://simplestats.io/mcp`. Self-hosted installations use `https://<their-domain>/mcp`.
- **Authentication:** the server uses OAuth. Do not ask the user for an API token and do not add an `Authorization` header: project API tokens are rejected by the MCP server. The connection acts as the signed-in user in the team currently selected in the app, with their role and project access, so one server covers all projects of that team.
- If a SimpleStats server already exists with an `Authorization` header (older setups used a project API token), replace it with the configuration below after asking the user.

## 2. Register the Server

Use the section for your agent and replace `MCP_ENDPOINT` with the value from step 1.

### Claude Code

```bash
claude mcp add --transport http --scope user simplestats MCP_ENDPOINT
```

Then tell the user to run `/mcp` in Claude Code, pick `simplestats` and choose **Authenticate**.

### Codex

Add to `~/.codex/config.toml`:

```toml
[mcp_servers.simplestats]
url = "MCP_ENDPOINT"
```

Then run `codex mcp login simplestats`, which opens the browser for the sign-in.

### Cursor

Add under `"mcpServers"` in `~/.cursor/mcp.json` (create the file if it does not exist):

```json
"simplestats": {
    "url": "MCP_ENDPOINT"
}
```

Then tell the user to click **Connect** next to the server in Cursor's MCP settings.

### VS Code (GitHub Copilot)

Add to the user `mcp.json` (command **MCP: Open User Configuration**), merging into an existing `"servers"` entry. VS Code starts the sign-in on first use:

```json
{
    "servers": {
        "simplestats": {
            "type": "http",
            "url": "MCP_ENDPOINT"
        }
    }
}
```

### OpenCode

Run `opencode mcp add simplestats --url MCP_ENDPOINT --global`, then `opencode mcp auth simplestats` to sign in. To edit the config by hand instead, add the server under `"mcp"` -> `"servers"` in the global OpenCode config:

```json
"simplestats": {
    "type": "remote",
    "url": "MCP_ENDPOINT"
}
```

### Claude Desktop / claude.ai

Remote servers are added in the app, not in a config file. Tell the user to open **Settings → Connectors → Add custom connector** and enter `MCP_ENDPOINT`.

### Other Agents

Register a remote MCP server using streamable HTTP with the URL `MCP_ENDPOINT` and no headers, following your agent's MCP documentation. The agent needs OAuth support for remote MCP servers.

## 3. Verify

Once the user has signed in and your agent can load the new server without a restart, call the `list-stats-capabilities` tool and show the user the projects it returns. A `401` means the sign-in has not completed yet (or an old `Authorization` header is still configured), a `403` explains in its message what is missing (e.g. a verified email address).

## 4. Finish

Tell the user:

- where the server was configured (agent and config file),
- to restart the agent if the tools are not available yet,
- that the connection works on the team selected in the app, and the connection is read-only unless they tick **Also allow changes** on the consent screen,
- an example question to try, e.g. "How many registrations did I get last month?"
