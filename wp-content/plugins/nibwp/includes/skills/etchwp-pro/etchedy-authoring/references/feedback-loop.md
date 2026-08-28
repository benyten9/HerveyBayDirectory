# Feedback loop

After every successful conversion, the agent asks the user "👍 or 👎?" and calls `nibwp/etchwp-pro-feedback`. The aggregated thumb-down reasons are surfaced on the next conversion of the same `(brand, element_type)` pair so future runs avoid past mistakes.

## Storage

```
option_name: nibwp_etchwp_feedback
shape:
{
  "<brand>::<element_type>": {
    "up":   int,
    "down": int,
    "recent_down_reasons": [
      { "ts": int (unix seconds), "reason": "string", "component_id": "string" }
    ]   // capped to the most recent 10
  }
}
```

The option is `autoload: false` so it is read only when relevant. Sanitized via `sanitize_key()` for the keys and `sanitize_text_field()` for the reason.

## Injection point

`nibwp/load-skill-playbook` accepts `brand` and `element_type` parameters. When both are present, the response includes a `lessons_learned` field — a markdown rendering of `recent_down_reasons` for that `(brand, element_type)`:

```
## Lessons learned for alpha::hero

Recent thumb-down reasons (latest first):

- 2026-05-29 — "heading too tight on mobile" (component: 0fa3b…)
- 2026-05-27 — "CTA pair stacks before hero subtitle on tablet" (component: 7ce91…)
```

Agents read this BEFORE synthesizing the next conversion of the same type. The string `{{INJECTED_FEEDBACK}}` in each `checklists/*.md` is the substitution point — `load-skill-playbook` replaces it with the rendered lessons-learned block (or the literal string "No prior feedback recorded." when the bucket is empty).

## Workflow

1. Conversion completes (validator passes, persister writes).
2. Agent asks user: "Hero converted. 👍 or 👎?"
3. User: "👎 — heading too tight on mobile"
4. Agent calls `nibwp/etchwp-pro-feedback`:
   ```json
   {
     "component_id": "0fa3b…",
     "brand":        "alpha",
     "element_type": "hero",
     "rating":       "down",
     "reason":       "heading too tight on mobile"
   }
   ```
5. Server stores the entry; aggregated counter increments; oldest entry beyond #10 is evicted.
6. Next hero conversion for brand `alpha`: `load-skill-playbook` returns `checklists/hero.md` with the "Lessons learned" block populated. The agent reads "heading too tight on mobile" and pre-emptively chooses tighter line-height + a smaller-token at the narrow-container breakpoint.

## When to skip

- Thumb-up: optional reason; if the user provides one, store it under `recent_up_reasons` (future extension). v1 only stores down reasons.
- No rating: do not call the ability. Silence is not a signal.

## What NOT to do

- Do NOT store user PII (email, name) in the reason field. The agent should paraphrase before sending if the user's reason includes anything sensitive.
- Do NOT aggregate per-user (yet). v1 is per `(brand, element_type)` only.
- Do NOT overwrite the option without reading it first — concurrent writes from parallel conversions would race. The current implementation reads → mutates → writes inside one PHP request, which is acceptable for single-agent use.
