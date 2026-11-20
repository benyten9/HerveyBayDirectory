# Paper's MCP server — local only

> Primary source: <https://paper.design/docs/mcp>, fetched 2 Oct 2026. Everything
> in §1–§3 is quoted or paraphrased from that page. §4 is explicitly
> **unverified** and labelled as such.

## 1. What the official docs establish

| Fact | The docs' words |
|---|---|
| The server is started by the desktop app | "First, install and open Paper Desktop. That starts the MCP server in the background and installs the Paper CLI used to connect agents." |
| Current transport is stdio | "The Connect your agent dialog now installs **stdio** via the Paper CLI (`paper mcp`)" |
| There is a legacy HTTP transport | "Older docs and configs connected over Streamable HTTP at `http://127.0.0.1:29979/mcp`" |
| No API key, no OAuth | the page does not mention either, anywhere |
| No cloud API | the page describes no hosted endpoint for Paper files |

Read that table again and notice what is missing: a hostname, an account, a
token, a file key. There is no remote surface. **Paper design data exists on the
designer's machine and nowhere a web server can reach it.**

## 2. What that means for WordPress

It means WordPress is not a participant in reading the design.

- `127.0.0.1:29979` on the designer's laptop is not `127.0.0.1` on the web
  server, and on a hosted site it is not reachable at all.
- There is no credential for WordPress to store, so the NibWP Paper integration
  **has no token field**. If you go looking for one in NibWP → Integrations →
  Paper you will find a status panel instead. That is deliberate.
- The integration never makes an outbound request to a loopback address. Even
  where it would technically work (a local dev site on the same machine) it is
  not implemented, because a feature that only works on localhost is a support
  burden pretending to be a feature.

So the agent reads Paper, and the agent hands the result to WordPress. That
handover is the subject of `handover.md`.

## 3. Setup

Two routes. Both are from the official docs page.

**Current, recommended — the Paper plugin for Claude Code:**

```
claude plugin marketplace add paper-design/agent-plugins && claude plugin install paper-desktop@paper
```

This is what Paper's own "Connect your agent" dialog installs, and it wires up
stdio via the `paper mcp` CLI.

**Legacy, still documented — the HTTP endpoint:**

```
claude mcp add paper --transport http http://127.0.0.1:29979/mcp --scope user
```

Use this when the plugin route is unavailable or the user is on an older Paper
build. The docs call this transport legacy but still describe it, so it is a
legitimate fallback rather than a hack.

**Preconditions, every time:**

1. Paper Desktop is installed and **open**. Closing the app stops the server.
2. The agent process runs on the **same machine** as Paper Desktop. A remote
   agent, a CI runner, or a cloud sandbox cannot reach it.
3. The MCP server answers. Verify by asking it for its tool list, not by
   assuming.

**Failure modes and what to say:**

| Symptom | Cause | What to tell the user |
|---|---|---|
| No Paper tools present at all | plugin/server not registered with this agent | give them the install line above |
| Tools present, every call errors | Paper Desktop is closed | "open Paper Desktop — it hosts the server" |
| Connection refused on `:29979` | newer Paper build, stdio only | switch to the plugin route |
| Works for you, not on their server | expected — the server is local | explain the bridge; nothing to fix |

There is **no fallback source**. If the bridge is down, stop and say so. Do not
substitute a screenshot and present the result as a conversion of their design.

## 4. Tool names — unverified, read this before using any of them

**The official docs page does not enumerate Paper's MCP tools.** It describes
capabilities generically and names none.

The names below come from **secondary sources**: community MCP wrappers on
GitHub, MCP directory listings, and a comparison article. They agree with each
other on roughly 24 tools, which is some evidence, but they are not primary and
at least one of them also claims the server is "authenticated" — which the
official docs contradict. That inconsistency is the reason to distrust the whole
list.

> **The rule: call Paper's own tool listing first.** Your MCP client can list the
> server's tools. Do that, match the capability you need against what is actually
> there, and call that. Never paste a name out of this file into a call.

### Reported tool names, by capability

Status key — **R** = reported by secondary sources, unverified. No entry in this
table is verified against a primary source.

| Capability you need | Reported name | Status |
|---|---|---|
| What is the user looking at | `get_selection` | R |
| File overview, artboard list | `get_basic_info` | R |
| One node's properties | `get_node_info` | R |
| A node's direct children | `get_children` | R |
| A compact subtree outline | `get_tree_summary` | R |
| Resolved CSS for one or more nodes | `get_computed_styles` | R |
| Code for a node and its descendants | `get_jsx` | R |
| A raster image of a node | `get_screenshot` | R |
| Create an artboard | `create_artboard` | R |
| Write markup into the canvas | `write_html` | R |
| Change a text layer's content | `set_text_content` | R |
| Change a layer's styles | `update_styles` | R |
| Clear the busy indicator | `finish_working_on_nodes` | R |
| Rename / duplicate / move / delete a node | — | R, names not even reported consistently |
| Export an image asset | — | R, names not reported consistently |

The two reported names that matter most for this skill, and why:

- **`get_computed_styles`** — if a tool like this exists, it is the single best
  input the NDO can have. Paper's canvas is HTML/CSS, so "computed styles" are
  not an approximation of the design; they are the design. Prefer it over
  inferring CSS from geometry.
- **`get_screenshot`** — the verification ground truth. Capture it once, before
  converting, and diff the rendered WordPress page against it afterwards.

### Write tools: reachable, not supported

Several reported tools write to the canvas. **This skill does not use them.**
NibWP → Paper is a one-way read. If the user asks to push a WordPress page back
into Paper, say it is not supported rather than improvising with `write_html`.
An unexpected edit to a designer's live file is the worst possible first
impression.

## 5. Reading a design, in practice

A capability-shaped recipe. Substitute the real tool names from the live listing.

1. **Ask what is selected.** If something is, offer it as the target. If nothing
   is, list the artboards and ask which one.
2. **Get the subtree outline** before anything heavy. A summary tells you how big
   the job is and lets you warn the user about a 400-node artboard before you
   spend ten turns on it.
3. **Get computed styles in batches.** If the tool accepts multiple node ids,
   use that — one call per node on a real artboard is the difference between a
   fast conversion and an abandoned one.
4. **Get the screenshot** at 2× if offered. Store it as the verify baseline.
5. **Get text content** with the node data, not separately, where the API allows.
6. **Note every shader or effect layer** as you walk the tree. Each one becomes a
   decision in `../core/assets.md` and a `warnings` entry in the NDO.
7. **Export images** for raster fills and for every shader layer you decided to
   flatten, then hand them to `nibwp/paper-manage { action: "attach_asset" }` so
   they become first-party media.

Then normalize (`../core/parser.md`) and hand over (`handover.md`).
