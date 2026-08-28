<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * FluentAffiliate integration for NIBWP (Pro tier).
 *
 * Surfaces FluentAffiliate's affiliate-program operations as MCP-callable
 * abilities so AI agents can manage affiliates, referrals, payouts, groups,
 * visits, settings, and aggregate reports.
 *
 * REQUIRES: FluentAffiliate plugin (free) v1.4+ active.
 * Pro license unlocks this integration via includes/premium/bootstrap.php.
 *
 * @see https://fluentaffiliate.com/docs/
 */

// =====================================================================
// AFFILIATES / GROUPS / REFERRALS / VISITS / PAYOUTS / SETTINGS / REPORTS
// =====================================================================

wp_register_ability('nibwp/fluentaffiliate-manage', [
    'label' => __('FluentAffiliate – Program Manager', domain: 'nibwp'),
    'description' => __(
        'Manage FluentAffiliate affiliates, groups, referrals, visits, payouts, settings, and reports. Approve/reject affiliates, create commissions, mark payouts paid, and pull program analytics.',
        domain: 'nibwp',
    ),
    'category' => 'affiliate',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => [
                    // Affiliates
                    'list_affiliates', 'get_affiliate', 'create_affiliate', 'update_affiliate', 'delete_affiliate',
                    'approve_affiliate', 'reject_affiliate', 'set_affiliate_rate',
                    // Groups
                    'list_groups', 'get_group', 'create_group', 'update_group', 'delete_group',
                    // Referrals (commissions)
                    'list_referrals', 'get_referral', 'create_referral', 'update_referral', 'delete_referral',
                    'mark_referral_paid', 'mark_referral_unpaid', 'reject_referral',
                    // Visits
                    'list_visits', 'get_visit_stats',
                    // Payouts
                    'list_payouts', 'get_payout', 'create_payout', 'mark_payout_paid', 'delete_payout',
                    // Settings / Options
                    'get_setting', 'update_setting',
                    // Reports / Analytics
                    'top_affiliates', 'revenue_summary', 'affiliate_dashboard',
                ],
                'description' => 'The action to perform.',
            ],
            // Affiliate fields
            'affiliate_id' => ['type' => 'integer', 'description' => 'Affiliate ID.'],
            'user_id' => ['type' => 'integer', 'description' => 'WordPress user ID linked to the affiliate.'],
            'affiliate_data' => [
                'type' => 'object',
                'description' => 'Affiliate data for create/update. Fields: user_id, group_id, custom_param, rate, rate_type (percentage/flat), payment_email, status (active/pending/rejected/inactive), settings, note.',
                'properties' => [
                    'user_id'        => ['type' => 'integer'],
                    'group_id'       => ['type' => ['integer', 'null']],
                    'custom_param'   => ['type' => 'string'],
                    'rate'           => ['type' => ['number', 'string']],
                    'rate_type'      => ['type' => 'string', 'enum' => ['percentage', 'flat']],
                    'payment_email'  => ['type' => 'string'],
                    'status'         => ['type' => 'string'],
                    'note'           => ['type' => 'string'],
                ],
            ],
            // Group fields
            'group_id'   => ['type' => 'integer', 'description' => 'Affiliate group ID.'],
            'group_data' => [
                'type'        => 'object',
                'description' => 'Group data. Fields: name, rate, rate_type, description.',
            ],
            // Referral / commission fields
            'referral_id' => ['type' => 'integer', 'description' => 'Referral ID.'],
            'referral_data' => [
                'type' => 'object',
                'description' => 'Referral data. Fields: affiliate_id, amount, currency, description, status (unpaid/paid/rejected/pending), order_total, utm_campaign, provider, type (sale/lead).',
            ],
            // Payout fields
            'payout_id' => ['type' => 'integer', 'description' => 'Payout ID.'],
            'payout_data' => [
                'type' => 'object',
                'description' => 'Payout data. Fields: title, description, total_amount, currency, payout_method (paypal/bank/manual), status (pending/paid).',
            ],
            // Settings
            'setting_key'   => ['type' => 'string', 'description' => 'FluentAffiliate option key (e.g. referral_format, default_rate, currency).'],
            'setting_value' => ['description' => 'Setting value (string|int|array).'],
            // Filters / pagination
            'status'   => ['type' => 'string', 'description' => 'Filter by status (active/pending/rejected/inactive for affiliates; unpaid/paid for referrals).'],
            'search'   => ['type' => 'string', 'description' => 'Search term for list endpoints.'],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'page'     => ['type' => 'integer', 'default' => 1],
            'date_from'=> ['type' => 'string', 'description' => 'ISO date for reports (YYYY-MM-DD).'],
            'date_to'  => ['type' => 'string', 'description' => 'ISO date for reports (YYYY-MM-DD).'],
            'limit'    => ['type' => 'integer', 'description' => 'Top-N limit for ranking reports.', 'default' => 10],
            'metric'   => ['type' => 'string', 'enum' => ['revenue', 'referrals', 'visits'], 'description' => 'Ranking metric for top_affiliates.', 'default' => 'revenue'],
        ],
        'required' => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'affiliates' => ['type' => 'array'],
            'affiliate'  => ['type' => 'object'],
            'groups'     => ['type' => 'array'],
            'group'      => ['type' => 'object'],
            'referrals'  => ['type' => 'array'],
            'referral'   => ['type' => 'object'],
            'visits'     => ['type' => 'array'],
            'payouts'    => ['type' => 'array'],
            'payout'     => ['type' => 'object'],
            'setting'    => ['description' => 'Setting value.'],
            'updated'    => ['type' => 'boolean'],
            'deleted'    => ['type' => 'boolean'],
            'total'      => ['type' => 'integer'],
            'stats'      => ['type' => 'object'],
            'report'     => ['type' => 'object'],
        ],
    ],
    'execute_callback'    => 'nibwp_fluentaffiliate_manage',
    'permission_callback' => 'nibwp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Manage the entire FluentAffiliate program — affiliates, groups, referrals (commissions), visits, payouts, settings, and reports.',
                '',
                'AFFILIATE ACTIONS:',
                '- list_affiliates: List affiliates. Supports status/search/per_page/page.',
                '- get_affiliate: Get one. Requires affiliate_id OR user_id.',
                '- create_affiliate: Create. affiliate_data.user_id required (link to a WP user).',
                '- update_affiliate: Update. Requires affiliate_id + affiliate_data fields.',
                '- approve_affiliate / reject_affiliate: Shortcuts for status change.',
                '- set_affiliate_rate: Override individual commission rate (rate + rate_type).',
                '- delete_affiliate: Hard delete. DESTRUCTIVE.',
                '',
                'GROUP ACTIONS:',
                '- list_groups / get_group / create_group / update_group / delete_group',
                '- Group sets default rate for member affiliates.',
                '',
                'REFERRAL ACTIONS (commissions):',
                '- list_referrals: Filterable by status (unpaid/paid/rejected/pending), affiliate_id.',
                '- create_referral: Manual commission. Requires affiliate_id, amount.',
                '- update_referral / delete_referral: Edit / remove.',
                '- mark_referral_paid / mark_referral_unpaid / reject_referral: Status shortcuts.',
                '',
                'VISIT ACTIONS:',
                '- list_visits: Recent traffic. Filter by affiliate_id.',
                '- get_visit_stats: Counts + conversion rate by affiliate.',
                '',
                'PAYOUT ACTIONS:',
                '- list_payouts / get_payout / create_payout / mark_payout_paid / delete_payout',
                '- Create batches commission referrals into a single payout.',
                '',
                'SETTING ACTIONS:',
                '- get_setting / update_setting: Read or write any fluentAffiliate_* option.',
                '',
                'REPORT ACTIONS:',
                '- top_affiliates: Rank by revenue/referrals/visits. Supports date_from/date_to/limit.',
                '- revenue_summary: Total earned + paid + unpaid for a date range.',
                '- affiliate_dashboard: Per-affiliate summary (visits/referrals/conversion/total earned).',
                '',
                'REQUIRES: FluentAffiliate v1.4+ active.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

/**
 * Central dispatcher for nibwp/fluentaffiliate-manage.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_fluentaffiliate_manage(array $input): array|WP_Error
{
    if (!defined('FLUENT_AFFILIATE_VERSION') && !class_exists('FluentAffiliate\\App\\App')) {
        return new WP_Error('fluentaffiliate_not_active', 'FluentAffiliate plugin is not active.');
    }

    foreach (['FluentAffiliate\\App\\Models\\Affiliate', 'FluentAffiliate\\App\\Models\\Referral'] as $cls) {
        if (!class_exists($cls)) {
            return new WP_Error('fluentaffiliate_models_missing', "Required model {$cls} not loaded.");
        }
    }

    $action = (string) ($input['action'] ?? '');
    $per_page = min(max((int) ($input['per_page'] ?? 20), 1), 100);
    $page = max((int) ($input['page'] ?? 1), 1);

    try {
        switch ($action) {
            // ─── AFFILIATES ────────────────────────────────────────────
            case 'list_affiliates':
                return nibwp_fa__list_affiliates($input, $per_page, $page);
            case 'get_affiliate':
                return nibwp_fa__get_affiliate($input);
            case 'create_affiliate':
                return nibwp_fa__create_affiliate($input);
            case 'update_affiliate':
                return nibwp_fa__update_affiliate($input);
            case 'approve_affiliate':
                return nibwp_fa__set_affiliate_status($input, 'active');
            case 'reject_affiliate':
                return nibwp_fa__set_affiliate_status($input, 'rejected');
            case 'set_affiliate_rate':
                return nibwp_fa__set_affiliate_rate($input);
            case 'delete_affiliate':
                return nibwp_fa__delete_affiliate($input);

            // ─── GROUPS ────────────────────────────────────────────────
            case 'list_groups':
                return nibwp_fa__list_groups();
            case 'get_group':
                return nibwp_fa__get_group($input);
            case 'create_group':
                return nibwp_fa__create_group($input);
            case 'update_group':
                return nibwp_fa__update_group($input);
            case 'delete_group':
                return nibwp_fa__delete_group($input);

            // ─── REFERRALS ─────────────────────────────────────────────
            case 'list_referrals':
                return nibwp_fa__list_referrals($input, $per_page, $page);
            case 'get_referral':
                return nibwp_fa__get_referral($input);
            case 'create_referral':
                return nibwp_fa__create_referral($input);
            case 'update_referral':
                return nibwp_fa__update_referral($input);
            case 'mark_referral_paid':
                return nibwp_fa__set_referral_status($input, 'paid');
            case 'mark_referral_unpaid':
                return nibwp_fa__set_referral_status($input, 'unpaid');
            case 'reject_referral':
                return nibwp_fa__set_referral_status($input, 'rejected');
            case 'delete_referral':
                return nibwp_fa__delete_referral($input);

            // ─── VISITS ────────────────────────────────────────────────
            case 'list_visits':
                return nibwp_fa__list_visits($input, $per_page, $page);
            case 'get_visit_stats':
                return nibwp_fa__visit_stats($input);

            // ─── PAYOUTS ───────────────────────────────────────────────
            case 'list_payouts':
                return nibwp_fa__list_payouts($input, $per_page, $page);
            case 'get_payout':
                return nibwp_fa__get_payout($input);
            case 'create_payout':
                return nibwp_fa__create_payout($input);
            case 'mark_payout_paid':
                return nibwp_fa__set_payout_status($input, 'paid');
            case 'delete_payout':
                return nibwp_fa__delete_payout($input);

            // ─── SETTINGS ──────────────────────────────────────────────
            case 'get_setting':
                return nibwp_fa__get_setting($input);
            case 'update_setting':
                return nibwp_fa__update_setting($input);

            // ─── REPORTS ───────────────────────────────────────────────
            case 'top_affiliates':
                return nibwp_fa__top_affiliates($input);
            case 'revenue_summary':
                return nibwp_fa__revenue_summary($input);
            case 'affiliate_dashboard':
                return nibwp_fa__affiliate_dashboard($input);

            default:
                return new WP_Error('fluentaffiliate_invalid_action', "Unknown action: {$action}");
        }
    } catch (\Throwable $e) {
        return new WP_Error('fluentaffiliate_error', $e->getMessage(), ['action' => $action]);
    }
}

// =====================================================================
// AFFILIATES
// =====================================================================

function nibwp_fa__serialize_affiliate(object $affiliate): array
{
    $row = [
        'id'             => (int) $affiliate->id,
        'user_id'        => (int) $affiliate->user_id,
        'group_id'       => $affiliate->group_id ? (int) $affiliate->group_id : null,
        'rate'           => $affiliate->rate,
        'rate_type'      => $affiliate->rate_type,
        'status'         => $affiliate->status,
        'payment_email'  => $affiliate->payment_email,
        'custom_param'   => $affiliate->custom_param,
        'note'           => $affiliate->note,
        'visits'         => isset($affiliate->visits) ? (int) $affiliate->visits : null,
        'referrals'      => isset($affiliate->referrals) ? (int) $affiliate->referrals : null,
        'earnings'       => isset($affiliate->earnings) ? (float) $affiliate->earnings : null,
        'unpaid_earnings'=> isset($affiliate->unpaid_earnings) ? (float) $affiliate->unpaid_earnings : null,
        'created_at'     => (string) $affiliate->created_at,
        'updated_at'     => (string) $affiliate->updated_at,
    ];

    $user = get_userdata((int) $affiliate->user_id);
    if ($user) {
        $row['user'] = [
            'id'           => $user->ID,
            'user_login'   => $user->user_login,
            'user_email'   => $user->user_email,
            'display_name' => $user->display_name,
        ];
    }

    return $row;
}

function nibwp_fa__list_affiliates(array $input, int $per_page, int $page): array
{
    $cls = 'FluentAffiliate\\App\\Models\\Affiliate';
    $q   = $cls::query()->orderBy('id', 'DESC');

    if (!empty($input['status'])) {
        $q->where('status', (string) $input['status']);
    }
    if (!empty($input['search'])) {
        $search = (string) $input['search'];
        $q->where(function ($w) use ($search) {
            $w->where('payment_email', 'LIKE', '%' . $search . '%')
              ->orWhere('custom_param', 'LIKE', '%' . $search . '%');
        });
    }

    $paginated = $q->paginate($per_page, ['*'], 'page', $page);
    $items     = [];
    foreach ($paginated->items() as $a) {
        $items[] = nibwp_fa__serialize_affiliate($a);
    }
    return ['affiliates' => $items, 'total' => $paginated->total()];
}

function nibwp_fa__get_affiliate(array $input): array|WP_Error
{
    $cls = 'FluentAffiliate\\App\\Models\\Affiliate';
    if (!empty($input['affiliate_id'])) {
        $a = $cls::find((int) $input['affiliate_id']);
    } elseif (!empty($input['user_id'])) {
        $a = $cls::where('user_id', (int) $input['user_id'])->first();
    } else {
        return new WP_Error('fluentaffiliate_missing_id', 'Provide affiliate_id or user_id.');
    }
    if (!$a) {
        return new WP_Error('fluentaffiliate_not_found', 'Affiliate not found.');
    }
    return ['affiliate' => nibwp_fa__serialize_affiliate($a)];
}

function nibwp_fa__create_affiliate(array $input): array|WP_Error
{
    $data = (array) ($input['affiliate_data'] ?? []);
    if (empty($data['user_id'])) {
        return new WP_Error('fluentaffiliate_missing_user', 'affiliate_data.user_id is required.');
    }
    $user_id = (int) $data['user_id'];
    if (!get_userdata($user_id)) {
        return new WP_Error('fluentaffiliate_invalid_user', "WP user {$user_id} not found.");
    }
    $cls = 'FluentAffiliate\\App\\Models\\Affiliate';
    if ($cls::where('user_id', $user_id)->first()) {
        return new WP_Error('fluentaffiliate_duplicate', "User {$user_id} is already an affiliate.");
    }
    $a = $cls::create([
        'user_id'       => $user_id,
        'group_id'      => isset($data['group_id']) ? (int) $data['group_id'] : null,
        'custom_param'  => (string) ($data['custom_param'] ?? ''),
        'rate'          => $data['rate'] ?? '',
        'rate_type'     => (string) ($data['rate_type'] ?? 'percentage'),
        'payment_email' => (string) ($data['payment_email'] ?? ''),
        'status'        => (string) ($data['status'] ?? 'pending'),
        'note'          => (string) ($data['note'] ?? ''),
    ]);
    return ['affiliate' => nibwp_fa__serialize_affiliate($a)];
}

function nibwp_fa__update_affiliate(array $input): array|WP_Error
{
    $id = (int) ($input['affiliate_id'] ?? 0);
    if (!$id) {
        return new WP_Error('fluentaffiliate_missing_id', 'affiliate_id is required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\Affiliate';
    $a = $cls::find($id);
    if (!$a) {
        return new WP_Error('fluentaffiliate_not_found', "Affiliate {$id} not found.");
    }
    $data = (array) ($input['affiliate_data'] ?? []);
    foreach (['group_id', 'custom_param', 'rate', 'rate_type', 'payment_email', 'status', 'note'] as $f) {
        if (array_key_exists($f, $data)) {
            $a->{$f} = $data[$f];
        }
    }
    $a->save();
    return ['affiliate' => nibwp_fa__serialize_affiliate($a), 'updated' => true];
}

function nibwp_fa__set_affiliate_status(array $input, string $status): array|WP_Error
{
    $id = (int) ($input['affiliate_id'] ?? 0);
    if (!$id) {
        return new WP_Error('fluentaffiliate_missing_id', 'affiliate_id is required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\Affiliate';
    $a = $cls::find($id);
    if (!$a) {
        return new WP_Error('fluentaffiliate_not_found', "Affiliate {$id} not found.");
    }
    $a->status = $status;
    $a->save();
    /** Fires after an affiliate is approved/rejected via NIBWP. */
    do_action('nibwp/fluentaffiliate/affiliate_status_changed', $a, $status);
    return ['affiliate' => nibwp_fa__serialize_affiliate($a), 'updated' => true];
}

function nibwp_fa__set_affiliate_rate(array $input): array|WP_Error
{
    $id = (int) ($input['affiliate_id'] ?? 0);
    if (!$id) {
        return new WP_Error('fluentaffiliate_missing_id', 'affiliate_id is required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\Affiliate';
    $a = $cls::find($id);
    if (!$a) {
        return new WP_Error('fluentaffiliate_not_found', "Affiliate {$id} not found.");
    }
    $data = (array) ($input['affiliate_data'] ?? []);
    if (!array_key_exists('rate', $data)) {
        return new WP_Error('fluentaffiliate_missing_rate', 'affiliate_data.rate is required.');
    }
    $a->rate      = $data['rate'];
    $a->rate_type = (string) ($data['rate_type'] ?? ($a->rate_type ?: 'percentage'));
    $a->save();
    return ['affiliate' => nibwp_fa__serialize_affiliate($a), 'updated' => true];
}

function nibwp_fa__delete_affiliate(array $input): array|WP_Error
{
    $id = (int) ($input['affiliate_id'] ?? 0);
    if (!$id) {
        return new WP_Error('fluentaffiliate_missing_id', 'affiliate_id is required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\Affiliate';
    $a = $cls::find($id);
    if (!$a) {
        return new WP_Error('fluentaffiliate_not_found', "Affiliate {$id} not found.");
    }
    $a->delete();
    return ['deleted' => true, 'affiliate_id' => $id];
}

// =====================================================================
// GROUPS
// =====================================================================

function nibwp_fa__serialize_group(object $group): array
{
    return [
        'id'         => (int) $group->id,
        'name'       => (string) ($group->value ?? $group->name ?? ''),
        'meta_key'   => $group->meta_key ?? null,
        'settings'   => $group->settings ?? null,
        'created_at' => (string) ($group->created_at ?? ''),
    ];
}

function nibwp_fa__list_groups(): array
{
    $cls = 'FluentAffiliate\\App\\Models\\AffiliateGroup';
    $items = [];
    if (class_exists($cls)) {
        $rows = $cls::where('object_type', 'affiliate_group')->orderBy('id', 'DESC')->get();
        foreach ($rows as $row) {
            $items[] = nibwp_fa__serialize_group($row);
        }
    }
    return ['groups' => $items, 'total' => count($items)];
}

function nibwp_fa__get_group(array $input): array|WP_Error
{
    $id = (int) ($input['group_id'] ?? 0);
    if (!$id) {
        return new WP_Error('fluentaffiliate_missing_id', 'group_id is required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\AffiliateGroup';
    $g = $cls::find($id);
    if (!$g) {
        return new WP_Error('fluentaffiliate_not_found', "Group {$id} not found.");
    }
    return ['group' => nibwp_fa__serialize_group($g)];
}

function nibwp_fa__create_group(array $input): array|WP_Error
{
    $data = (array) ($input['group_data'] ?? []);
    if (empty($data['name'])) {
        return new WP_Error('fluentaffiliate_missing_name', 'group_data.name is required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\AffiliateGroup';
    $g = $cls::create([
        'object_type' => 'affiliate_group',
        'meta_key'    => sanitize_key((string) $data['name']),
        'value'       => (string) $data['name'],
    ]);
    return ['group' => nibwp_fa__serialize_group($g)];
}

function nibwp_fa__update_group(array $input): array|WP_Error
{
    $id = (int) ($input['group_id'] ?? 0);
    if (!$id) {
        return new WP_Error('fluentaffiliate_missing_id', 'group_id is required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\AffiliateGroup';
    $g = $cls::find($id);
    if (!$g) {
        return new WP_Error('fluentaffiliate_not_found', "Group {$id} not found.");
    }
    $data = (array) ($input['group_data'] ?? []);
    if (!empty($data['name'])) {
        $g->value = (string) $data['name'];
    }
    $g->save();
    return ['group' => nibwp_fa__serialize_group($g), 'updated' => true];
}

function nibwp_fa__delete_group(array $input): array|WP_Error
{
    $id = (int) ($input['group_id'] ?? 0);
    if (!$id) {
        return new WP_Error('fluentaffiliate_missing_id', 'group_id is required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\AffiliateGroup';
    $g = $cls::find($id);
    if (!$g) {
        return new WP_Error('fluentaffiliate_not_found', "Group {$id} not found.");
    }
    $g->delete();
    return ['deleted' => true, 'group_id' => $id];
}

// =====================================================================
// REFERRALS (commissions)
// =====================================================================

function nibwp_fa__serialize_referral(object $r): array
{
    return [
        'id'              => (int) $r->id,
        'affiliate_id'    => (int) $r->affiliate_id,
        'parent_id'       => $r->parent_id ? (int) $r->parent_id : null,
        'customer_id'     => $r->customer_id ? (int) $r->customer_id : null,
        'visit_id'        => $r->visit_id ? (int) $r->visit_id : null,
        'amount'          => (float) $r->amount,
        'order_total'     => (float) $r->order_total,
        'currency'        => (string) $r->currency,
        'description'     => (string) $r->description,
        'utm_campaign'    => (string) $r->utm_campaign,
        'provider'        => (string) $r->provider,
        'provider_id'     => (string) $r->provider_id,
        'provider_sub_id' => (string) $r->provider_sub_id,
        'type'            => (string) $r->type,
        'status'          => (string) $r->status,
        'payout_id'       => $r->payout_id ? (int) $r->payout_id : null,
        'created_at'      => (string) $r->created_at,
        'updated_at'      => (string) $r->updated_at,
    ];
}

function nibwp_fa__list_referrals(array $input, int $per_page, int $page): array
{
    $cls = 'FluentAffiliate\\App\\Models\\Referral';
    $q   = $cls::query()->orderBy('id', 'DESC');

    if (!empty($input['affiliate_id'])) {
        $q->where('affiliate_id', (int) $input['affiliate_id']);
    }
    if (!empty($input['status'])) {
        $q->where('status', (string) $input['status']);
    }
    if (!empty($input['date_from'])) {
        $q->where('created_at', '>=', (string) $input['date_from']);
    }
    if (!empty($input['date_to'])) {
        $q->where('created_at', '<=', (string) $input['date_to'] . ' 23:59:59');
    }

    $paginated = $q->paginate($per_page, ['*'], 'page', $page);
    $items     = [];
    foreach ($paginated->items() as $r) {
        $items[] = nibwp_fa__serialize_referral($r);
    }
    return ['referrals' => $items, 'total' => $paginated->total()];
}

function nibwp_fa__get_referral(array $input): array|WP_Error
{
    $id = (int) ($input['referral_id'] ?? 0);
    if (!$id) {
        return new WP_Error('fluentaffiliate_missing_id', 'referral_id is required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\Referral';
    $r = $cls::find($id);
    if (!$r) {
        return new WP_Error('fluentaffiliate_not_found', "Referral {$id} not found.");
    }
    return ['referral' => nibwp_fa__serialize_referral($r)];
}

function nibwp_fa__create_referral(array $input): array|WP_Error
{
    $data = (array) ($input['referral_data'] ?? []);
    if (empty($data['affiliate_id'])) {
        return new WP_Error('fluentaffiliate_missing_id', 'referral_data.affiliate_id is required.');
    }
    if (!isset($data['amount'])) {
        return new WP_Error('fluentaffiliate_missing_amount', 'referral_data.amount is required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\Referral';
    $r = $cls::create([
        'affiliate_id'  => (int) $data['affiliate_id'],
        'amount'        => (float) $data['amount'],
        'order_total'   => isset($data['order_total']) ? (float) $data['order_total'] : 0,
        'currency'      => (string) ($data['currency'] ?? ''),
        'description'   => (string) ($data['description'] ?? ''),
        'utm_campaign'  => (string) ($data['utm_campaign'] ?? ''),
        'provider'      => (string) ($data['provider'] ?? 'manual'),
        'type'          => (string) ($data['type'] ?? 'sale'),
        'status'        => (string) ($data['status'] ?? 'unpaid'),
    ]);
    // Bump affiliate's referral counter.
    $affiliateCls = 'FluentAffiliate\\App\\Models\\Affiliate';
    if ($a = $affiliateCls::find((int) $data['affiliate_id'])) {
        if (method_exists($a, 'increase')) {
            $a->increase('referrals');
        }
    }
    return ['referral' => nibwp_fa__serialize_referral($r)];
}

function nibwp_fa__update_referral(array $input): array|WP_Error
{
    $id = (int) ($input['referral_id'] ?? 0);
    if (!$id) {
        return new WP_Error('fluentaffiliate_missing_id', 'referral_id is required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\Referral';
    $r = $cls::find($id);
    if (!$r) {
        return new WP_Error('fluentaffiliate_not_found', "Referral {$id} not found.");
    }
    $data = (array) ($input['referral_data'] ?? []);
    foreach (['amount', 'order_total', 'currency', 'description', 'utm_campaign', 'provider', 'type', 'status'] as $f) {
        if (array_key_exists($f, $data)) {
            $r->{$f} = $data[$f];
        }
    }
    $r->save();
    return ['referral' => nibwp_fa__serialize_referral($r), 'updated' => true];
}

function nibwp_fa__set_referral_status(array $input, string $status): array|WP_Error
{
    $id = (int) ($input['referral_id'] ?? 0);
    if (!$id) {
        return new WP_Error('fluentaffiliate_missing_id', 'referral_id is required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\Referral';
    $r = $cls::find($id);
    if (!$r) {
        return new WP_Error('fluentaffiliate_not_found', "Referral {$id} not found.");
    }
    $r->status = $status;
    $r->save();
    return ['referral' => nibwp_fa__serialize_referral($r), 'updated' => true];
}

function nibwp_fa__delete_referral(array $input): array|WP_Error
{
    $id = (int) ($input['referral_id'] ?? 0);
    if (!$id) {
        return new WP_Error('fluentaffiliate_missing_id', 'referral_id is required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\Referral';
    $r = $cls::find($id);
    if (!$r) {
        return new WP_Error('fluentaffiliate_not_found', "Referral {$id} not found.");
    }
    $r->delete();
    return ['deleted' => true, 'referral_id' => $id];
}

// =====================================================================
// VISITS
// =====================================================================

function nibwp_fa__list_visits(array $input, int $per_page, int $page): array
{
    $cls = 'FluentAffiliate\\App\\Models\\Visit';
    $q   = $cls::query()->orderBy('id', 'DESC');
    if (!empty($input['affiliate_id'])) {
        $q->where('affiliate_id', (int) $input['affiliate_id']);
    }
    if (!empty($input['date_from'])) {
        $q->where('created_at', '>=', (string) $input['date_from']);
    }
    if (!empty($input['date_to'])) {
        $q->where('created_at', '<=', (string) $input['date_to'] . ' 23:59:59');
    }
    $paginated = $q->paginate($per_page, ['*'], 'page', $page);
    $items     = [];
    foreach ($paginated->items() as $v) {
        $items[] = [
            'id'           => (int) $v->id,
            'affiliate_id' => (int) $v->affiliate_id,
            'url'          => (string) $v->url,
            'referrer'     => (string) $v->referrer,
            'utm_campaign' => (string) $v->utm_campaign,
            'utm_medium'   => (string) $v->utm_medium,
            'utm_source'   => (string) $v->utm_source,
            'ip'           => (string) $v->ip,
            'user_id'      => $v->user_id ? (int) $v->user_id : null,
            'referral_id'  => $v->referral_id ? (int) $v->referral_id : null,
            'created_at'   => (string) $v->created_at,
        ];
    }
    return ['visits' => $items, 'total' => $paginated->total()];
}

function nibwp_fa__visit_stats(array $input): array|WP_Error
{
    if (empty($input['affiliate_id'])) {
        return new WP_Error('fluentaffiliate_missing_id', 'affiliate_id is required.');
    }
    $aid = (int) $input['affiliate_id'];

    $visitCls    = 'FluentAffiliate\\App\\Models\\Visit';
    $referralCls = 'FluentAffiliate\\App\\Models\\Referral';

    $visits    = $visitCls::where('affiliate_id', $aid)->count();
    $referrals = $referralCls::where('affiliate_id', $aid)->count();
    $earned    = (float) $referralCls::where('affiliate_id', $aid)->sum('amount');
    $unpaid    = (float) $referralCls::where('affiliate_id', $aid)->where('status', 'unpaid')->sum('amount');
    $paid      = (float) $referralCls::where('affiliate_id', $aid)->where('status', 'paid')->sum('amount');

    $conversion = $visits > 0 ? round(($referrals / $visits) * 100, 2) : 0;

    return [
        'stats' => [
            'affiliate_id'    => $aid,
            'visits'          => (int) $visits,
            'referrals'       => (int) $referrals,
            'conversion_rate' => $conversion,
            'total_earned'    => $earned,
            'unpaid'          => $unpaid,
            'paid'            => $paid,
        ],
    ];
}

// =====================================================================
// PAYOUTS
// =====================================================================

function nibwp_fa__serialize_payout(object $p): array
{
    return [
        'id'            => (int) $p->id,
        'title'         => (string) $p->title,
        'description'   => (string) $p->description,
        'total_amount'  => (float) $p->total_amount,
        'currency'      => (string) $p->currency,
        'payout_method' => (string) $p->payout_method,
        'status'        => (string) $p->status,
        'created_by'    => $p->created_by ? (int) $p->created_by : null,
        'created_at'    => (string) $p->created_at,
        'updated_at'    => (string) $p->updated_at,
    ];
}

function nibwp_fa__list_payouts(array $input, int $per_page, int $page): array
{
    $cls = 'FluentAffiliate\\App\\Models\\Payout';
    $q   = $cls::query()->orderBy('id', 'DESC');
    if (!empty($input['status'])) {
        $q->where('status', (string) $input['status']);
    }
    $paginated = $q->paginate($per_page, ['*'], 'page', $page);
    $items     = [];
    foreach ($paginated->items() as $p) {
        $items[] = nibwp_fa__serialize_payout($p);
    }
    return ['payouts' => $items, 'total' => $paginated->total()];
}

function nibwp_fa__get_payout(array $input): array|WP_Error
{
    $id = (int) ($input['payout_id'] ?? 0);
    if (!$id) {
        return new WP_Error('fluentaffiliate_missing_id', 'payout_id is required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\Payout';
    $p = $cls::find($id);
    if (!$p) {
        return new WP_Error('fluentaffiliate_not_found', "Payout {$id} not found.");
    }
    return ['payout' => nibwp_fa__serialize_payout($p)];
}

function nibwp_fa__create_payout(array $input): array|WP_Error
{
    $data = (array) ($input['payout_data'] ?? []);
    if (empty($data['title']) || !isset($data['total_amount'])) {
        return new WP_Error('fluentaffiliate_missing_fields', 'payout_data.title and payout_data.total_amount are required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\Payout';
    $p = $cls::create([
        'created_by'    => get_current_user_id() ?: null,
        'title'         => (string) $data['title'],
        'description'   => (string) ($data['description'] ?? ''),
        'total_amount'  => (float) $data['total_amount'],
        'currency'      => (string) ($data['currency'] ?? ''),
        'payout_method' => (string) ($data['payout_method'] ?? 'manual'),
        'status'        => (string) ($data['status'] ?? 'pending'),
    ]);
    return ['payout' => nibwp_fa__serialize_payout($p)];
}

function nibwp_fa__set_payout_status(array $input, string $status): array|WP_Error
{
    $id = (int) ($input['payout_id'] ?? 0);
    if (!$id) {
        return new WP_Error('fluentaffiliate_missing_id', 'payout_id is required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\Payout';
    $p = $cls::find($id);
    if (!$p) {
        return new WP_Error('fluentaffiliate_not_found', "Payout {$id} not found.");
    }
    $p->status = $status;
    $p->save();
    return ['payout' => nibwp_fa__serialize_payout($p), 'updated' => true];
}

function nibwp_fa__delete_payout(array $input): array|WP_Error
{
    $id = (int) ($input['payout_id'] ?? 0);
    if (!$id) {
        return new WP_Error('fluentaffiliate_missing_id', 'payout_id is required.');
    }
    $cls = 'FluentAffiliate\\App\\Models\\Payout';
    $p = $cls::find($id);
    if (!$p) {
        return new WP_Error('fluentaffiliate_not_found', "Payout {$id} not found.");
    }
    $p->delete();
    return ['deleted' => true, 'payout_id' => $id];
}

// =====================================================================
// SETTINGS
// =====================================================================

function nibwp_fa__get_setting(array $input): array|WP_Error
{
    if (empty($input['setting_key'])) {
        return new WP_Error('fluentaffiliate_missing_key', 'setting_key is required.');
    }
    if (!function_exists('fluentAffiliate_get_option')) {
        return new WP_Error('fluentaffiliate_helpers_missing', 'fluentAffiliate_get_option() not loaded.');
    }
    $value = fluentAffiliate_get_option((string) $input['setting_key'], null);
    return ['setting' => $value, 'setting_key' => (string) $input['setting_key']];
}

function nibwp_fa__update_setting(array $input): array|WP_Error
{
    if (empty($input['setting_key'])) {
        return new WP_Error('fluentaffiliate_missing_key', 'setting_key is required.');
    }
    if (!array_key_exists('setting_value', $input)) {
        return new WP_Error('fluentaffiliate_missing_value', 'setting_value is required.');
    }
    if (!function_exists('fluentAffiliate_update_option')) {
        return new WP_Error('fluentaffiliate_helpers_missing', 'fluentAffiliate_update_option() not loaded.');
    }
    $id = fluentAffiliate_update_option((string) $input['setting_key'], $input['setting_value']);
    return ['updated' => true, 'setting_key' => (string) $input['setting_key'], 'meta_id' => (int) $id];
}

// =====================================================================
// REPORTS / ANALYTICS
// =====================================================================

function nibwp_fa__top_affiliates(array $input): array
{
    $limit   = max(1, min(100, (int) ($input['limit'] ?? 10)));
    $metric  = (string) ($input['metric'] ?? 'revenue');
    $from    = !empty($input['date_from']) ? (string) $input['date_from'] : null;
    $to      = !empty($input['date_to'])   ? (string) $input['date_to']   : null;

    global $wpdb;
    $faRef  = $wpdb->prefix . 'fa_referrals';
    $faVis  = $wpdb->prefix . 'fa_visits';

    $rows = [];
    if ($metric === 'revenue' || $metric === 'referrals') {
        $where = ['1=1'];
        $args  = [];
        if ($from) { $where[] = 'created_at >= %s'; $args[] = $from; }
        if ($to)   { $where[] = 'created_at <= %s'; $args[] = $to . ' 23:59:59'; }
        $whereSql = implode(' AND ', $where);
        $select   = $metric === 'revenue'
            ? 'SUM(amount) AS metric_value, COUNT(*) AS ref_count'
            : 'COUNT(*) AS metric_value, SUM(amount) AS metric_revenue';
        $sql = "SELECT affiliate_id, {$select} FROM {$faRef} WHERE {$whereSql} GROUP BY affiliate_id ORDER BY metric_value DESC LIMIT %d";
        $args[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
    } else {
        $where = ['1=1'];
        $args  = [];
        if ($from) { $where[] = 'created_at >= %s'; $args[] = $from; }
        if ($to)   { $where[] = 'created_at <= %s'; $args[] = $to . ' 23:59:59'; }
        $whereSql = implode(' AND ', $where);
        $sql = "SELECT affiliate_id, COUNT(*) AS metric_value FROM {$faVis} WHERE {$whereSql} GROUP BY affiliate_id ORDER BY metric_value DESC LIMIT %d";
        $args[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
    }

    $report = [];
    foreach ((array) $rows as $row) {
        $aid = (int) $row['affiliate_id'];
        $user = null;
        $aCls = 'FluentAffiliate\\App\\Models\\Affiliate';
        if ($a = $aCls::find($aid)) {
            $wpUser = get_userdata((int) $a->user_id);
            $user   = $wpUser ? $wpUser->display_name ?: $wpUser->user_email : null;
        }
        $report[] = [
            'affiliate_id' => $aid,
            'user'         => $user,
            'metric_value' => isset($row['metric_value']) ? (float) $row['metric_value'] : 0,
            'extra'        => $row,
        ];
    }
    return ['report' => ['metric' => $metric, 'date_from' => $from, 'date_to' => $to, 'rows' => $report]];
}

function nibwp_fa__revenue_summary(array $input): array
{
    $from = !empty($input['date_from']) ? (string) $input['date_from'] : null;
    $to   = !empty($input['date_to'])   ? (string) $input['date_to']   : null;

    $cls = 'FluentAffiliate\\App\\Models\\Referral';
    $q   = $cls::query();
    if ($from) { $q->where('created_at', '>=', $from); }
    if ($to)   { $q->where('created_at', '<=', $to . ' 23:59:59'); }

    $total  = (float) (clone $q)->sum('amount');
    $paid   = (float) (clone $q)->where('status', 'paid')->sum('amount');
    $unpaid = (float) (clone $q)->where('status', 'unpaid')->sum('amount');
    $rej    = (float) (clone $q)->where('status', 'rejected')->sum('amount');
    $count  = (int)   (clone $q)->count();

    return [
        'report' => [
            'date_from'      => $from,
            'date_to'        => $to,
            'referral_count' => $count,
            'total_amount'   => $total,
            'paid'           => $paid,
            'unpaid'         => $unpaid,
            'rejected'       => $rej,
        ],
    ];
}

function nibwp_fa__affiliate_dashboard(array $input): array|WP_Error
{
    if (empty($input['affiliate_id'])) {
        return new WP_Error('fluentaffiliate_missing_id', 'affiliate_id is required.');
    }
    $stats = nibwp_fa__visit_stats($input);
    if (is_wp_error($stats)) {
        return $stats;
    }
    $cls = 'FluentAffiliate\\App\\Models\\Affiliate';
    $a   = $cls::find((int) $input['affiliate_id']);
    $payload = [
        'affiliate' => $a ? nibwp_fa__serialize_affiliate($a) : null,
        'stats'     => $stats['stats'],
    ];
    return ['report' => $payload];
}
