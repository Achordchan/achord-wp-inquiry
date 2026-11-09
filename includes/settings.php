<?php
/**
 * 设置 → 询盘设置: where notifications go, Cloudflare Turnstile, the blocklist and whether inquiries keep the IP.
 * Registered with show_in_rest, so administrators can also read and change it at /wp/v2/settings (achord_inquiry_options).
 */

if (!defined('ABSPATH')) {
    exit;
}

function achord_inquiry_sanitize_options($value): array
{
    $value = is_array($value) ? $value : [];
    $current = achord_inquiry_options();
    $clean = [];
    foreach ($current as $key => $old) {
        $given = array_key_exists($key, $value) ? $value[$key] : $old;
        $given = is_scalar($given) ? (string) $given : '';
        $clean[$key] = match ($key) {
            'turnstile_enabled', 'turnstile_local_mode', 'store_ip', 'suspect_check' => in_array($given, ['1', 'true'], true) ? '1' : '0',
            'recipient' => is_email(sanitize_email($given)) ? sanitize_email($given) : '',
            'blocklist' => sanitize_textarea_field($given),
            default => sanitize_text_field($given),
        };
    }
    return $clean;
}

add_action('init', static function (): void {
    if (!achord_inquiry_active()) {
        return;
    }
    $properties = [];
    foreach (array_keys(achord_inquiry_options()) as $key) {
        $properties[$key] = ['type' => 'string'];
    }
    register_setting('achord_inquiry', ACHORD_INQUIRY_OPTION, [
        'type' => 'object',
        'description' => '询盘设置：收件邮箱、Turnstile、黑名单、是否保存 IP、自动标记疑似垃圾',
        'default' => [],
        'sanitize_callback' => 'achord_inquiry_sanitize_options',
        'show_in_rest' => ['schema' => ['type' => 'object', 'properties' => $properties]],
    ]);
});

add_action('admin_menu', static function (): void {
    if (achord_inquiry_active()) {
        add_options_page('询盘设置', '询盘设置', 'manage_options', 'achord-inquiry', 'achord_inquiry_settings_page');
    }
});

function achord_inquiry_settings_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $options = achord_inquiry_options();
    $name = static fn(string $key): string => esc_attr(ACHORD_INQUIRY_OPTION . '[' . $key . ']');
    $checkbox = static function (string $key, string $label) use ($options, $name): void {
        echo '<label><input type="hidden" name="' . $name($key) . '" value="0"><input type="checkbox" name="' . $name($key) . '" value="1"' . checked($options[$key], '1', false) . '> ' . esc_html($label) . '</label>';
    };
    echo '<div class="wrap"><h1>询盘设置</h1><form method="post" action="options.php">';
    settings_fields('achord_inquiry');
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row">收件邮箱</th><td><input class="regular-text" type="email" name="' . $name('recipient') . '" value="' . esc_attr($options['recipient']) . '" placeholder="' . esc_attr(achord_inquiry_recipient()) . '"><p class="description">询盘通知邮件发到这里。留空时用主题给的地址（灰字），都没有就发给站点管理员邮箱。</p></td></tr>';

    echo '<tr><th scope="row">Cloudflare Turnstile</th><td>';
    $checkbox('turnstile_enabled', '启用人机验证');
    echo '<br>';
    $checkbox('turnstile_local_mode', '本地测试模式（用 Cloudflare 的测试密钥，线上不要勾）');
    echo '<p><input class="regular-text" type="text" name="' . $name('turnstile_site_key') . '" value="' . esc_attr($options['turnstile_site_key']) . '" placeholder="Site Key"></p>';
    echo '<p><input class="regular-text" type="password" autocomplete="new-password" name="' . $name('turnstile_secret_key') . '" value="' . esc_attr($options['turnstile_secret_key']) . '" placeholder="Secret Key"></p>';
    echo '<p class="description">在 Cloudflare 后台 Turnstile 里给本站域名建一个小组件，把两个密钥填进来再勾选启用。没填全时不会启用，表单照常提交。</p></td></tr>';

    echo '<tr><th scope="row">询盘黑名单</th><td><textarea class="large-text code" rows="6" name="' . $name('blocklist') . '">' . esc_textarea($options['blocklist']) . '</textarea><p class="description">每行一个：邮箱（spam@example.com）、整个邮箱域名（@example.com）、IP（203.0.113.7）或 IP 段（203.0.113.0/24）。命中的询盘存成「已拦截」草稿，不发邮件、不推钉钉、不算广告转化，客户看到的仍是提交成功。</p></td></tr>';

    echo '<tr><th scope="row">疑似垃圾</th><td>';
    $checkbox('suspect_check', '自动标记疑似垃圾询盘');
    echo '<p class="description">留言里有两句以上群发骗局的套话（索要目录和价目表、问「你是负责人吗」、约 Zoom/Teams 会议等）却一句没提设备本身，或者从机房/VPN 网络提交且没提设备的，照常存下并发邮件和钉钉，但标题前加【疑似垃圾】、自动标为无效、不算 GA4 和广告转化。是真客户的，在询盘列表点「恢复有效」。</p></td></tr>';

    echo '<tr><th scope="row">保存 IP</th><td>';
    $checkbox('store_ip', '询盘保存访客 IP');
    echo '<p class="description">先把隐私政策里关于 IP 的说法改好再勾选。不勾时 IP 照样显示在邮件和钉钉里、IP 黑名单照样生效，只是询盘记录里不留 IP。</p></td></tr>';

    echo '</tbody></table>';
    submit_button();
    echo '</form></div>';
}
