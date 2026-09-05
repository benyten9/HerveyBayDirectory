# Contact Page Slot Inventory

**Purpose:** Catalog all Pro-only components and extensions that should become WordPress filter slots in the free plugin's contact details page. This enables Pro to extend free without forking code.

**Summary:** 16 distinct slots identified across 5 categories. Highest risks are booking lifecycle support (modifies activity rendering logic) and custom fields tab gating (needs free restructuring).

---

## Tab Components (Full Replacement)

| Slot Name | Location in Tree | Props | Return | Category | Risk/Notes |
|-----------|------------------|-------|--------|----------|-----------|
| `doublescale_contact_tab_component_sms` | Tab strip, SMS tab | `contact_id: number`, `navigate?: (path: string) => void` | `ReactNode` (full tab) | clean-additive | Pro replaces placeholder; adds send-sms-dialog, sms-details-dialog, provider config |
| `doublescale_contact_tab_component_whatsapp` | Tab strip, WhatsApp tab | `contact_id: number`, `navigate?: (path: string) => void` | `ReactNode` (full tab) | clean-additive | Pro replaces null default; adds whatsapp-chat, multiple dialogs, view mode toggle (table/chat) |
| `doublescale_contact_tab_component_website_tracking` | Tab strip, Website Tracking tab | `contact_id: number`, `navigate?: (path: string) => void` | `ReactNode` (full tab) | clean-additive | Pro full implementation; free shows pro-feature notice |
| `doublescale_contact_tab_component_lead_score` | Tab strip, Lead Score tab | `contact_id: number`, `navigate?: (path: string) => void` | `ReactNode` (full tab) | clean-additive | Pro tab wraps lead-score-card; free shows pro-feature notice |

---

## Sidebar - Contact Information Card

| Slot Name | Location in Tree | Props | Return | Category | Risk/Notes |
|-----------|------------------|-------|--------|----------|-----------|
| `doublescale_contact_sidebar_info_card_custom_tab` | Sidebar, Contact Info card, custom fields tab | `contact: Contact` | `ReactNode` | clean-additive | Free gates custom tab with ProFeatureNotice; Pro removes gate, shows full custom fields editor with `useCustomFields` hook from `@doublescale-free/hooks` |

---

## Activities Feed - New Activity Types

| Slot Name | Location in Tree | Props | Return | Category | Risk/Notes |
|-----------|------------------|-------|--------|----------|-----------|
| `doublescale_contact_activities_booking_icon` | Activities feed, icon map | (none; pure icon) | `ReactNode` (icon) | clean-additive | Pro adds 7 booking-related icons: `booking_scheduled`, `booking_confirmed`, `booking_pending`, `booking_rescheduled`, `booking_cancelled`, `booking_completed`, `booking_rejected` |
| `doublescale_contact_activities_booking_styles` | Activities feed, badge style map | (none; pure CSS) | `string` (Tailwind classes) | clean-additive | Pro adds 7 badge style entries for booking activity types |
| `doublescale_contact_activities_booking_renderer` | Activities feed, activity detail card | `activity: Activity` with `type: booking_*`, `data: { event_name, scheduled_at, host_name, duration, details_url }` | `ReactNode` | modifies-behavior | **RISK:** Pro inlines booking detail rendering inside switch statement; free needs slot hook in renderActivityContent. This changes free's activity rendering architecture. |

---

## SMS Tab - Pro Extensions

| Slot Name | Location in Tree | Props | Return | Category | Risk/Notes |
|-----------|------------------|-------|--------|----------|-----------|
| `doublescale_contact_sms_provider_modal` | SMS tab, config modal | `open: boolean`, `onClose: () => void`, `onSuccess?: () => void` | `ReactNode` | clean-additive | TwilioConfigModal: Pro-only component. Free SMS is placeholder; Pro opens config in same context. |
| `doublescale_contact_sms_send_dialog` | SMS tab, send message | `open: boolean`, `onClose: () => void`, `contact: Contact | null` | `ReactNode` | clean-additive | SendSMSDialog with AI prompt support (if configured); uses `useSendMessage` hook |
| `doublescale_contact_sms_details_dialog` | SMS tab, message viewer | `smsMessage: TrackedMessage \| null`, `onClose: () => void` | `ReactNode` | clean-additive | SMSDetailsDialog displays full SMS body, status, metadata |

---

## WhatsApp Tab - Pro Extensions

| Slot Name | Location in Tree | Props | Return | Category | Risk/Notes |
|-----------|------------------|-------|--------|----------|-----------|
| `doublescale_contact_whatsapp_send_dialog` | WhatsApp tab, send message | `open: boolean`, `onClose: () => void`, `contact: Contact | null` | `ReactNode` | clean-additive | SendWhatsAppDialog with template support, variable substitution, scheduling |
| `doublescale_contact_whatsapp_details_dialog` | WhatsApp tab, message viewer | `whatsappMessage: TrackedMessage \| null`, `onClose: () => void` | `ReactNode` | clean-additive | WhatsAppDetailsDialog displays message, status, read receipts |
| `doublescale_contact_whatsapp_chat` | WhatsApp tab, chat view | `messages: TrackedMessage[]`, `contact: Contact | null` | `ReactNode` | clean-additive | WhatsappChat component: alternative "chat" view mode (toggleable with icons in header) |

---

## Website Tracking Tab - Pro Extension

| Slot Name | Location in Tree | Props | Return | Category | Risk/Notes |
|-----------|------------------|-------|--------|----------|-----------|
| `doublescale_contact_website_tracking_columns` | Website Tracking tab, table columns | (none; export function) | `ColumnDef<PageVisit>[]` | clean-additive | Free placeholder tab; Pro provides full columns definition with page URL, visit count, timestamps, etc. |

---

## Lead Scoring - Sidebar Card

| Slot Name | Location in Tree | Props | Return | Category | Risk/Notes |
|-----------|------------------|-------|--------|----------|-----------|
| `doublescale_contact_lead_score_card` | Sidebar, after Lists/Tags, before Info card | `contact: Contact \| null` | `ReactNode` | shared-with-extension | LeadScoreCardContent component reads `contact.lead_score` data (if present) and displays level + points. Free has null card; Pro mounts actual component. Requires free to accept the slot gracefully. |

---

## Structural Constraints & Migration Risks

### High-Risk: Booking Activity Rendering (modifies-behavior)

**Finding:** Pro adds 7 new booking activity type renderers inline in the `renderActivityContent()` switch statement within `activities/index.tsx` (lines 591-651 in Pro). Free's activities feed doesn't know about `booking_*` types at all.

**Why it's risky:** 
- Free's activity feed was not designed for extensibility of new type handlers. Adding a booking renderer requires either:
  1. Free exposes a slot hook inside `renderActivityContent()` for type-specific rendering (breaks inline architecture), OR
  2. Free must pre-declare booking types in icon maps & style maps before Pro can provide renderers.
- Current Pro approach is a pure fork; a slot would require free to add booking type enums/constants and call `applyFilters()` for each case.

**Mitigation:** Free should add booking lifecycle types to its activity type enum and expose a generic activity type renderer slot that Pro can intercept.

### Medium-Risk: Custom Fields Tab Gating

**Finding:** Free's `contact-information/info-card/index.tsx` gates the "Custom" tab with a `ProFeatureNotice` if `!config.getProPluginData()?.is_active`. Pro removes this check entirely and imports `useCustomFields` from `@doublescale-free/hooks/use-custom-fields`.

**Why it's risky:**
- The free hook path `@doublescale-free/hooks/use-custom-fields` doesn't exist in free yet; it's only in Pro's alias setup.
- Pro expects free to export a custom-fields hook for field listing/editing, but free doesn't export it.
- Free would need to restructure the info-card to accept a custom-fields slot callback instead of importing a hook that doesn't exist.

**Mitigation:** Free should either export the hook or move custom fields tab content into a slot callback.

### Low-Risk: SMS/WhatsApp Dialogs & Provider Config

- **SMS SendDialog, Details, Config:** All are additive (free has no SMS at all, just a placeholder tab). Slots are straightforward.
- **WhatsApp SendDialog, Details, Chat:** Same; all additive.
- **Website Tracking columns:** Additive; free exports columns function for Pro to override.

---

## Implementation Notes

1. **Tab Components Already Use applyFilters:** DataCard.tsx already has infrastructure:
   ```javascript
   const SMS = applyFilters('doublescale_contact_tab_component', SMSBase, 'sms');
   ```
   This makes the SMS, WhatsApp, Website Tracking, and LeadScore tab slots trivial to implement.

2. **Lead Score Card Slot:** Free's ContactInformation component should wrap the card slot:
   ```javascript
   const LeadScoreCard = applyFilters('doublescale_contact_lead_score_card', null);
   if (LeadScoreCard) return <LeadScoreCard contact={contact} />;
   ```

3. **Custom Fields Hook:** Free should export `useCustomFields` or accept a custom-fields tab renderer slot in `info-card/index.tsx`. Pro then uses the hook or provides a full renderer component.

4. **Activity Type Rendering:** Most complex refactor. Free should:
   - Add booking type enums to activity types
   - Create icon/style maps that can be filtered
   - Move the switch statement to a callback-based approach or wrap each case in a filter

5. **Dialogs (SMS, WhatsApp, Website Tracking):** Straightforward imports/mounts in the free version of each tab. No restructuring needed.

---

## Slot Naming Convention

All slots follow the pattern:
```
doublescale_contact_<section>_<subsection>_<aspect>
```

Where:
- **section** = `tab`, `sidebar`, `activities`, `sms`, `whatsapp`, `website_tracking`, `lead_score`
- **subsection** = optional (e.g., `info_card`, `provider`, `send_dialog`)
- **aspect** = what's being extended (`component`, `modal`, `dialog`, `card`, `renderer`, `icons`, `styles`, `columns`)

---

## High-Level Slot List (Quick Reference)

### Direct Tab Replacement (Lowest Effort)
1. `doublescale_contact_tab_component_sms`
2. `doublescale_contact_tab_component_whatsapp`
3. `doublescale_contact_tab_component_website_tracking`
4. `doublescale_contact_tab_component_lead_score`

### Sidebar Extensions (Low Effort)
5. `doublescale_contact_lead_score_card`
6. `doublescale_contact_sidebar_info_card_custom_tab`

### SMS Tab (Low Effort)
7. `doublescale_contact_sms_provider_modal`
8. `doublescale_contact_sms_send_dialog`
9. `doublescale_contact_sms_details_dialog`

### WhatsApp Tab (Low Effort)
10. `doublescale_contact_whatsapp_send_dialog`
11. `doublescale_contact_whatsapp_details_dialog`
12. `doublescale_contact_whatsapp_chat`

### Website Tracking Tab (Low Effort)
13. `doublescale_contact_website_tracking_columns`

### Activities Feed (High Effort - Requires Architecture Change)
14. `doublescale_contact_activities_booking_icon`
15. `doublescale_contact_activities_booking_styles`
16. `doublescale_contact_activities_booking_renderer`

---

## Summary by Category

| Category | Count | Effort | Risk |
|----------|-------|--------|------|
| **clean-additive** | 12 | Low–Medium | Low |
| **shared-with-extension** | 2 | Low | Low–Medium |
| **modifies-behavior** | 1 | High | High |
| **needs-shared-util** | 1 | Medium | Medium |

