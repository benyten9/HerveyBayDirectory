# The handover — the agent is the bridge

This is the file that explains the one thing the rest of the KB assumes.

## The shape of it

```
Figma:   WordPress ──token──► api.figma.com          the SITE pulls
Paper:   the AGENT ──local MCP──► Paper Desktop       the AGENT pushes
             │
             └──ingest──► WordPress
```

With Figma, WordPress holds a credential and does the reading. With Paper there
is no credential to hold and nothing on the server can see the file. The only
process that can read the design is the one running next to Paper Desktop — the
agent. So the agent does the reading, and then carries the result across.

Nothing about this is a workaround. It is the only architecture Paper's local-only
MCP server permits, and it has one real advantage over the Figma model: the
design never passes through a third-party API, and WordPress stores no secret
that could leak.

## The six steps

```
read from Paper → ingest → plan → preview_convert → convert → audit
```

| Step | Who does it | Call |
|---|---|---|
| read | the agent, against Paper | Paper's own MCP tools — `paper-mcp.md` |
| ingest | the agent, against WordPress | `nibwp/paper-manage { action: "ingest" }` |
| plan | the skill | `nibwp/paper-pro-plan` |
| preview_convert | the skill | `nibwp/paper-manage { action: "preview_convert" }` |
| convert | the skill | `nibwp/paper-manage { action: "convert" }` |
| audit | the skill | `nibwp/paper-manage { action: "audit" }` or `nibwp/paper-pro-audit` |

Do not collapse `preview_convert` into `convert`. The preview costs one call and
catches the two failures that are expensive later: a wrong builder target, and a
heading ramp that produced four `h1`s.

## Step 0 — confirm both halves before you start

```
nibwp/paper-manage { action: "status" }
```

`status` answers the WordPress-side questions: is the integration active, is the
Pro entitlement present, which builders can this site actually reach, how many
designs are already stored. It does **not** and cannot tell you whether Paper is
reachable — that is your side of the bridge. Check both:

- Paper side: the MCP server answers a tool listing.
- WordPress side: `status` returns active and entitled.

If either fails, stop and name which one. "It didn't work" is useless to a user
who has to fix one specific thing.

## Step 1 — ingest

`ingest` is the only route into WordPress. It takes the design and stores it as a
record, returning an id that every later action uses.

Two ways to call it, and the choice matters:

**Hand it a finished NDO.** You walked Paper's tree, built the object yourself,
and you own the mapping. This is the normal path — you have the most context
about what the design means, and the most ability to fix a bad heading level or
label a shader layer honestly.

**Hand it a raw Paper payload to normalize.** Cheaper to call, less control. Use
it for a quick look at an unfamiliar artboard. Expect the generic `type` to be
`container` more often than it should be, because the normalizer cannot know
Paper's node vocabulary as well as you can after reading it.

Either way: **`ingest` treats its input as untrusted.** It is an agent-supplied
payload arriving over an ability call, not something the site generated. It is
validated, sanitized and size-capped on the way in. Do not rely on a value
surviving unchanged just because you sent it — read the record back with
`get_design` if the exact bytes matter.

What to put in the payload beyond the NDO itself:

- A **title** that means something to the user. They will pick this record out of
  a list later.
- The **artboard width** in `root.bbox.w`. Responsive planning needs it.
- Every compromise in `warnings`, as a plain string. The Paper normalizer fills in
  the mechanical ones (an unresolved image reference, for example); nothing
  downstream will detect a dropped shader or a missing font for you.
  `../core/schema.md`.

## Step 2 — plan before converting

`nibwp/paper-pro-plan` reads the stored record and returns a build plan: which
builder, how the tree breaks into sections, which subtrees repeat and should be
one component, which text should bind to dynamic data, and which layers have a
fidelity problem.

Show the plan to the user. This is the cheapest possible moment to be told "that
second section isn't a hero, it's a testimonial carousel" — and the most
expensive moment to miss it.

## Step 3 — preview, then convert

`preview_convert` builds and returns markup and warnings. It **persists
nothing**. Read the warnings. Count the `h1`s. Check that the token map got used
instead of raw hexes.

`convert` persists. It creates a **draft**. Never convert onto a live page: the
user's existing content is not yours to overwrite, and a draft costs them one
click to promote.

## Step 4 — assets and tokens

Two actions that are easy to forget and visible when skipped:

- `attach_asset` sideloads an image you exported from Paper into the media
  library. Skip it and the page references a path on the designer's machine,
  which works on exactly one computer. Every raster fill and every flattened
  shader layer goes through it.
- `sync_tokens` pushes the record's token map into the builder's native globals.
  Skip it and the page has correct colors that the user cannot edit from their
  builder's own color panel — which they will, reasonably, call broken.

## Step 5 — audit, honestly

`audit` reports what converted, what degraded and what was dropped. Pair it with
a Playwright render at the artboard width, diffed against the Paper screenshot
you captured in step 1.

Report the shader gaps explicitly, by layer name. A user who is told "the noise
field in the hero is a flattened PNG, so it will not animate" trusts the rest of
the report. A user who discovers it themselves does not.

## What the bridge cannot do

- **Re-read the design later from the server.** There is no stored reference the
  site can resolve. A second conversion means a second agent session next to
  Paper Desktop. Say so when the user asks for "a refresh".
- **Run on a schedule.** No cron job can reach Paper. Nothing here is automatable
  without a human-adjacent agent session.
- **Work for a teammate who doesn't have the file.** Paper access is the
  designer's own app access, same as a design tool's always is.
- **Push WordPress back into Paper.** Reportedly technically possible with
  Paper's write tools; not supported here. `paper-mcp.md` §4.
