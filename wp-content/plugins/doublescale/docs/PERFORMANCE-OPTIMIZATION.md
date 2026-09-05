# DoubleScale Performance Optimization Guide

**Date:** July 2026  
**Context:** Investigation of slowness on `doublescale.io` and implications for DoubleScale / DoubleScale Pro on customer sites.

This document summarizes root causes, fixes already shipped, measured results, and recommended next steps for developers.

---

## Executive Summary

Site slowness was **not a single bug**. It was a stack of issues:

1. **Production debug logging** writing ~185 lines per request to a **2.6 GB** log file  
2. **Full CRM kernel booting on every page view** (vendor + all modules)  
3. **No page caching** on the marketing site  
4. **Heavy plugin stack** (~30 plugins including 15+ EDD extensions)  
5. **Undersized server** (2 GB RAM, 10 PHP-FPM workers)

Lazy kernel boot (shipped) improved browse-page performance significantly. **Page caching** remains the largest win for visitor-perceived speed.

---

## Measured Results (doublescale.io)

### Before any fixes

| Metric | Value |
|--------|-------|
| Homepage TTFB (worst) | ~31s |
| Server load average | ~11 |
| `debug.log` size | 2.6 GB / 6.6M lines |
| Log growth per request | ~185 lines / ~25 KB |

### After debug off + log cleared + memory raised

| Page | Avg TTFB (server) |
|------|-------------------|
| Homepage | ~5.5s |
| Pricing | ~6.7s |
| REST API | ~5.1s |

### DoubleScale deactivated (A/B test)

| Page | Avg TTFB |
|------|----------|
| Homepage | ~6.9s |
| Pricing | ~1.8s |
| My Account | ~0.9s |
| REST API | ~1.2s |

**Conclusion:** DoubleScale full boot was a major contributor, but not the only one. Other plugins + no cache still add ~3–5s.

### Lazy boot shipped (tracking off)

| Check | Result |
|-------|--------|
| `DOUBLESCALE_LAZY_BOOT` | `yes` |
| Kernel booted on homepage | `no` |
| Vendor loaded on homepage | `no` |
| Homepage avg TTFB | **~4.8s** |

### Website tracking enabled (forces full boot in v1)

| Check | Result |
|-------|--------|
| `DOUBLESCALE_LAZY_BOOT` | `no` |
| Kernel booted on homepage | `yes` |
| Homepage avg TTFB | **~8.5s** |

Static assets (CSS) load in **~0.12s** — nginx is fine; PHP/WordPress is the bottleneck.

### Targets

| Context | Target TTFB |
|---------|-------------|
| Cached marketing page | < 200ms |
| Acceptable dynamic WP | < 500ms |
| Current uncached homepage | ~5–8s |

---

## Root Cause Breakdown

### Total slowness ≈

```
WordPress core
+ Other plugins (EDD, Wordfence, Rank Math, …)   ← always present on marketing site
+ DoubleScale vendor autoload                       ← only on full boot
+ DoubleScale module boot (Automations, Admin, …)   ← only on full boot
+ No page caching                                   ← every visitor pays full PHP cost
+ Server queuing (2 GB RAM, 10 workers)             ← causes 1.5s–16s variance
```

### DoubleScale boot chain (before lazy boot)

1. `Lifecycle::load_dependencies()` → loads entire Composer vendor (`scoper-autoload.php`)
2. `plugins_loaded` → `Bootstrap::init()` → `PluginKernel`
3. Discovers and boots **all** modules under `includes/Modules/`
4. `CoreModule::boot()` instantiates Admin, UserRoles, SubscriptionManager, etc. on every request
5. Automations module glob-loads **100+ trigger/action PHP files** on every full boot

`AdminLoader::is_admin_page()` only gates **script enqueuing**, not kernel boot.

---

## Fixes Already Shipped

### Live server (`doublescale.io`)

| Fix | Details |
|-----|---------|
| `WP_DEBUG` / `WP_DEBUG_LOG` | Set to `false` in production |
| Debug log | Deleted 2.6 GB backup; truncated `debug.log` |
| `WP_MEMORY_LIMIT` | 256M / `WP_MAX_MEMORY_LIMIT` 512M in `wp-config.php` |

### DoubleScale plugin (local + live test deploy)

| File | Purpose |
|------|---------|
| `includes/Core/RequestContext.php` | Decides full vs lazy boot per request |
| `includes/Core/KernelLoader.php` | Boots vendor + kernel only when needed |
| `includes/Lifecycle.php` | Splits core vs vendor deps; gates `Bootstrap::init()` |
| `includes/Core/functions.php` | Adds `doublescale_is_lazy_boot()`, `doublescale_ensure_kernel_booted()` |
| `doublescale-pro/includes/pro-vendor-loader.php` | Defers Pro vendor until full boot |
| `doublescale-pro/doublescale-pro.php` | Uses deferred vendor loader |

**Backups on live:** `wp-content/plugins/doublescale/includes/Core/.bak-20260721-043114/`

### Escape hatch (support / debugging)

Add to `wp-config.php` to disable lazy boot:

```php
define( 'DOUBLESCALE_DISABLE_LAZY_BOOT', true );
```

Filter override:

```php
add_filter( 'doublescale_force_full_kernel', '__return_true' );
add_filter( 'doublescale_boot_full_kernel', fn() => true ); // or false
```

---

## Lazy Boot Rules (v1)

### Full kernel boots when

- `is_admin()` or admin-adjacent (`wp-login.php`, etc.)
- `wp_doing_ajax()`, `wp_doing_cron()`, `WP_CLI`
- `REST_REQUEST` or URI contains `/wp-json/`
- Public CRM routes: tracking params (`?doublescale=&hash_key=`), unsubscribe, attachment serve (`ds_file`), booking URLs, `admin-ajax.php?action=doublescale_*`
- Commerce URLs: checkout, cart, `edd_action=`, `wc-ajax=`, etc.
- Logged-in user with `doublescale_*` role or `manage_options`
- Website tracking **explicitly enabled** in saved `doublescale_settings` (see limitation below)

### Lazy boot (skip vendor + kernel) when

- Anonymous visitor on a normal browse page (homepage, blog, pricing without checkout params)
- None of the full-boot conditions above apply

### Known limitation (v1)

When **website tracking is enabled**, `RequestContext` forces **full boot** on every frontend page — not just the WebsiteTracking module. This adds ~4s vs lazy boot. **Module-scoped boot** is the planned fix.

When tracking settings are **missing**, v1 treats tracking as off (do not force full boot).

---

## Vendor / SDK Analysis

### Mail providers

| Provider | Implementation | Approx. size |
|----------|----------------|--------------|
| Mailgun, SparkPost, Mailjet, etc. | `wp_remote_post()` + JSON | Lightweight |
| SendGrid | Full SDK | ~860 KB |
| Postmark | SDK | ~148 KB |
| Brevo (Sendinblue) | OpenAPI SDK (hundreds of models) | **~6.4 MB** |
| WPEloquent / Illuminate | Core CRM ORM | ~2.5 MB (required) |

### Impact of vendor on performance

- **Browse pages with lazy boot:** vendor **not loaded** — SDKs irrelevant for speed  
- **Full boot (admin, tracking, sending):** entire `scoper-autoload.php` registers all vendor classes upfront, even if site only uses Mailgun  

Replacing Brevo/SendGrid/Postmark SDKs with direct HTTP (like Mailgun) would:

- Reduce plugin size and full-boot autoload cost  
- Simplify PHP 8.4 compatibility  
- **Not** replace lazy boot or page caching for marketing pages  

**Recommendation:** Replace **Brevo first** (largest bloat), then lazy-load SMTP providers by connected account slug.

### Direct API endpoints (reference)

```
POST https://api.sendgrid.com/v3/mail/send
POST https://api.postmarkapp.com/email
POST https://api.brevo.com/v3/smtp/email
```

See `includes/Modules/Smtp/Providers/mailgun/class-account-api.php` for the preferred pattern.

---

## Recommended Next Steps

### Priority 1 — doublescale.io (marketing site)

| # | Action | Impact |
|---|--------|--------|
| 1 | **Page caching** (WP Rocket, Flying Press, LiteSpeed, or nginx FastCGI) | ★★★★★ — TTFB < 200ms for cached pages |
| 2 | Disable website tracking on marketing site **or** ship module-scoped boot | ★★★ — avoids full CRM boot on every page |
| 3 | Deactivate dev plugins (Duplicator, WP Migrate DB, demo receiver, under-construction) | ★★ |
| 4 | Audit EDD extensions — disable unused | ★★★ |
| 5 | Set PHP memory 256M in Plesk panel | ★ |
| 6 | Consider Redis object cache | ★★★ |
| 7 | Consider more RAM / PHP workers if load spikes persist | ★★★★ |

### Priority 2 — DoubleScale product (next release)

| # | Action | Impact |
|---|--------|--------|
| 1 | **Module-scoped public boot** — tracking, portal, unsubscribe without full Automations/Admin stack | ★★★★ |
| 2 | **Defer Automations catalog** — load trigger globs only for REST automation endpoints / admin builder | ★★★★ |
| 3 | **Replace Brevo SDK** with `wp_remote_post()` | ★★★ |
| 4 | **Per-provider SMTP lazy load** — only load SDK for connected provider | ★★★ |
| 5 | **Split `CoreModule::boot()`** into admin / public / integration slices | ★★ |

### Priority 3 — Following release

| # | Action | Impact |
|---|--------|--------|
| 1 | Replace SendGrid + Postmark SDKs with direct API | ★★ |
| 2 | Lazy-load integration triggers (WC/EDD) by active plugins only | ★★★ |
| 3 | PHP 8.4 deprecation cleanup in remaining vendor usage | ★ |

---

## Architecture: Target Boot Tiers

```
Tier 0 — Always
  Constants, activation hooks, RequestContext, KernelLoader stub

Tier 1 — Lazy (browse pages)
  Nothing (current v1) OR minimal hooks only

Tier 2 — Public modules (planned)
  core (public slice) + contacts + websitetracking | portal | forms embed
  Vendor loaded; Eloquent initialized; Automations NOT loaded

Tier 3 — Integration (planned)
  Tier 2 + automation runtime hooks for active WC/EDD/forms integrations only

Tier 4 — Full kernel
  Admin, REST CRM, cron, AJAX, CLI, campaign sending, automation builder
  All modules, full vendor, Automations catalog
```

### Module-scoped boot sketch

```php
// RequestContext.php — future
if ( self::needs_public_modules_only() ) {
    return 'public_modules';
}

// KernelLoader.php — future
public static function boot_public_modules( array $modules ): void {
    self::load_vendor();
    PluginKernel::instance()->boot_modules( $modules );
    do_action( 'doublescale_ready' );
}
```

Requires `PluginKernel` / `ModuleRegistry` to support partial module boot and splitting `CoreModule::boot()`.

---

## What Improves What (Quick Reference)

| Goal | Best levers |
|------|-------------|
| **Visitor homepage fast** | Page cache → lazy boot (done) → module-scoped tracking → remove CRM from marketing site if not needed |
| **Admin / CRM app** | Acceptable to be heavy; defer Automations catalog; per-provider SMTP load |
| **Plugin zip / install size** | Remove Brevo SDK; trim unused vendor |
| **Server stability** | More RAM; Redis; OPcache; reduce concurrent full boots |

---

## Files to Review

| Path | Relevance |
|------|-----------|
| `includes/Lifecycle.php` | Boot entry, vendor deferral |
| `includes/Core/RequestContext.php` | Full vs lazy decision tree |
| `includes/Core/KernelLoader.php` | Full boot orchestration |
| `includes/Core/PluginKernel.php` | Module discovery (all modules today) |
| `includes/Core/CoreModule.php` | Boots admin stack unconditionally on full boot |
| `includes/Modules/Automations/Module.php` | Glob-loads trigger/action files |
| `includes/Modules/Smtp/Providers/sendinblue/` | Brevo SDK usage |
| `includes/Modules/Smtp/Providers/sendgrid/` | SendGrid SDK usage |
| `includes/Modules/Smtp/Providers/postmark/` | Postmark SDK usage |
| `includes/Modules/Smtp/Providers/mailgun/` | **Reference** direct API pattern |
| `doubleScale-pro/includes/Modules/WebsiteTracking/Website.php` | Frontend tracking script + REST |
| `dependencies/build/vendor/scoper-autoload.php` | Eager class registration |

---

## Testing Checklist

After any performance change, verify:

- [ ] Homepage: lazy boot when tracking off (`DOUBLESCALE_LAZY_BOOT` defined, no `PluginKernel`)
- [ ] Homepage: tracking script + `/wp-json/doublescale/v1/tracking/page-view` when tracking on
- [ ] wp-admin → DoubleScale loads without error
- [ ] REST `/wp-json/doublescale/v1/` returns 200
- [ ] EDD checkout / purchase completes; automation triggers fire
- [ ] Email tracking links (`?doublescale=&hash_key=`) work
- [ ] Unsubscribe / preference links work
- [ ] Booking / portal / attachment download URLs work
- [ ] Campaign send + SMTP providers (Mailgun, SendGrid, Brevo, Postmark) still send
- [ ] Cron / Action Scheduler jobs for `doublescale_*` groups run
- [ ] Plugin activation / deactivation on fresh site

### Benchmark commands (server)

```bash
# TTFB (5 runs)
for i in 1 2 3 4 5; do
  curl -s -o /dev/null -w "TTFB=%{time_starttransfer}s Total=%{time_total}s\n" "https://example.com/"
done

# Boot mode check (WP-CLI / php eval)
# lazy_boot, kernel_booted, vendor_loaded — see RequestContext
```

---

## Notes for doublescale.io Specifically

- **DoubleScale CRM on the marketing site** is optional — EDD, theme, and AffiliateWP handle sales; AppSumo licensing lives in the theme.
- Keeping DoubleScale active for dogfooding is fine with **lazy boot + tracking off** or **module-scoped boot** (once shipped).
- **Never enable `WP_DEBUG_LOG` in production** on customer-facing sites.

---

## Questions / Ownership

| Area | Suggested owner |
|------|-----------------|
| Lazy boot + RequestContext refinements | Core plugin team |
| Module-scoped boot | Core + Pro (WebsiteTracking, Portal) |
| Automations deferral | Automations module team |
| Brevo / SDK removal | SMTP module team |
| doublescale.io caching & server | DevOps / hosting |
| EDD extension audit | Marketing site maintainer |

---

*Generated from production investigation on doublescale.io, July 2026.*
