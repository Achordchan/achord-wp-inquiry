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

        public function update_item_permissions_check($request)
        {
            return achord_inquiry_rest_error('achord_inquiry_read_only', 'Inquiries are read-only here; manage them in wp-admin.', 403);
        }

        public function delete_item_permissions_check($request)
        {
            return achord_inquiry_rest_error('achord_inquiry_read_only', 'Inquiries are read-only here; manage them in wp-admin.', 403);
        }
    }
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
        'blocked' => '黑名单拦截',
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
    $optional = ['blocked', 'lead_type', 'language', 'page_referrer'];
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
