#!/usr/bin/env bash
#
# REST API smoke test for DoubleScale Pro.
#
# Hits every major endpoint group via cookie+nonce auth against the local WP.
# CRUD cycles for entity endpoints; GET-only for read/analytics endpoints.
# Creates test data, verifies HTTP status codes, then cleans up.
#
# Usage:  bash tools/test-rest-smoke.sh
# Env:    WP_USER  (default: ahmed)
#         WP_PASS  (default: TestAdmin123!)
#         WP_URL   (default: http://localhost/wordpress)

set -euo pipefail

WP_URL="${WP_URL:-http://localhost/wordpress}"
WP_USER="${WP_USER:-ahmed}"
WP_PASS="${WP_PASS:-TestAdmin123!}"
BASE="${WP_URL}/wp-json/doublescale/v1"
COOKIES=$(mktemp)
PASS_COUNT=0
FAIL_COUNT=0
TOTAL=0

trap 'rm -f "$COOKIES"' EXIT

##############################################################################
# Helpers
##############################################################################

auth() {
  curl -s -c "$COOKIES" \
    -d "log=${WP_USER}&pwd=$(python3 -c "import urllib.parse; print(urllib.parse.quote('${WP_PASS}'))")&wp-submit=Log+In&redirect_to=/wordpress/wp-admin/&testcookie=1" \
    -b "wordpress_test_cookie=WP+Cookie+check" \
    -L "${WP_URL}/wp-login.php" -o /dev/null
  NONCE=$(curl -s -b "$COOKIES" "${WP_URL}/wp-admin/admin-ajax.php?action=rest-nonce")
  if [[ -z "$NONCE" || "$NONCE" == "0" ]]; then
    echo "FATAL: could not obtain REST nonce. Check WP_USER / WP_PASS."
    exit 1
  fi
  echo "Authenticated as ${WP_USER}, nonce=${NONCE}"
}

api() {
  local method="$1" path="$2" data="${3:-}"
  local extra=()
  if [[ -n "$data" ]]; then
    extra=(-H "Content-Type: application/json" -d "$data")
  fi
  local tmp; tmp=$(mktemp)
  HTTP_CODE=$(curl -s -o "$tmp" -w "%{http_code}" \
    -X "$method" \
    -b "$COOKIES" \
    -H "X-WP-Nonce: ${NONCE}" \
    "${extra[@]}" \
    "${BASE}${path}")
  BODY=$(cat "$tmp")
  rm -f "$tmp"
}

assert() {
  local label="$1" expected="$2"
  TOTAL=$((TOTAL + 1))
  if [[ "$HTTP_CODE" == "$expected" ]]; then
    echo "[PASS] ${label} (${HTTP_CODE})"
    PASS_COUNT=$((PASS_COUNT + 1))
  else
    echo "[FAIL] ${label} (${HTTP_CODE}) -- expected ${expected}"
    echo "       ${BODY:0:200}"
    FAIL_COUNT=$((FAIL_COUNT + 1))
  fi
}

json_id() {
  echo "$BODY" | python3 -c "import sys,json; print(json.load(sys.stdin).get('id',''))" 2>/dev/null || echo ""
}

json_field() {
  local field="$1"
  echo "$BODY" | python3 -c "import sys,json; print(json.load(sys.stdin).get('${field}',''))" 2>/dev/null || echo ""
}

##############################################################################
# Auth
##############################################################################
echo "=== DoubleScale REST Smoke Test ==="
echo "Target: ${BASE}"
auth
echo ""

TS=$(date +%s)

##############################################################################
# 1. Contacts  (create→200, get→200, update→200, delete→200)
##############################################################################
echo "--- Contacts ---"
api POST /contacts "{\"email\":\"smoketest-${TS}@example.com\",\"first_name\":\"Smoke\",\"last_name\":\"Test\"}"
assert "contacts: create" "200"
CONTACT_ID=$(json_id)

if [[ -n "$CONTACT_ID" ]]; then
  api GET "/contacts/${CONTACT_ID}";  assert "contacts: get" "200"
  api PUT "/contacts/${CONTACT_ID}" "{\"first_name\":\"Updated\"}"; assert "contacts: update" "200"
fi

##############################################################################
# 2. Tags  (create→201, delete→200)
##############################################################################
echo ""
echo "--- Tags ---"
api POST /tags "{\"name\":\"smoke-tag-${TS}\"}"
assert "tags: create" "201"
TAG_ID=$(json_id)

if [[ -n "$TAG_ID" ]]; then
  api GET "/tags/${TAG_ID}";  assert "tags: get" "200"
  api PUT "/tags/${TAG_ID}" "{\"name\":\"smoke-tag-updated\"}"; assert "tags: update" "200"
  api DELETE "/tags/${TAG_ID}"; assert "tags: delete" "200"
fi

##############################################################################
# 3. Lists  (create→201, delete→200)
##############################################################################
echo ""
echo "--- Lists ---"
api POST /lists "{\"name\":\"smoke-list-${TS}\"}"
assert "lists: create" "201"
LIST_ID=$(json_id)

if [[ -n "$LIST_ID" ]]; then
  api GET "/lists/${LIST_ID}";  assert "lists: get" "200"
  api PUT "/lists/${LIST_ID}" "{\"name\":\"smoke-list-updated\"}"; assert "lists: update" "200"
  api DELETE "/lists/${LIST_ID}"; assert "lists: delete" "200"
fi

##############################################################################
# 4. Pipelines + Deals  (pipeline create→201, deal create→200)
##############################################################################
echo ""
echo "--- Pipelines ---"
api POST /pipelines "{\"name\":\"Smoke Pipeline ${TS}\",\"stages\":[{\"name\":\"Stage 1\",\"probability\":50}]}"
assert "pipelines: create" "201"
PIPELINE_ID=$(json_id)
# Extract stage ID from create response
STAGE_ID=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); s=d.get('stages',[]); print(s[0]['id'] if s else '')" 2>/dev/null || echo "")

if [[ -n "$PIPELINE_ID" ]]; then
  api GET "/pipelines/${PIPELINE_ID}";  assert "pipelines: get" "200"
  api PUT "/pipelines/${PIPELINE_ID}" "{\"name\":\"Smoke Pipeline Updated\"}"; assert "pipelines: update" "200"
fi

echo ""
echo "--- Deals ---"
DEAL_CONTACT="${CONTACT_ID:-1}"
if [[ -n "$PIPELINE_ID" && -n "$STAGE_ID" ]]; then
  api POST /deals "{\"title\":\"Smoke Deal ${TS}\",\"pipeline_id\":${PIPELINE_ID},\"stage_id\":${STAGE_ID},\"contact_id\":${DEAL_CONTACT},\"value\":100}"
  assert "deals: create" "201"
  DEAL_ID=$(json_id)

  if [[ -n "$DEAL_ID" ]]; then
    api GET "/deals/${DEAL_ID}";  assert "deals: get" "200"
    api PUT "/deals/${DEAL_ID}" "{\"title\":\"Smoke Deal Updated\"}"; assert "deals: update" "200"
    api DELETE "/deals/${DEAL_ID}"; assert "deals: delete" "200"
  fi
else
  echo "[SKIP] deals (no pipeline/stage)"
fi

if [[ -n "$PIPELINE_ID" ]]; then
  api DELETE "/pipelines/${PIPELINE_ID}"; assert "pipelines: delete" "200"
fi

##############################################################################
# 5. Tasks  (create→201, delete→200; needs contact_id, assigned_to)
##############################################################################
echo ""
echo "--- Tasks ---"
TASK_CONTACT="${CONTACT_ID:-1}"
api POST /tasks "{\"title\":\"Smoke Task ${TS}\",\"task_type\":\"todo\",\"priority\":\"medium\",\"due_date\":\"2026-12-31\",\"status\":\"pending\",\"contact_id\":${TASK_CONTACT},\"assigned_to\":1}"
assert "tasks: create" "201"
TASK_ID=$(json_id)

if [[ -n "$TASK_ID" ]]; then
  api GET "/tasks/${TASK_ID}";  assert "tasks: get" "200"
  api PUT "/tasks/${TASK_ID}" "{\"title\":\"Smoke Task Updated\"}"; assert "tasks: update" "200"
  api DELETE "/tasks/${TASK_ID}"; assert "tasks: delete" "200"
fi

##############################################################################
# 6. Automations  (create→201; needs name+trigger with valid slug)
##############################################################################
echo ""
echo "--- Automations ---"
api POST /automations "{\"name\":\"Smoke Automation ${TS}\",\"trigger\":\"contact_subscribed\",\"status\":\"inactive\"}"
assert "automations: create" "201"
AUTO_ID=$(json_id)

if [[ -n "$AUTO_ID" ]]; then
  api GET "/automations/${AUTO_ID}";  assert "automations: get" "200"
  api PUT "/automations/${AUTO_ID}" "{\"name\":\"Smoke Automation Updated\"}"; assert "automations: update" "200"
  api DELETE "/automations/${AUTO_ID}"; assert "automations: delete" "204"
fi

api GET /automations/triggers; assert "automations: triggers list" "200"
api GET /automations/actions;  assert "automations: actions list" "200"
api GET /automations/rules;    assert "automations: rules list" "200"
api GET /automations/goals;    assert "automations: goals list" "200"

##############################################################################
# 7. Campaigns  (create→201, delete→204)
##############################################################################
echo ""
echo "--- Campaigns ---"
api POST /campaigns "{\"name\":\"Smoke Campaign ${TS}\",\"type\":\"email\",\"status\":\"draft\"}"
assert "campaigns: create" "201"
CAMP_ID=$(json_id)

if [[ -n "$CAMP_ID" ]]; then
  api GET "/campaigns/${CAMP_ID}";  assert "campaigns: get" "200"
  api PUT "/campaigns/${CAMP_ID}" "{\"name\":\"Smoke Campaign Updated\"}"; assert "campaigns: update" "200"
  api DELETE "/campaigns/${CAMP_ID}"; assert "campaigns: delete" "204"
fi

##############################################################################
# 8. Templates  (create→201, delete→204)
##############################################################################
echo ""
echo "--- Templates ---"
api POST /templates/save "{\"name\":\"Smoke Template ${TS}\",\"content\":\"<p>test</p>\",\"type\":\"email\"}"
assert "templates: create" "201"
TPL_ID=$(json_id)

if [[ -n "$TPL_ID" ]]; then
  api GET "/templates/${TPL_ID}";  assert "templates: get" "200"
  api PUT "/templates/${TPL_ID}" "{\"name\":\"Smoke Template Updated\"}"; assert "templates: update" "200"
  api DELETE "/templates/${TPL_ID}"; assert "templates: delete" "204"
fi

##############################################################################
# 9. Custom Fields Groups  (create→201, get needs scope param)
##############################################################################
echo ""
echo "--- Custom Fields Groups ---"
api POST /custom-fields-groups "{\"name\":\"Smoke Group ${TS}\",\"scope\":\"contact\"}"
assert "custom-fields-groups: create" "201"
CFG_ID=$(json_id)

if [[ -n "$CFG_ID" ]]; then
  api GET "/custom-fields-groups/${CFG_ID}?scope=contact";  assert "custom-fields-groups: get" "200"
  api PUT "/custom-fields-groups/${CFG_ID}" "{\"name\":\"Smoke Group Updated\"}"; assert "custom-fields-groups: update" "200"
fi

echo ""
echo "--- Custom Fields ---"
GROUP_FOR_CF="${CFG_ID:-1}"
api POST /custom-fields "{\"name\":\"Smoke Field ${TS}\",\"type\":\"text\",\"scope\":\"contact\",\"group_id\":${GROUP_FOR_CF}}"
assert "custom-fields: create" "201"
CF_ID=$(json_id)

if [[ -n "$CF_ID" ]]; then
  api GET "/custom-fields/${CF_ID}";  assert "custom-fields: get" "200"
  api PUT "/custom-fields/${CF_ID}" "{\"name\":\"Smoke Field Updated\"}"; assert "custom-fields: update" "200"
  api DELETE "/custom-fields/${CF_ID}"; assert "custom-fields: delete" "204"
fi

# delete_item on groups requires new_group_id param (to reassign fields); use 0 to discard
if [[ -n "$CFG_ID" ]]; then
  api DELETE "/custom-fields-groups/${CFG_ID}?new_group_id=0"; assert "custom-fields-groups: delete" "204"
fi

##############################################################################
# 10. Activities  (needs contact_id)
##############################################################################
echo ""
echo "--- Activities ---"
ACT_CONTACT="${CONTACT_ID:-1}"
api POST /activities/notes "{\"title\":\"Smoke Note ${TS}\",\"content\":\"Test note\",\"contact_id\":${ACT_CONTACT}}"
assert "activities: create note" "201"
ACT_ID=$(json_id)

if [[ -n "$ACT_ID" ]]; then
  api GET "/activities/${ACT_ID}";  assert "activities: get" "200"
  api PUT "/activities/${ACT_ID}" "{\"title\":\"Smoke Note Updated\"}"; assert "activities: update" "200"
  api DELETE "/activities/${ACT_ID}"; assert "activities: delete" "200"
fi

# Cleanup contact from section 1
if [[ -n "$CONTACT_ID" ]]; then
  api DELETE "/contacts/${CONTACT_ID}"; assert "contacts: delete" "200"
fi

##############################################################################
# 11. Email Sequences  (create→201, delete→204)
##############################################################################
echo ""
echo "--- Email Sequences ---"
api POST /email-sequences "{\"name\":\"Smoke Sequence ${TS}\",\"status\":\"draft\"}"
assert "email-sequences: create" "201"
SEQ_ID=$(json_id)

if [[ -n "$SEQ_ID" ]]; then
  api GET "/email-sequences/${SEQ_ID}";  assert "email-sequences: get" "200"
  api PUT "/email-sequences/${SEQ_ID}" "{\"name\":\"Smoke Sequence Updated\"}"; assert "email-sequences: update" "200"
  api DELETE "/email-sequences/${SEQ_ID}"; assert "email-sequences: delete" "204"
fi

##############################################################################
# 12. Lead Scoring Rules  (create→201; needs title, is_adding, points, status)
##############################################################################
echo ""
echo "--- Lead Scoring Rules ---"
api POST /lead-scoring-rules "{\"title\":\"Smoke Rule ${TS}\",\"is_adding\":true,\"points\":10,\"status\":\"active\"}"
assert "lead-scoring-rules: create" "201"
LSR_ID=$(json_id)

if [[ -n "$LSR_ID" ]]; then
  api GET "/lead-scoring-rules/${LSR_ID}";  assert "lead-scoring-rules: get" "200"
  api PUT "/lead-scoring-rules/${LSR_ID}" "{\"title\":\"Smoke Rule Updated\"}"; assert "lead-scoring-rules: update" "200"
  api DELETE "/lead-scoring-rules/${LSR_ID}"; assert "lead-scoring-rules: delete" "204"
fi

echo ""
echo "--- Lead Scoring Levels ---"
api POST /lead-scoring-levels "{\"name\":\"Smoke Level ${TS}\",\"slug\":\"smoke-${TS}\",\"points\":50,\"color\":\"#ff0000\"}"
assert "lead-scoring-levels: create" "201"
LSL_ID=$(json_id)

if [[ -n "$LSL_ID" ]]; then
  api GET "/lead-scoring-levels/${LSL_ID}";  assert "lead-scoring-levels: get" "200"
  api PUT "/lead-scoring-levels/${LSL_ID}" "{\"name\":\"Smoke Level Updated\"}"; assert "lead-scoring-levels: update" "200"
  api DELETE "/lead-scoring-levels/${LSL_ID}"; assert "lead-scoring-levels: delete" "204"
fi

##############################################################################
# 13. Link Triggers  (create→201, delete→204)
##############################################################################
echo ""
echo "--- Link Triggers ---"
api POST /link-triggers "{\"name\":\"Smoke Trigger ${TS}\",\"url\":\"https://example.com/smoke\",\"status\":\"active\"}"
assert "link-triggers: create" "201"
LT_ID=$(json_id)

if [[ -n "$LT_ID" ]]; then
  api GET "/link-triggers/${LT_ID}";  assert "link-triggers: get" "200"
  api PUT "/link-triggers/${LT_ID}" "{\"name\":\"Smoke Trigger Updated\"}"; assert "link-triggers: update" "200"
  api DELETE "/link-triggers/${LT_ID}"; assert "link-triggers: delete" "204"
fi

##############################################################################
# Read-only / GET endpoints
##############################################################################
echo ""
echo "--- Read-only Endpoints ---"

api GET /general/dashboard;       assert "dashboard" "200"
api GET /settings;                assert "settings" "200"
api GET /logs;                    assert "logs" "200"
api GET /notifications;           assert "notifications" "200"
api GET "/notifications/count";   assert "notifications: count" "200"
api GET /notification-preferences;             assert "notification-preferences" "200"
api GET "/notification-preferences/categories"; assert "notification-preferences: categories" "200"
api GET "/notification-preferences/defaults";   assert "notification-preferences: defaults" "200"
api GET /contacts/analytics;      assert "contacts: analytics" "200"
api GET /contacts/filters;        assert "contacts: filters" "200"
api GET "/campaigns/analytics?channel=email"; assert "campaigns: analytics" "200"
api GET /abandoned-carts;         assert "abandoned-carts" "200"
api GET /inbox;                   assert "inbox" "200"
api GET "/inbox/unread-count";    assert "inbox: unread-count" "200"
api GET /page-visits;             assert "page-visits" "200"
api GET "/plugins/status?plugins=action-scheduler"; assert "plugins: status" "200"
api GET /license/status;          assert "license: status" "200"
api GET "/user-management/users"; assert "user-management: users" "200"
api GET "/integrations/provider-status"; assert "integrations: provider-status" "200"
api GET /forms;                   assert "forms" "200"
api GET /deals/statistics;        assert "deals: statistics" "200"
api GET /deals/overdue;           assert "deals: overdue" "200"
api GET /activities/statistics;   assert "activities: statistics" "200"
api GET /activities/upcoming;     assert "activities: upcoming" "200"
api GET /automations/merge-tags;  assert "automations: merge-tags" "200"
api GET /email-blocks;            assert "email-blocks" "200"
api GET "/lead-scoring-rules/active"; assert "lead-scoring-rules: active" "200"
api GET /custom-fields;           assert "custom-fields: list" "200"
api GET "/custom-fields-groups?scope=contact"; assert "custom-fields-groups: list" "200"
api GET /tags;                    assert "tags: list" "200"
api GET /lists;                   assert "lists: list" "200"
api GET "/settings/cron-status";  assert "settings: cron-status" "200"
api GET /templates;               assert "templates: list" "200"
api GET "/templates/user-templates"; assert "templates: user-templates" "200"
api GET /email-sequences;         assert "email-sequences: list" "200"
api GET /link-triggers;           assert "link-triggers: list" "200"
api GET /lead-scoring-rules;      assert "lead-scoring-rules: list" "200"
api GET /lead-scoring-levels;     assert "lead-scoring-levels: list" "200"
api GET /pipelines;               assert "pipelines: list" "200"
api GET /contacts;                assert "contacts: list" "200"
api GET /deals;                   assert "deals: list" "200"
api GET /tasks;                   assert "tasks: list" "200"
api GET /automations;             assert "automations: list" "200"
api GET /campaigns;               assert "campaigns: list" "200"
api GET /activities;              assert "activities: list" "200"
api GET "/settings/bounce-webhooks"; assert "settings: bounce-webhooks" "200"
api GET "/settings/messaging-webhooks"; assert "settings: messaging-webhooks" "200"

##############################################################################
# Summary
##############################################################################
echo ""
echo "============================================="
echo "  TOTAL: ${TOTAL}   PASS: ${PASS_COUNT}   FAIL: ${FAIL_COUNT}"
echo "============================================="

if [[ "$FAIL_COUNT" -gt 0 ]]; then
  exit 1
fi
exit 0
