<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Voxel integration — commerce and community readers/writers.
 *
 * Orders, plans and memberships here; timeline and messages helpers join in
 * the same file so the social surface lives in one place. Everything goes
 * through Voxel's classes — the tables ({p}vx_orders, {p}voxel_timeline,
 * {p}voxel_messages) are Voxel's own storage and are never written raw.
 */

/**
 * A plain-array view of one order.
 *
 * @param \Voxel\Order $order
 * @return array<string,mixed>
 */
function nibwp_voxel_order_snapshot($order): array
{
    $customer = $order->get_customer();
    $vendor = $order->get_vendor();

    $items = [];
    foreach ($order->get_items() as $item) {
        $items[] = [
            'id' => $item->get_id(),
            'type' => method_exists($item, 'get_type') ? $item->get_type() : null,
            'post_id' => method_exists($item, 'get_post_id') ? $item->get_post_id() : null,
            'summary' => method_exists($item, 'get_product_label') ? $item->get_product_label() : null,
        ];
    }

    return [
        'id' => $order->get_id(),
        'status' => $order->get_status(),
        'shipping_status' => method_exists($order, 'get_shipping_status') ? $order->get_shipping_status() : null,
        'customer' => $customer ? ['id' => $customer->get_id(), 'name' => $customer->get_display_name()] : null,
        'vendor' => $vendor ? ['id' => $vendor->get_id(), 'name' => $vendor->get_display_name()] : null,
        'total' => $order->get_total(),
        'currency' => method_exists($order, 'get_currency') ? $order->get_currency() : null,
        'payment_method' => method_exists($order, 'get_payment_method_key') ? $order->get_payment_method_key() : null,
        'created_at' => method_exists($order, 'get_created_at') ? $order->get_created_at() : null,
        'testmode' => method_exists($order, 'is_test_mode') ? $order->is_test_mode() : null,
        'items' => $items,
    ];
}

/**
 * Orders + bookings dispatcher.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_orders_dispatch(array $input)
{
    if (!class_exists('\\Voxel\\Order')) {
        return nibwp_voxel_err('orders_unavailable', 'Voxel\'s order system is not loaded on this site.', 404);
    }

    $action = (string) ($input['action'] ?? '');
    [$per, $page] = nibwp_voxel_paginate($input);

    switch ($action) {
        case 'list':
            return nibwp_voxel_try(static function () use ($input, $per, $page) {
                $args = ['limit' => $per, 'offset' => ($page - 1) * $per, 'order' => 'DESC', 'order_by' => 'id'];
                foreach (['status', 'shipping_status', 'customer_id', 'vendor_id', 'product_type', 'search'] as $k) {
                    if (isset($input[$k]) && $input[$k] !== '') {
                        $args[$k] = $input[$k];
                    }
                }
                $orders = \Voxel\Order::query($args);
                return [
                    'items' => array_map('nibwp_voxel_order_snapshot', $orders),
                    'count' => count($orders),
                    'page' => $page,
                    'statuses' => array_keys(\Voxel\Order::get_status_config()),
                ];
            });

        case 'get':
            return nibwp_voxel_try(static function () use ($input) {
                $order = \Voxel\Order::get((int) ($input['order_id'] ?? 0));
                if ($order === null) {
                    return nibwp_voxel_err('no_order', 'No order with that id.', 404);
                }
                return ['item' => nibwp_voxel_order_snapshot($order), 'details' => $order->get_details()];
            });

        case 'set-status':
            return nibwp_voxel_try(static function () use ($input) {
                $order = \Voxel\Order::get((int) ($input['order_id'] ?? 0));
                if ($order === null) {
                    return nibwp_voxel_err('no_order', 'No order with that id.', 404);
                }
                $status = (string) ($input['status'] ?? '');
                $valid = array_keys(\Voxel\Order::get_status_config());
                if (!in_array($status, $valid, true)) {
                    return nibwp_voxel_err('bad_status', sprintf('Status must be one of: %s.', implode(', ', $valid)));
                }
                $from = $order->get_status();
                $order->set_status($status);
                $order->save();

                // Money caveat, stated rather than hidden: this changes the
                // order record. It does not talk to Stripe/PayPal — refunds
                // and captures happen at the provider, not here.
                return [
                    'order_id' => $order->get_id(),
                    'from' => $from,
                    'to' => $status,
                    'note' => 'Order record updated. Payment-provider actions (refund, capture) are not performed by this.',
                ];
            });

        case 'actions':
            return nibwp_voxel_try(static function () use ($input) {
                $order = \Voxel\Order::get((int) ($input['order_id'] ?? 0));
                if ($order === null) {
                    return nibwp_voxel_err('no_order', 'No order with that id.', 404);
                }
                $user = \Voxel\User::get(get_current_user_id());
                $actions = $user ? $order->get_actions($user) : [];
                return ['actions' => array_values(array_map(static fn ($a) => is_array($a) ? ($a['action'] ?? $a['label'] ?? '?') : (string) $a, (array) $actions))];
            });

        default:
            return nibwp_voxel_err('bad_action', 'Unknown action. Use list, get, set-status, or actions.');
    }
}

/**
 * Membership plans + paid-listing packages.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_plans_dispatch(array $input)
{
    $action = (string) ($input['action'] ?? '');

    $plans_on = class_exists('\\Voxel\\Plan');
    $listings_on = class_exists('\\Voxel\\Modules\\Paid_Listings\\Listing_Plan');

    switch ($action) {
        case 'list-plans':
            if (!$plans_on) {
                return nibwp_voxel_err('plans_unavailable', 'Paid memberships module is off (Voxel → Settings → Addons).', 404);
            }
            return nibwp_voxel_try(static fn () => [
                'plans' => array_map(static fn ($p) => [
                    'key' => $p->get_key(),
                    'label' => $p->get_label(),
                    'archived' => $p->is_archived(),
                    'config' => $p->get_config(),
                ], array_values(\Voxel\Plan::all())),
            ]);

        case 'get-plan':
            if (!$plans_on) {
                return nibwp_voxel_err('plans_unavailable', 'Paid memberships module is off.', 404);
            }
            return nibwp_voxel_try(static function () use ($input) {
                $plan = \Voxel\Plan::get((string) ($input['plan_key'] ?? ''));
                return $plan === null
                    ? nibwp_voxel_err('unknown_plan', 'No plan with that key.', 404)
                    : ['plan' => $plan->get_config()];
            });

        case 'create-plan':
        case 'update-plan':
            if (!$plans_on) {
                return nibwp_voxel_err('plans_unavailable', 'Paid memberships module is off.', 404);
            }
            $key = sanitize_key((string) ($input['plan_key'] ?? ''));
            $data = is_array($input['plan'] ?? null) ? $input['plan'] : [];
            if ($key === '' || $data === []) {
                return nibwp_voxel_err('missing_args', 'Provide "plan_key" and a "plan" object ({label, description, pricing, submissions...}).');
            }
            return nibwp_voxel_try(static function () use ($action, $key, $data) {
                $exists = \Voxel\Plan::get($key) !== null;
                if ($action === 'create-plan' && $exists) {
                    return nibwp_voxel_err('plan_exists', sprintf('Plan "%s" already exists — use update-plan.', $key), 409);
                }
                if ($action === 'update-plan' && !$exists) {
                    return nibwp_voxel_err('unknown_plan', sprintf('No plan "%s" — use create-plan.', $key), 404);
                }
                $plan = \Voxel\Plan::create(array_merge(['key' => $key], $data), $exists);
                return [$action === 'create-plan' ? 'created' : 'updated' => true, 'plan' => $plan->get_config()];
            });

        case 'get-membership':
            return nibwp_voxel_try(static function () use ($input) {
                $user = \Voxel\User::get((int) ($input['user_id'] ?? get_current_user_id()));
                if ($user === null) {
                    return nibwp_voxel_err('no_user', 'No such user.', 404);
                }
                $membership = $user->get_membership();
                return [
                    'user_id' => $user->get_id(),
                    'membership' => method_exists($membership, 'to_array') ? $membership->to_array() : ['type' => get_class($membership)],
                    'active' => method_exists($membership, 'is_active') ? $membership->is_active() : null,
                ];
            });

        case 'list-listing-plans':
            if (!$listings_on) {
                return nibwp_voxel_err('paid_listings_unavailable', 'Paid listings module is off (Voxel → Settings → Addons).', 404);
            }
            return nibwp_voxel_try(static fn () => [
                'listing_plans' => array_map(
                    static fn ($p) => method_exists($p, 'get_config') ? $p->get_config() : ['key' => $p->get_key()],
                    array_values(\Voxel\Modules\Paid_Listings\Listing_Plan::all())
                ),
            ]);

        default:
            return nibwp_voxel_err('bad_action', 'Unknown action. Use list-plans, get-plan, create-plan, update-plan, get-membership, or list-listing-plans.');
    }
}

/**
 * A plain-array view of one timeline status.
 *
 * @param \Voxel\Timeline\Status $status
 * @return array<string,mixed>
 */
function nibwp_voxel_status_snapshot($status): array
{
    $user = $status->get_user();
    $post = $status->get_post();

    return [
        'id' => $status->get_id(),
        'feed' => method_exists($status, 'get_feed') ? $status->get_feed() : null,
        'content' => method_exists($status, 'get_content') ? $status->get_content() : null,
        'user' => $user ? ['id' => $user->get_id(), 'name' => $user->get_display_name()] : null,
        'post_id' => $post ? $post->get_id() : null,
        'review_score' => method_exists($status, 'get_review_score') ? $status->get_review_score() : null,
        'moderation' => method_exists($status, 'get_moderation') ? $status->get_moderation() : null,
        'created_at' => method_exists($status, 'get_created_at') ? $status->get_created_at() : null,
        'link' => $status->get_link(),
    ];
}

/**
 * Timeline: statuses, replies, likes, follows.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_timeline_dispatch(array $input)
{
    if (!class_exists('\\Voxel\\Timeline\\Status')) {
        return nibwp_voxel_err('timeline_unavailable', 'Voxel\'s timeline is not available on this site.', 404);
    }

    $action = (string) ($input['action'] ?? '');
    [$per, $page] = nibwp_voxel_paginate($input);

    switch ($action) {
        case 'list':
            return nibwp_voxel_try(static function () use ($input, $per, $page) {
                $args = ['limit' => $per, 'offset' => ($page - 1) * $per, 'order' => 'DESC', 'order_by' => 'created_at'];
                foreach (['feed', 'moderation', 'search'] as $k) {
                    if (isset($input[$k]) && $input[$k] !== '') {
                        $args[$k] = $input[$k];
                    }
                }
                // Status::query() answers with a wrapper, not a flat list.
                $result = \Voxel\Timeline\Status::query($args);
                $statuses = (array) ($result['items'] ?? []);
                return [
                    'items' => array_map('nibwp_voxel_status_snapshot', $statuses),
                    'count' => count($statuses),
                    'total' => $result['_total_count'] ?? null,
                    'page' => $page,
                ];
            });

        case 'get':
            return nibwp_voxel_try(static function () use ($input) {
                $status = \Voxel\Timeline\Status::get((int) ($input['status_id'] ?? 0));
                return $status === null
                    ? nibwp_voxel_err('not_found', 'No status with that id.', 404)
                    : ['item' => nibwp_voxel_status_snapshot($status)];
            });

        case 'create':
            $content = trim((string) ($input['content'] ?? ''));
            if ($content === '') {
                return nibwp_voxel_err('no_content', 'Provide "content".');
            }
            return nibwp_voxel_try(static function () use ($input, $content) {
                $status = \Voxel\Timeline\Status::create([
                    'user_id' => (int) ($input['user_id'] ?? get_current_user_id()),
                    'post_id' => !empty($input['post_id']) ? (int) $input['post_id'] : null,
                    'content' => $content,
                    'feed' => (string) ($input['feed'] ?? (!empty($input['post_id']) ? 'post_timeline' : 'user_timeline')),
                    'review_score' => isset($input['review_score']) ? (int) $input['review_score'] : null,
                ]);
                return ['created' => true, 'item' => nibwp_voxel_status_snapshot($status)];
            });

        case 'update':
            return nibwp_voxel_try(static function () use ($input) {
                $status = \Voxel\Timeline\Status::get((int) ($input['status_id'] ?? 0));
                if ($status === null) {
                    return nibwp_voxel_err('not_found', 'No status with that id.', 404);
                }
                $content = trim((string) ($input['content'] ?? ''));
                if ($content === '') {
                    return nibwp_voxel_err('no_content', 'Provide "content".');
                }
                $status->update(['content' => $content, 'edited_at' => \Voxel\utc()->format('Y-m-d H:i:s')]);
                // Statuses are memoized like every other Voxel model, so the
                // snapshot has to come from a forced re-read or it shows the
                // text we just replaced.
                return ['updated' => true, 'item' => nibwp_voxel_status_snapshot(\Voxel\Timeline\Status::force_get($status->get_id()))];
            });

        case 'approve':
            return nibwp_voxel_try(static function () use ($input) {
                $status = \Voxel\Timeline\Status::get((int) ($input['status_id'] ?? 0));
                if ($status === null) {
                    return nibwp_voxel_err('not_found', 'No status with that id.', 404);
                }
                $status->mark_approved();
                return ['approved' => true, 'status_id' => $status->get_id()];
            });

        case 'reply':
            $content = trim((string) ($input['content'] ?? ''));
            $status_id = (int) ($input['status_id'] ?? 0);
            if ($content === '' || $status_id <= 0) {
                return nibwp_voxel_err('missing_args', 'Provide "status_id" and "content".');
            }
            return nibwp_voxel_try(static function () use ($input, $status_id, $content) {
                if (\Voxel\Timeline\Status::get($status_id) === null) {
                    return nibwp_voxel_err('not_found', 'No status with that id.', 404);
                }
                $reply = \Voxel\Timeline\Reply::create([
                    'user_id' => (int) ($input['user_id'] ?? get_current_user_id()),
                    'status_id' => $status_id,
                    'content' => $content,
                ]);
                return ['created' => true, 'reply_id' => $reply->get_id()];
            });

        case 'follow':
        case 'unfollow':
            $object_type = (string) ($input['object_type'] ?? 'post');
            $object_id = (int) ($input['object_id'] ?? 0);
            if (!in_array($object_type, ['post', 'user'], true) || $object_id <= 0) {
                return nibwp_voxel_err('missing_args', 'Provide "object_type" (post|user) and "object_id".');
            }
            return nibwp_voxel_try(static function () use ($input, $action, $object_type, $object_id) {
                $user = \Voxel\User::get((int) ($input['user_id'] ?? get_current_user_id()));
                if ($user === null) {
                    return nibwp_voxel_err('no_user', 'No such user.', 404);
                }
                // Voxel models follow state as a constant; unfollow is the
                // absence of a row, expressed as null.
                $status = $action === 'follow' ? (defined('\\Voxel\\FOLLOW_ACCEPTED') ? \Voxel\FOLLOW_ACCEPTED : 1) : null;
                $user->set_follow_status($object_type, $object_id, $status);
                return [$action === 'follow' ? 'following' : 'unfollowed' => true, 'object_type' => $object_type, 'object_id' => $object_id];
            });

        default:
            return nibwp_voxel_err('bad_action', 'Unknown action.');
    }
}

/**
 * Direct messages + notifications.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>|WP_Error
 */
function nibwp_voxel_messages_dispatch(array $input)
{
    $action = (string) ($input['action'] ?? '');
    $chat = '\\Voxel\\Modules\\Direct_Messages\\Chat';
    $message = '\\Voxel\\Modules\\Direct_Messages\\Message';

    $needs_dm = in_array($action, ['inbox', 'load', 'send', 'mark-seen'], true);
    if ($needs_dm && !class_exists($chat)) {
        return nibwp_voxel_err('dm_unavailable', 'The direct messages module is off (Voxel → Settings → Addons → Direct messages).', 404);
    }

    switch ($action) {
        case 'inbox':
            return nibwp_voxel_try(static function () use ($chat, $input) {
                [$per, $page] = nibwp_voxel_paginate($input);
                $user_id = (int) ($input['user_id'] ?? get_current_user_id());
                return ['chats' => $chat::get_inbox($user_id, $per, ($page - 1) * $per), 'user_id' => $user_id];
            });

        case 'load':
            return nibwp_voxel_try(static function () use ($chat, $input) {
                [$per] = nibwp_voxel_paginate($input);
                $p1 = ['type' => (string) ($input['party1_type'] ?? 'user'), 'id' => (int) ($input['party1_id'] ?? get_current_user_id())];
                $p2 = ['type' => (string) ($input['party2_type'] ?? 'user'), 'id' => (int) ($input['party2_id'] ?? 0)];
                if ($p2['id'] <= 0) {
                    return nibwp_voxel_err('missing_args', 'Provide "party2_id" (and party2_type if it is a post).');
                }
                return ['messages' => $chat::load_messages($p1, $p2, $input['cursor'] ?? null, $per)];
            });

        case 'send':
            return nibwp_voxel_try(static function () use ($message, $input) {
                $content = trim((string) ($input['content'] ?? ''));
                $receiver_id = (int) ($input['receiver_id'] ?? 0);
                if ($content === '' || $receiver_id <= 0) {
                    return nibwp_voxel_err('missing_args', 'Provide "receiver_id" and "content".');
                }
                $msg = $message::create([
                    'sender_type' => (string) ($input['sender_type'] ?? 'user'),
                    'sender_id' => (int) ($input['sender_id'] ?? get_current_user_id()),
                    'receiver_type' => (string) ($input['receiver_type'] ?? 'user'),
                    'receiver_id' => $receiver_id,
                    'content' => $content,
                ]);
                if (method_exists($msg, 'update_chat')) {
                    $msg->update_chat();
                }
                return ['sent' => true, 'message_id' => $msg->get_id()];
            });

        case 'mark-seen':
            return nibwp_voxel_try(static function () use ($chat, $input) {
                $p1 = ['type' => 'user', 'id' => (int) ($input['party1_id'] ?? get_current_user_id())];
                $p2 = ['type' => (string) ($input['party2_type'] ?? 'user'), 'id' => (int) ($input['party2_id'] ?? 0)];
                if ($p2['id'] <= 0) {
                    return nibwp_voxel_err('missing_args', 'Provide "party2_id".');
                }
                $chat::mark_as_seen($p1, $p2);
                return ['marked' => true];
            });

        case 'notifications':
            return nibwp_voxel_try(static function () use ($input) {
                [$per, $page] = nibwp_voxel_paginate($input);
                $user_id = (int) ($input['user_id'] ?? get_current_user_id());
                $items = \Voxel\Notification::query([
                    'user_id' => $user_id,
                    'limit' => $per,
                    'offset' => ($page - 1) * $per,
                ]);
                return [
                    'notifications' => array_map(static fn ($n) => [
                        'id' => $n->get_id(),
                        'type' => method_exists($n, 'get_type') ? $n->get_type() : null,
                        'seen' => method_exists($n, 'is_seen') ? $n->is_seen() : null,
                        'subject' => method_exists($n, 'get_subject') ? $n->get_subject() : null,
                    ], $items),
                    'count' => count($items),
                ];
            });

        case 'unread-count':
            return nibwp_voxel_try(static function () use ($input) {
                $user_id = (int) ($input['user_id'] ?? get_current_user_id());
                // Voxel counts unread "since" a moment — usually the last time
                // the user opened their notifications. Default to a wide
                // window so the number means "unread", not "unread today".
                $since = (string) ($input['since'] ?? '-1 year');
                return [
                    'user_id' => $user_id,
                    'since' => $since,
                    'unread' => (int) \Voxel\Notification::get_unread_count($user_id, $since),
                ];
            });

        default:
            return nibwp_voxel_err('bad_action', 'Unknown action.');
    }
}
