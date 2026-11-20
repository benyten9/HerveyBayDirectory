<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * WooCommerce Pro — the shared entry guard, the capability checks and the hub
 * registration helper.
 *
 * Nothing here knows about taxes, attributes or CSV files. Every ability file in
 * this skill opens the same way — licence gate, WooCommerce present, capability
 * for the thing being touched — and registers through the same helper, so a hub
 * added later gets the metadata the MCP layer relies on without anyone having to
 * remember the key set.
 *
 * Two rules the rest of the skill leans on:
 *
 *   1. Authorisation is asked about the OBJECT, not the role. A handler that
 *      touches product 42 asks current_user_can('edit_post', 42). Store
 *      configuration is the exception, because it has no object — that asks
 *      manage_woocommerce.
 *   2. WooCommerce is never assumed. Every class and function this skill reaches
 *      for is checked first, so a store mid-upgrade, or a site where the plugin
 *      was just deactivated, gets a sentence instead of a fatal.
 */

/** House WP_Error wrapper. */
function nibwp_wcpro_err(string $code, string $message, int $status = 400, array $extra = []): WP_Error
{
    return new WP_Error($code, $message, ['status' => $status] + $extra);
}

/**
 * The refusal every capability check returns. One code, so a client can tell an
 * authorisation failure from a validation failure without reading prose.
 */
function nibwp_wcpro_forbidden(string $message): WP_Error
{
    return new WP_Error('nibwp_forbidden', $message, ['status' => 403]);
}

/** Is WooCommerce loaded far enough to be worth asking anything? */
function nibwp_wcpro_wc_active(): bool
{
    return class_exists('WooCommerce') && function_exists('wc_get_product');
}

/**
 * Entry guard. Returns the preflight answers (empty when no token was asked
 * for), or the WP_Error that stops the call.
 *
 * $require_token is true only for the handful of actions that write a lot at
 * once — a CSV import commit. Everything else is gated on capability alone,
 * because a token on every read turns a four-call setup into twelve.
 *
 * @return array{answers: array<string, mixed>, token: string}|WP_Error
 */
function nibwp_wcpro_guard(array $input, bool $require_token = false): array|WP_Error
{
    if (function_exists('nibwp_skill_gate')) {
        $gate = nibwp_skill_gate('woocommerce-pro');
        if (is_wp_error($gate)) {
            return $gate;
        }
    }

    if (!nibwp_wcpro_wc_active()) {
        return nibwp_wcpro_err(
            'wc_not_active',
            'WooCommerce is not active on this site, so there is no store to configure. Activate WooCommerce and try again.',
            409,
        );
    }

    if (!$require_token) {
        return ['answers' => [], 'token' => ''];
    }

    if (!function_exists('nibwp_skill_preflight_consume_token')) {
        $preflight = __DIR__ . '/../../../abilities/skill-preflight.php';
        if (is_readable($preflight)) {
            require_once $preflight;
        }
    }
    if (!function_exists('nibwp_skill_preflight_consume_token')) {
        return nibwp_wcpro_err('preflight_unavailable', 'The preflight ability is not loaded, so this write cannot be authorised.', 500);
    }

    $token = (string) ($input['_preflight_token'] ?? '');
    $answers = nibwp_skill_preflight_consume_token($token, 'woocommerce-pro', $input);
    if (is_wp_error($answers)) {
        return $answers;
    }

    return ['answers' => is_array($answers) ? $answers : [], 'token' => $token];
}

/** Release a preflight token after the write it authorised succeeded. */
function nibwp_wcpro_clear_token(string $token): void
{
    if ($token !== '' && function_exists('nibwp_skill_preflight_clear_token')) {
        nibwp_skill_preflight_clear_token($token);
    }
}

/**
 * The capability that opens WooCommerce → Settings in wp-admin. Everything that
 * writes store configuration asks for it, because store configuration belongs
 * to whoever runs the shop and not to whoever can edit a post.
 */
function nibwp_wcpro_require_manage(string $what): ?WP_Error
{
    if (!current_user_can('manage_woocommerce')) {
        return nibwp_wcpro_forbidden(sprintf('%s is part of the store configuration, which needs the manage_woocommerce capability.', $what));
    }

    return null;
}

/** A named primitive capability, for the screens WooCommerce itself gates that way. */
function nibwp_wcpro_require_cap(string $cap, string $what): ?WP_Error
{
    if (!current_user_can($cap)) {
        return nibwp_wcpro_forbidden(sprintf('%s needs the %s capability.', $what, $cap));
    }

    return null;
}

/**
 * Object-level check on a post — a product, a variation, an order.
 *
 * current_user_can('edit_post', $id) is a meta capability: WordPress maps it
 * through the post's type, its author and its status, so a shop manager who may
 * edit products but not pages gets the right answer and an author who may edit
 * their own drafts does not reach somebody else's.
 */
function nibwp_wcpro_require_post_cap(string $cap, int $post_id, string $noun): ?WP_Error
{
    if ($post_id <= 0) {
        return nibwp_wcpro_err('missing_id', sprintf('Provide the %s id.', $noun));
    }
    if (!current_user_can($cap, $post_id)) {
        return nibwp_wcpro_forbidden(sprintf('Not allowed to %s %s %d.', str_replace('_', ' ', $cap), $noun, $post_id));
    }

    return null;
}

/**
 * Object-level check on a term of a taxonomy — a product tag, an attribute term.
 *
 * The taxonomy carries its own capability names, so a taxonomy registered with
 * unusual caps is honoured rather than second-guessed.
 */
function nibwp_wcpro_require_term_cap(string $which, string $taxonomy, int $term_id, string $noun): ?WP_Error
{
    if (!taxonomy_exists($taxonomy)) {
        return nibwp_wcpro_err('unknown_taxonomy', sprintf('The taxonomy "%s" does not exist on this site.', $taxonomy), 404);
    }

    $object = get_taxonomy($taxonomy);
    $caps = is_object($object) && isset($object->cap) ? (array) $object->cap : [];
    $cap = (string) ($caps[$which] ?? '');
    if ($cap === '') {
        // manage_terms is the taxonomy's own name for "may touch this at all".
        $cap = (string) ($caps['manage_terms'] ?? 'manage_categories');
    }

    // delete_term / edit_term are meta capabilities when a term id is known, so
    // pass it and let WordPress map it; the primitive is the fallback.
    $allowed = $term_id > 0 && in_array($which, ['edit_terms', 'delete_terms'], true)
        ? (current_user_can($which === 'edit_terms' ? 'edit_term' : 'delete_term', $term_id) || current_user_can($cap))
        : current_user_can($cap);

    if (!$allowed) {
        return nibwp_wcpro_forbidden(sprintf('Not allowed to change %s in %s (needs %s).', $noun, $taxonomy, $cap));
    }

    return null;
}

/** May the connected account create a product at all? */
function nibwp_wcpro_require_create_product(): ?WP_Error
{
    $type = get_post_type_object('product');
    $cap = is_object($type) && isset($type->cap->create_posts)
        ? (string) $type->cap->create_posts
        : 'publish_products';

    if (!current_user_can($cap)) {
        return nibwp_wcpro_forbidden(sprintf('Creating products needs the %s capability.', $cap));
    }

    return null;
}

/**
 * A WooCommerce class this skill needs but cannot assume.
 *
 * WC_Tax, WC_Shipping_Zones, WC_Webhook and WC_Product_CSV_Importer are all
 * things a store can be missing — an optional file, a partial upgrade, a fork.
 * Say which one and what it was for instead of fataling on ::class.
 */
function nibwp_wcpro_require_class(string $class, string $what): ?WP_Error
{
    if (!class_exists($class)) {
        return nibwp_wcpro_err(
            'wc_class_missing',
            sprintf('%s needs the WooCommerce class %s, which is not available on this site.', $what, $class),
            409,
        );
    }

    return null;
}

/** Same, for a function. */
function nibwp_wcpro_require_function(string $function, string $what): ?WP_Error
{
    if (!function_exists($function)) {
        return nibwp_wcpro_err(
            'wc_function_missing',
            sprintf('%s needs the WooCommerce function %s(), which is not available on this site.', $what, $function),
            409,
        );
    }

    return null;
}

/**
 * The standard meta block. Matches the shape every other skill registers with,
 * so the MCP adapter and the discover payload treat these hubs like the rest.
 */
function nibwp_wcpro_ability_meta(bool $readonly, bool $destructive, string $instructions = ''): array
{
    return [
        'show_in_rest' => true,
        'mcp' => ['public' => true, 'type' => 'tool'],
        'annotations' => array_filter([
            'readonly'    => $readonly,
            'destructive' => $destructive,
            'idempotent'  => false,
            'instructions' => $instructions !== '' ? $instructions : null,
        ], static fn ($value) => $value !== null),
    ];
}

/**
 * Register one hub ability.
 *
 * A hub is one tool carrying an `action` enum rather than one tool per
 * operation, because an MCP client handed several hundred tools picks worse and
 * costs more. The enum is the contract: tests/skills/woocommerce-pro-check.php
 * compares it against the switch in the handler in both directions, so an action
 * that exists only in the enum, or only in the code, fails the build.
 *
 * @param array<int, string>          $actions    the action enum
 * @param array<string, mixed>        $properties extra input properties beside `action`
 */
function nibwp_wcpro_register_hub(
    string $slug,
    string $label,
    string $description,
    array $actions,
    array $properties,
    string $callback,
    string $instructions,
    bool $readonly = false,
): void {
    wp_register_ability($slug, [
        'label'       => $label,
        'description' => $description,
        'category'    => 'woocommerce-pro',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => array_values($actions),
                    'description' => 'Which operation to run. One of: ' . implode(', ', $actions) . '.',
                ],
            ] + $properties + [
                '_preflight_token' => [
                    'type' => 'string',
                    'description' => 'Token from nibwp/skill-preflight { skill_id:"woocommerce-pro" }. Required only by the bulk-write actions that say so.',
                ],
            ],
            'required' => ['action'],
            // Left open on purpose: a hub gains actions with their own fields,
            // and a client that strips unknown keys against a closed schema
            // silently drops the field the action needs.
            'additionalProperties' => true,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'action'    => ['type' => 'string'],
                'success'   => ['type' => 'boolean'],
                'summary'   => ['type' => 'string'],
                'result'    => ['type' => 'object'],
                'items'     => ['type' => 'array'],
                'count'     => ['type' => 'integer'],
                'dry_run'   => ['type' => 'boolean'],
                'problems'  => ['type' => 'array', 'items' => ['type' => 'object']],
                'next_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ],
        'execute_callback'    => $callback,
        'permission_callback' => 'nibwp_permission_callback',
        // An MCP client reads these to decide whether to confirm before calling.
        // A read-only hub marked destructive trains someone to click through the
        // confirmation that is there to protect the hubs which really do write, so
        // the default stays destructive and a hub that only reads says so.
        'meta' => nibwp_wcpro_ability_meta($readonly, !$readonly, $instructions),
    ]);
}

/**
 * The refusal at the bottom of every hub switch.
 *
 * Unreachable while the enum and the switch agree, which the test suite
 * enforces — but a filter can widen an enum at runtime, and this is what that
 * looks like from the caller's side.
 */
function nibwp_wcpro_unknown_action(string $action, array $actions): WP_Error
{
    return nibwp_wcpro_err(
        'unknown_action',
        sprintf('Unknown action "%s". Valid actions: %s.', $action, implode(', ', $actions)),
        400,
    );
}

/**
 * The shape a hub answers with. Keeps `action` on every response so a client
 * batching calls can tell them apart.
 *
 * @param array<string, mixed> $payload
 */
function nibwp_wcpro_ok(string $action, string $summary, array $payload = []): array
{
    return ['action' => $action, 'success' => true, 'summary' => $summary] + $payload;
}
