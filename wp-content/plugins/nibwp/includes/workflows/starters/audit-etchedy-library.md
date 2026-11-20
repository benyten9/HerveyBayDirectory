# Audit the Etchedy Library

Using **NibWP** and the **Etchedy Pro** skill, check the components stored in the Etchedy library against the rules a fresh save would face today, and fix what no longer passes. The rules tighten over time; a library that was clean when a component went in is not necessarily clean now.

## When to use
- "Check my Etchedy library."
- Before handing a client a library, or before reusing components on a new project.
- After the build rules or the design system changed.

## Principles
- **Read-only first.** The audit changes nothing. Fixing is a separate, confirmed step.
- **Start narrow.** Each component costs a read. Use a `category` or `type` filter on a large library, and raise the filter's precision — not the limit — if a run reports `truncated`.
- **Your components, by default.** The official library is not the customer's to fix, and locked items cannot be read; the audit says which it skipped and why.
- **A failed rule is a fix to make; a warning is a decision.**

## Process
1. **Scope it.** Ask what to check, or start with the components saved on this site (`scope: user`, the default). Add `category` or `type` when the library is large.
2. **Run it.** `nibwp/etchedy-pro-audit-library`. Read `checked`, `passed`, `warned`, `failed`, `skipped` and `truncated`. If `truncated` is true, `remaining` says how many were not reached — narrow the filter and run again.
3. **Triage.** Group findings by rule: `site_specific_id`, `site_specific_url` and `unbound_field` are failures that make a component break elsewhere; `no_properties` and `property_no_default` are warnings that make it a snapshot. Build-rule failures (hardcoded colour or font size, an unknown token, a missing brand prefix) come from the EtchWP rules.
4. **Propose fixes.** For each failed component, say what the rule means in plain words and what the change would be. Do not write anything yet.
5. **Fix with consent.** For each component the user approves: `nibwp/etchedy-manage` `action: get`, change the payload, `action: update` with the whole component. Run the audit again on that component with `id` to confirm it passes.
6. **Report what was skipped.** Locked components need a higher Etchedy tier; unreadable ones need a look at the file.

## Rules
**Do**
- Run the audit with a filter on anything but a small library.
- Quote each finding's `rule`, `msg` and `path` when explaining it.
- Re-audit a component by `id` after fixing it.
- Report `skipped` and `truncated` honestly.

**Don't**
- Treat a clean result as complete if the run was `truncated` or components were `skipped`.
- Fix anything without the user's yes — an update replaces the stored component.
- Raise the limit to cover a large library; narrow the filter.
- Audit the official library to "fix" it.

## Validation
- Every failed component re-audits clean, or the user has chosen to leave it.
- The report accounts for every component in scope: passed, warned, failed, or skipped with a reason.
- No component was changed without confirmation.

## Report
**Checked:** N (passed N · warned N · failed N). **Fixed:** list, each re-audited clean. **Left as is:** list, with the user's reason. **Skipped:** locked / unreadable, with reasons. **Not reached:** N, if truncated — and the filter to use next.
