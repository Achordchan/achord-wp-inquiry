<?php
/**
 * The blocklist of 询盘设置 as WordPress abilities (WordPress 6.9+), so an administrator's connector (the WordPress.com
 * MCP connector lists a site's abilities) can read it and add or remove entries without opening wp-admin.
 *
 * - achord-inquiry/get-blocklist: the entries, read-only.
 * - achord-inquiry/update-blocklist: add and/or remove entries. Only the blocklist changes; the other settings (keys,
 *   recipient, AI) are neither returned nor written. An entry must be a mailbox, @domain, IP or IP range, the same kinds
 *   the blocklist matches (includes/guard.php); anything else is reported back and not saved, so a typo never becomes
 *   an entry that matches nobody (or, like 0.0.0.0/0, everybody).
 *
 * Both need manage_options, as the settings page does.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_abilities_api_categories_init', static function (): void {
    if (achord_inquiry_active() && function_exists('wp_register_ability_category')) {
        wp_register_ability_category('achord-inquiry', [
            'label' => '询盘',
            'description' => '网站询盘的设置（询盘插件 Achord WP Inquiry）。',
        ]);
    }
});

add_action('wp_abilities_api_init', static function (): void {
    if (!achord_inquiry_active() || !function_exists('wp_register_ability')) {
        return;
    }
    $entries_schema = ['type' => 'array', 'items' => ['type' => 'string']];
    $permission = static fn(): bool => current_user_can('manage_options');

    wp_register_ability('achord-inquiry/get-blocklist', [
        'label' => '读取询盘黑名单',
        'description' => '返回询盘设置里的询盘黑名单（每项是邮箱、@整个域名、IP 或 IP 段）。命中黑名单的询盘存成「已拦截」草稿，不发邮件、不推钉钉、不算广告转化。只读。',
        'category' => 'achord-inquiry',
        'output_schema' => [
            'type' => 'object',
            'properties' => ['entries' => $entries_schema, 'count' => ['type' => 'integer']],
        ],
        'execute_callback' => static fn(): array => achord_inquiry_blocklist_report(),
        'permission_callback' => $permission,
        'meta' => [
            'show_in_rest' => true,
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        ],
    ]);

    wp_register_ability('achord-inquiry/update-blocklist', [
        'label' => '修改询盘黑名单',
        'description' => '往询盘黑名单里加（add）或删（remove）条目，每项是邮箱（spam@example.com）、@整个域名（@example.com）、IP（203.0.113.7）或 IP 段（203.0.113.0/24），不分大小写。只改黑名单，其他询盘设置不动。格式不对的条目不保存，在 invalid 里列出；已有的不重复加，在 already_listed 里列出；要删的不在名单里，在 not_listed 里列出。返回改后的完整名单。',
        'category' => 'achord-inquiry',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'add' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '要加入的条目'],
                'remove' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '要删掉的条目'],
            ],
            'additionalProperties' => false,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'added' => $entries_schema,
                'removed' => $entries_schema,
                'already_listed' => $entries_schema,
                'not_listed' => $entries_schema,
                'invalid' => $entries_schema,
                'entries' => $entries_schema,
                'count' => ['type' => 'integer'],
            ],
        ],
        'execute_callback' => static fn($input = null) => achord_inquiry_update_blocklist(
            is_array($input) ? (array) ($input['add'] ?? []) : [],
            is_array($input) ? (array) ($input['remove'] ?? []) : []
        ),
        'permission_callback' => $permission,
        'meta' => [
            'show_in_rest' => true,
            // Removing an entry lets that sender through again, so the change is not merely additive.
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
        ],
    ]);
});

/** The blocklist as the abilities return it. */
function achord_inquiry_blocklist_report(): array
{
    $entries = achord_inquiry_blocklist();
    return ['entries' => $entries, 'count' => count($entries)];
}

/** Whether a blocklist entry (already lower-cased) is one of the kinds achord_inquiry_block_reason() matches. */
function achord_inquiry_valid_block_entry(string $entry): bool
{
    if (str_starts_with($entry, '@')) {
        $domain = substr($entry, 1);
        return str_contains($domain, '.') && (bool) filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }
    if (str_contains($entry, '@')) {
        return (bool) is_email($entry);
    }
    if (str_contains($entry, '/')) {
        return achord_inquiry_valid_range($entry);
    }
    return (bool) filter_var($entry, FILTER_VALIDATE_IP);
}

/** Forget this request's copy of 询盘设置, so the next read comes from the database (another request may have saved since). */
function achord_inquiry_forget_options(): void
{
    wp_cache_delete(ACHORD_INQUIRY_OPTION, 'options');
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
}

/**
 * Add and remove blocklist entries and save the blocklist, one entry per line. The rest of 询盘设置 is saved as it is
 * (theme defaults included, as saving the settings page does), so only the blocklist changes.
 *
 * The whole read-change-save runs under a lock and starts from the saved settings, not this request's cached copy, so
 * two calls at once cannot each save their own list over the other's. Afterwards the saved list is read back: if it is
 * not what was meant (the database refused the write), the call fails instead of reporting entries as added or removed.
 */
function achord_inquiry_update_blocklist(array $add, array $remove)
{
    if (!achord_inquiry_lock('blocklist', 10.0)) {
        return new WP_Error('achord_inquiry_busy', '另一个请求正在改询盘黑名单，请稍后再试。', ['status' => 409]);
    }
    try {
        return achord_inquiry_update_blocklist_locked($add, $remove);
    } finally {
        achord_inquiry_unlock('blocklist');
    }
}

function achord_inquiry_update_blocklist_locked(array $add, array $remove)
{
    $clean = static function (array $items): array {
        $items = array_map(static fn($item): string => is_scalar($item) ? strtolower(trim((string) $item)) : '', $items);
        return array_values(array_unique(array_filter($items, 'strlen')));
    };
    $add = $clean($add);
    $remove = $clean($remove);
    achord_inquiry_forget_options();
    $entries = achord_inquiry_blocklist();
    $result = ['added' => [], 'removed' => [], 'already_listed' => [], 'not_listed' => [], 'invalid' => []];

    foreach ($remove as $entry) {
        $at = array_search($entry, $entries, true);
        if ($at === false) {
            $result['not_listed'][] = $entry;
            continue;
        }
        unset($entries[$at]);
        $result['removed'][] = $entry;
    }
    foreach ($add as $entry) {
        if (!achord_inquiry_valid_block_entry($entry)) {
            $result['invalid'][] = $entry;
        } elseif (in_array($entry, $entries, true)) {
            $result['already_listed'][] = $entry;
        } else {
            $entries[] = $entry;
            $result['added'][] = $entry;
        }
    }

    if ($result['added'] !== [] || $result['removed'] !== []) {
        $entries = array_values($entries);
        $options = achord_inquiry_options();
        $options['blocklist'] = implode("\n", $entries);
        // update_option() also answers false when nothing changed, so the saved list itself is the check.
        update_option(ACHORD_INQUIRY_OPTION, $options);
        achord_inquiry_forget_options();
        if (achord_inquiry_blocklist() !== $entries) {
            return new WP_Error('achord_inquiry_not_saved', '询盘黑名单没有存进去（数据库写入失败），名单没有改动，请稍后再试。', ['status' => 500]);
        }
    }
    return $result + achord_inquiry_blocklist_report();
}
