<?php
/**
 * Where inquiries are kept: the 询盘 list in the admin (a private post type, one post per inquiry, every field in post
 * meta as _<name>), its columns and detail box, and a read-only REST route for administrators.
 */

if (!defined('ABSPATH')) {
    exit;
}

function achord_inquiry_post_type(): string
{
    return (string) achord_inquiry_config()['post_type'];
}

function achord_inquiry_register_post_type(): void
{
    $type = achord_inquiry_post_type();
    if (post_type_exists($type)) {
        return;
    }
    $config = achord_inquiry_config();
    register_post_type($type, [
        'labels' => [
            'name' => '询盘', 'singular_name' => '询盘', 'menu_name' => '询盘', 'all_items' => '全部询盘', 'edit_item' => '查看询盘',
            'view_item' => '查看询盘', 'search_items' => '搜索询盘', 'not_found' => '还没有询盘', 'not_found_in_trash' => '回收站里没有询盘',
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'menu_icon' => 'dashicons-email-alt2',
        // custom-fields only so the read-only REST record is published; the Custom Fields box it adds is removed below.
        'supports' => ['custom-fields'],
        'capability_type' => 'post',
        'capabilities' => ['create_posts' => 'do_not_allow'],
        'map_meta_cap' => true,
        'show_in_rest' => true,
        'rest_base' => (string) $config['rest_base'],
        'rest_controller_class' => 'Achord_Inquiry_REST_Controller',
    ]);
}

/**
 * Save one inquiry with its fields; a blocked one as a draft marked [已拦截] with the reason. The title follows the lead
 * type's pattern (Inquiry from <name> - <time>). Returns the post ID or a WP_Error.
 */
function achord_inquiry_save(array $fields, string $block_reason = '')
{
    achord_inquiry_register_post_type();
    $customer = ($fields['name'] ?? '') !== '' ? $fields['name'] : (($fields['company'] ?? '') !== '' ? $fields['company'] : (string) ($fields['email'] ?? ''));
    $title = sprintf(achord_inquiry_lead_type($fields)['title'], $customer, current_time('Y-m-d H:i'));
    $post_id = wp_insert_post([
        'post_type' => achord_inquiry_post_type(),
        'post_status' => $block_reason !== '' ? 'draft' : 'publish',
        // wp_insert_post() and update_post_meta() unslash what they are given; the values here are already unslashed,
        // so they are slashed once more or a backslash the visitor typed (C:\Projects) would be lost.
        'post_title' => wp_slash(($block_reason !== '' ? '[已拦截] ' : '') . $title),
    ], true);
    if (is_wp_error($post_id)) {
        return $post_id;
    }
    foreach ($fields as $key => $value) {
        update_post_meta((int) $post_id, '_' . $key, wp_slash(is_scalar($value) ? (string) $value : ''));
    }
    if ($block_reason !== '') {
        update_post_meta((int) $post_id, '_blocked', wp_slash(achord_inquiry_saved_block_reason($block_reason)));
    }
    return (int) $post_id;
}

if (class_exists('WP_REST_Posts_Controller')) {
    /** The posts controller without its writes. Reading needs manage_options: core lets anyone read a published post. */
    final class Achord_Inquiry_REST_Controller extends WP_REST_Posts_Controller
    {
        public function get_items_permissions_check($request)
        {
            return achord_inquiry_can_read() ? parent::get_items_permissions_check($request) : achord_inquiry_rest_error('rest_forbidden', 'Only administrators can read inquiries.', rest_authorization_required_code());
        }

        public function get_item_permissions_check($request)
        {
            return achord_inquiry_can_read() ? parent::get_item_permissions_check($request) : achord_inquiry_rest_error('rest_forbidden', 'Only administrators can read inquiries.', rest_authorization_required_code());
        }

        public function check_read_permission($post)
        {
            return achord_inquiry_can_read() && parent::check_read_permission($post);
        }

        public function create_item_permissions_check($request)
        {
            return achord_inquiry_rest_error('achord_inquiry_read_only', 'Inquiries are read-only here; manage them in wp-admin.', 403);
        }

        /** The one write: an administrator may set or clear the invalid flag (meta.<rest_record>_invalid), nothing else. */
        public function update_item_permissions_check($request)
        {
            $writable = array_keys($this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE));
            $refused = achord_inquiry_rest_update_refused_params($request, $writable);
            if (!achord_inquiry_can_read() || $refused !== []) {
                return achord_inquiry_rest_error('achord_inquiry_read_only', 'Inquiries are read-only; only meta.' . achord_inquiry_invalid_rest_name() . ' can be written. Refused: ' . implode(', ', $refused), 403);
            }
            return parent::update_item_permissions_check($request);
        }

        /**
         * The flag goes through achord_inquiry_set_invalid() rather than a plain meta write, so a connector's mark or
         * restore counts as the administrator's call (clears _invalid_auto, finishes the screening, sends a missed
         * email) and waits for a screening in progress.
         */
        public function update_item($request)
        {
            $post = get_post((int) $request['id']);
            if (!$post instanceof WP_Post || $post->post_type !== $this->post_type) {
                return achord_inquiry_rest_error('rest_post_invalid_id', 'Invalid inquiry ID.', 404);
            }
            $params = array_merge((array) $request->get_query_params(), (array) $request->get_body_params(), (array) $request->get_json_params());
            // update_item_permissions_check() already refuses a request without the flag; checked again so an update
            // that omits it can never read as a restore.
            $meta = $params['meta'] ?? null;
            $flag = achord_inquiry_invalid_rest_name();
            if (!is_array($meta) || !array_key_exists($flag, $meta) || !is_string($meta[$flag])) {
                return achord_inquiry_rest_error('achord_inquiry_read_only', 'Only meta.' . $flag . ' can be written, and it must be given.', 400);
            }
            if (!achord_inquiry_set_invalid((int) $post->ID, $meta[$flag])) {
                return achord_inquiry_rest_error('achord_inquiry_busy', 'This inquiry is being processed (notifications are going out); try again shortly.', 409);
            }
            $request->set_param('context', 'edit');
            return rest_ensure_response($this->prepare_item_for_response(get_post((int) $post->ID), $request));
        }

        public function delete_item_permissions_check($request)
        {
            return achord_inquiry_rest_error('achord_inquiry_read_only', 'Inquiries are read-only here; manage them in wp-admin.', 403);
        }
    }
}

/** The REST name of the invalid flag: meta.<rest_record>_invalid (meta.boblift_inquiry_invalid on the crane site). */
function achord_inquiry_invalid_rest_name(): string
{
    return (string) achord_inquiry_config()['rest_record'] . '_invalid';
}

/**
 * What an update request would write besides meta.<rest_record>_invalid: a writable post field (title, status, …) in the
 * query string, form body or JSON, any other meta key, or a flag that is not text. Empty means only the flag changes.
 * Parameters the controller does not write (context, _fields, a proxy's own extras) are left alone; $writable is the
 * controller's own list of editable fields, so a field WordPress adds later is refused too.
 */
function achord_inquiry_rest_update_refused_params($request, array $writable): array
{
    $params = array_merge((array) $request->get_query_params(), (array) $request->get_body_params(), (array) $request->get_json_params());
    $refused = [];
    foreach ($writable as $key) {
        if ($key !== 'id' && $key !== 'meta' && array_key_exists($key, $params)) {
            $refused[] = (string) $key;
        }
    }
    $flag = achord_inquiry_invalid_rest_name();
    $meta = $params['meta'] ?? null;
    $meta = is_array($meta) ? $meta : [];
    if (!array_key_exists($flag, $meta)) {
        $refused[] = 'meta.' . $flag . ' (missing)';
    } elseif (!is_string($meta[$flag])) {
        $refused[] = 'meta.' . $flag . ' (not text)';
    }
    foreach (array_keys($meta) as $key) {
        if ($key !== $flag) {
            $refused[] = 'meta.' . $key;
        }
    }
    return $refused;
}

/**
 * Mark an inquiry invalid (a reason such as 与产品无关) or valid again (''). The inquiry stays as it was; the flag only
 * tells reports and the list that it is not a real lead. $auto is the screening's own mark (_invalid_auto), which the
 * safety net may finish; a mark or restore from the list or the connector is the administrator's call and stands.
 */
function achord_inquiry_set_invalid(int $post_id, string $reason, bool $auto = false): bool
{
    // The screening calls this holding the lock already (re-entrant); an administrator waits for a running screening,
    // and is told to try again (false) rather than act alongside it.
    if (!achord_inquiry_lock($post_id)) {
        return false;
    }
    try {
        achord_inquiry_set_invalid_locked($post_id, $reason, $auto);
    } finally {
        achord_inquiry_unlock($post_id);
    }
    return true;
}

function achord_inquiry_set_invalid_locked(int $post_id, string $reason, bool $auto): void
{
    // Read what another request may have written while we waited, not this request's cached copy.
    wp_cache_delete($post_id, 'post_meta');
    $reason = trim(sanitize_text_field($reason));
    if ($reason === '') {
        delete_post_meta($post_id, '_invalid');
        delete_post_meta($post_id, '_invalid_auto');
        // An inquiry the screening stopped (or never finished) never reached sales: restoring it sends the email it
        // missed, once. A blocklist draft stays as it was.
        $verdict = (string) get_post_meta($post_id, '_screen_verdict', true);
        $unfinished = get_post_meta($post_id, '_screen_done', true) !== '1' && get_post_meta($post_id, '_blocked', true) === '';
        if (($verdict === 'spam' || $unfinished) && get_post_meta($post_id, '_mail_sent', true) === '') {
            // Sent from the administrator's request: the sender's saved IP (or none) stands in for theirs.
            $record = achord_inquiry_record($post_id);
            achord_inquiry_ip_override((string) ($record['ip'] ?? ''));
            try {
                achord_inquiry_send_mail(array_diff_key($record, ['invalid' => 1, 'screen_verdict' => 1, 'screen_reason' => 1]), $post_id);
            } finally {
                achord_inquiry_ip_override(null, true);
            }
        }
        // The administrator has decided: the safety net must not mark it invalid again.
        if (get_post_meta($post_id, '_blocked', true) === '') {
            update_post_meta($post_id, '_screen_done', '1');
            wp_clear_scheduled_hook('achord_inquiry_unscreened', [$post_id]);
        }
        return;
    }
    if ($auto) {
        update_post_meta($post_id, '_invalid_auto', '1');
    } else {
        delete_post_meta($post_id, '_invalid_auto');
    }
    update_post_meta($post_id, '_invalid', wp_slash($reason));
}

function achord_inquiry_can_read(): bool
{
    return current_user_can('manage_options');
}

function achord_inquiry_rest_error(string $code, string $message, int $status): WP_Error
{
    return new WP_Error($code, $message, ['status' => $status]);
}

/** Every saved field of one inquiry, by name without the leading underscore. */
function achord_inquiry_record(int $post_id): array
{
    $record = [];
    foreach ((array) get_post_meta($post_id) as $key => $values) {
        $key = (string) $key;
        if (!str_starts_with($key, '_') || str_starts_with($key, '_edit_') || str_starts_with($key, '_wp_')) {
            continue;
        }
        $record[substr($key, 1)] = (string) ($values[0] ?? '');
    }
    return $record;
}

/** The admin labels of everything an inquiry may hold, in the order the detail box lists them. */
function achord_inquiry_admin_labels(): array
{
    $labels = [];
    foreach (achord_inquiry_fields() as $name => $field) {
        $labels[$name] = (string) $field['admin'];
    }
    $labels += [
        'lead_type' => '线索类型',
        'source_entry' => '来源入口',
        'source_title' => '来源页面标题',
        'source_page' => '来源页面',
        'channel' => '来源渠道',
        'page_referrer' => '本页上一站',
        'device' => '访问设备',
        'language' => '页面语言',
        'ip' => 'IP 地址',
        'unchecked' => '未经人机验证',
        'blocked' => '黑名单拦截',
        'invalid' => '已标无效',
        'screen_stars' => '询盘优质度（星）',
        'screen_summary' => 'AI 背调',
        'screen_reason' => '判定原因',
        'screened_by' => '判定方式',
    ];
    foreach (ACHORD_INQUIRY_ATTRIBUTION_LABELS as $name => $label) {
        $labels['attr_' . $name] = '广告来源 · ' . $label;
    }
    return (array) apply_filters('achord_inquiry_admin_labels', $labels);
}

function achord_inquiry_meta_box(WP_Post $post): void
{
    $labels = achord_inquiry_admin_labels();
    $copy = [];
    foreach ($labels as $key => $label) {
        $value = (string) get_post_meta($post->ID, '_' . $key, true);
        if ($value !== '') {
            $copy[] = $label . '：' . $value;
        }
    }
    echo '<p style="margin:0 0 12px;color:#646970">这是前台询盘表单生成的只读详情，不需要像文章一样编辑标题或正文。</p>';
    echo '<button type="button" class="button button-primary" style="margin:0 0 12px" onclick="navigator.clipboard && navigator.clipboard.writeText(this.dataset.copy);this.textContent=\'已复制客户信息\';" data-copy="' . esc_attr(implode("\n", $copy)) . '">复制客户信息</button>';
    echo '<table class="widefat striped"><tbody>';
    $optional = ['unchecked', 'blocked', 'invalid', 'lead_type', 'language', 'page_referrer', 'screen_stars', 'screen_summary', 'screen_reason', 'screened_by'];
    foreach ($labels as $key => $label) {
        $value = (string) get_post_meta($post->ID, '_' . $key, true);
        // Ad attribution exists only for visitors who came from an ad and agreed to advertising cookies.
        if ($value === '' && (str_starts_with((string) $key, 'attr_') || in_array($key, $optional, true))) {
            continue;
        }
        echo '<tr><th style="width:150px">' . esc_html($label) . '</th><td>' . nl2br(esc_html($value)) . '</td></tr>';
    }
    echo '</tbody></table>';
}

function achord_inquiry_columns(array $columns): array
{
    return [
        'cb' => $columns['cb'] ?? '<input type="checkbox">',
        'inquiry_customer' => '客户',
        'inquiry_contact' => '联系方式',
        'inquiry_product' => '意向产品',
        'inquiry_country' => '国家',
        'inquiry_source' => '来源',
        'inquiry_date' => '提交时间',
    ];
}

function achord_inquiry_column_content(string $column, int $post_id): void
{
    $meta = static fn(string $key): string => (string) get_post_meta($post_id, '_' . $key, true);
    switch ($column) {
        case 'inquiry_customer':
            $blocked = $meta('blocked');
            if ($blocked !== '') {
                echo '<span style="display:inline-block;margin:0 0 4px;padding:1px 6px;border:1px solid #64748b;border-radius:2px;color:#475569;font-weight:600">已拦截 · ' . esc_html($blocked) . '</span><br>';
            }
            $invalid = $meta('invalid');
            if ($invalid !== '') {
                echo '<span style="display:inline-block;margin:0 0 4px;padding:1px 6px;border:1px solid #b45309;border-radius:2px;color:#92400e;font-weight:600">无效 · ' . esc_html($invalid) . '</span><br>';
            }
            $stars = (int) $meta('screen_stars');
            if ($stars > 0) {
                echo '<span title="' . esc_attr($meta('screen_summary')) . '" style="color:#d97706;letter-spacing:1px">' . esc_html(achord_inquiry_stars_text($stars)) . '</span><br>';
            }
            $name = $meta('name');
            echo '<strong><a class="row-title" href="' . esc_url((string) get_edit_post_link($post_id)) . '">' . esc_html($name !== '' ? $name : get_the_title($post_id)) . '</a></strong>';
            return;
        case 'inquiry_contact':
            $email = $meta('email');
            $phone = $meta('phone');
            echo esc_html(trim($email . ($email !== '' && $phone !== '' ? ' / ' : '') . $phone));
            return;
        case 'inquiry_product':
            // A theme can put a badge before the product (the crane site marks discontinued models).
            echo (string) apply_filters('achord_inquiry_product_column', esc_html($meta('product')), $post_id);
            return;
        case 'inquiry_country':
            echo esc_html($meta('country'));
            return;
        case 'inquiry_source':
            $entry = $meta('source_entry');
            $page = $meta('source_page');
            $path = $page !== '' ? (string) wp_parse_url($page, PHP_URL_PATH) : '';
            echo esc_html($entry !== '' ? $entry : '—');
            if ($path !== '') {
                echo '<br><a href="' . esc_url($page) . '" target="_blank" rel="noopener">' . esc_html(rawurldecode($path)) . '</a>';
            }
            foreach (['channel', 'device'] as $key) {
                if ($meta($key) !== '') {
                    echo '<br><span style="color:#64748b">' . esc_html($meta($key)) . '</span>';
                }
            }
            return;
        case 'inquiry_date':
            echo esc_html((string) get_the_date('Y-m-d H:i', $post_id));
    }
}

/** The post type's hooks, added once the theme has opted in. */
function achord_inquiry_store_hooks(): void
{
    $type = achord_inquiry_post_type();
    $record = (string) achord_inquiry_config()['rest_record'];
    add_action('init', 'achord_inquiry_register_post_type');

    // Published as meta.<rest_record>, which tools that read only core fields and registered meta (the WordPress.com
    // connector) can see. Nothing is stored under that key: reading it builds the record from the inquiry's own fields.
    add_action('init', static function () use ($type, $record): void {
        register_post_meta($type, $record, [
            'type' => 'object',
            'single' => true,
            'description' => 'The inquiry, read-only: every saved field and, when blocked, why.',
            'show_in_rest' => ['schema' => ['type' => 'object', 'context' => ['edit'], 'additionalProperties' => true]],
            'auth_callback' => '__return_false',
        ]);
    }, 20);
    // The invalid flag, the one field the connector may write (achord_inquiry_rest_update_refused_params()).
    add_action('init', static function () use ($type): void {
        register_post_meta($type, '_invalid', [
            'type' => 'string',
            'single' => true,
            'description' => 'Why this inquiry is not a real lead (spam, unrelated, test); empty when it is one.',
            'show_in_rest' => ['name' => achord_inquiry_invalid_rest_name(), 'schema' => ['type' => 'string', 'context' => ['edit']]],
            'sanitize_callback' => static fn($value): string => trim(sanitize_text_field((string) $value)),
            'auth_callback' => static fn(): bool => achord_inquiry_can_read(),
        ]);
    }, 20);
    // 「标为无效 / 恢复有效」in the list's row actions.
    add_filter('post_row_actions', static function (array $actions, $post) use ($type): array {
        if (!$post instanceof WP_Post || $post->post_type !== $type || !achord_inquiry_can_read()) {
            return $actions;
        }
        $invalid = (string) get_post_meta($post->ID, '_invalid', true) !== '';
        $url = wp_nonce_url(admin_url('admin-post.php?action=achord_inquiry_invalid&post=' . $post->ID . '&mark=' . ($invalid ? '0' : '1')), 'achord_inquiry_invalid_' . $post->ID);
        $actions['achord_inquiry_invalid'] = '<a href="' . esc_url($url) . '">' . ($invalid ? '恢复有效' : '标为无效') . '</a>';
        return $actions;
    }, 10, 2);
    add_action('admin_post_achord_inquiry_invalid', static function () use ($type): void {
        $post_id = (int) ($_GET['post'] ?? 0);
        if (!achord_inquiry_can_read() || get_post_type($post_id) !== $type) {
            wp_die('无权操作这条询盘。', 403);
        }
        check_admin_referer('achord_inquiry_invalid_' . $post_id);
        if (!achord_inquiry_set_invalid($post_id, ($_GET['mark'] ?? '') === '1' ? '后台人工标记' : '')) {
            wp_die('这条询盘正在发通知，请过一会儿再试。', '请稍后再试', ['response' => 409, 'back_link' => true]);
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=' . $type));
        exit;
    });
    add_filter('get_post_metadata', static function ($value, $post_id, $key) use ($type, $record) {
        if ($key !== $record || get_post_type((int) $post_id) !== $type) {
            return $value;
        }
        return [achord_inquiry_can_read() ? achord_inquiry_record((int) $post_id) : []];
    }, 10, 3);
    // The same record as a top-level field.
    add_action('rest_api_init', static function () use ($type, $record): void {
        register_rest_field($type, $record, [
            'get_callback' => static fn(array $post): array => achord_inquiry_can_read() ? achord_inquiry_record((int) $post['id']) : [],
            'schema' => ['type' => 'object', 'context' => ['edit'], 'readonly' => true, 'additionalProperties' => true],
        ]);
    });
    // The default list shows blocked drafts too, so a blocked inquiry can be checked; an explicit status still narrows it.
    add_filter('rest_' . $type . '_query', static function (array $args, $request): array {
        if (!array_key_exists('status', $request->get_query_params())) {
            $args['post_status'] = ['publish', 'draft'];
        }
        return $args;
    }, 10, 2);

    add_filter('use_block_editor_for_post_type', static fn($use, $post_type) => $post_type === $type ? false : $use, 10, 2);
    add_action('add_meta_boxes_' . $type, static function () use ($type): void {
        remove_meta_box('postcustom', $type, 'normal');
        add_meta_box('achord_inquiry_details', '询盘详情', 'achord_inquiry_meta_box', $type, 'normal', 'high');
    });
    add_filter('manage_' . $type . '_posts_columns', 'achord_inquiry_columns');
    add_action('manage_' . $type . '_posts_custom_column', 'achord_inquiry_column_content', 10, 2);
    // An inquiry is read, not edited: the title box, publish options and "Add New" are hidden.
    add_action('admin_head', static function () use ($type): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== $type) {
            return;
        }
        $c = '.post-type-' . sanitize_html_class($type);
        echo '<style>' . $c . ' #titlediv,' . $c . ' #minor-publishing-actions,' . $c . ' #misc-publishing-actions,' . $c . ' #delete-action{display:none!important}' . $c . ' #post-body-content{margin-bottom:0}' . $c . ' #submitdiv .hndle,' . $c . ' #submitdiv .handle-actions{display:none}' . $c . ' #major-publishing-actions{border-top:0}' . $c . ' .wrap .page-title-action{display:none!important}</style>';
    });
}
