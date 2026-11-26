<?php
/**
 * 设置 → 询盘设置: where notifications go, Cloudflare Turnstile, the blocklist, whether inquiries keep the IP and the
 * form alert service (includes/alert.php).
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
            'turnstile_enabled', 'turnstile_local_mode', 'turnstile_fallback', 'store_ip', 'suspect_check' => in_array($given, ['1', 'true'], true) ? '1' : '0',
            'recipient' => is_email(sanitize_email($given)) ? sanitize_email($given) : '',
            'blocklist' => sanitize_textarea_field($given),
            'ai_timeout' => (string) max(5, min(60, (int) $given ?: 30)),
            'ai_base_url', 'alert_url' => preg_match('#^https://#i', trim($given)) ? esc_url_raw(trim($given)) : '',
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
        'description' => '询盘设置：收件邮箱、Turnstile、黑名单、是否保存 IP、自动标记疑似垃圾、AI 判断、表单报警',
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
    echo '<p class="description">在 Cloudflare 后台 Turnstile 里给本站域名建一个小组件，把两个密钥填进来再勾选启用。没填全时不会启用，表单照常提交。</p>';
    $checkbox('turnstile_fallback', '验证码加载不出来时照收询盘');
    echo '<p class="description">有的浏览器（比如 Facebook、Instagram 里点开的页面）或网络打不开 Cloudflare 的验证码，访客就交不了表单。勾上后，主题的表单脚本发现验证码加载不出来（或本站连不上 Cloudflare 校验）时照常提交：询盘照收、照发邮件和钉钉，标「未经人机验证」，黑名单、限流和垃圾筛查照常。为防有人冒用，同一 IP 每小时最多 ' . (int) ACHORD_INQUIRY_UNCHECKED_IP_LIMIT . ' 条、全站每天最多 ' . (int) ACHORD_INQUIRY_UNCHECKED_SITE_LIMIT . ' 条，超过就仍要验证码。</p></td></tr>';

    echo '<tr><th scope="row">询盘黑名单</th><td><textarea class="large-text code" rows="6" name="' . $name('blocklist') . '">' . esc_textarea($options['blocklist']) . '</textarea><p class="description">每行一个：邮箱（spam@example.com）、整个邮箱域名（@example.com）、IP（203.0.113.7）或 IP 段（203.0.113.0/24）。命中的询盘存成「已拦截」草稿，不发邮件、不推钉钉、不算广告转化，客户看到的仍是提交成功。</p></td></tr>';

    echo '<tr><th scope="row">疑似垃圾</th><td>';
    $checkbox('suspect_check', '自动标记疑似垃圾询盘');
    echo '<p class="description">留言里有两句以上群发骗局的套话（索要目录和价目表、问「你是负责人吗」、约 Zoom/Teams 会议等）却一句没提设备本身，或者从机房/VPN 网络提交且没提设备的，照常存下并发邮件和钉钉，但标题前加【疑似垃圾】、自动标为无效、不算 GA4 和广告转化。是真客户的，在询盘列表点「恢复有效」。</p></td></tr>';

    echo '<tr><th scope="row">AI 判断与背调</th><td>';
    echo '<p><input class="regular-text" type="url" name="' . $name('ai_base_url') . '" value="' . esc_attr($options['ai_base_url']) . '" placeholder="Base URL，例如 https://api.openai.com/v1"></p>';
    echo '<p><input class="regular-text" type="password" autocomplete="new-password" name="' . $name('ai_api_key') . '" value="' . esc_attr($options['ai_api_key']) . '" placeholder="API Key"></p>';
    echo '<p><input class="small-text" type="number" min="5" max="60" name="' . $name('ai_timeout') . '" value="' . esc_attr($options['ai_timeout']) . '"> 秒内没答复就改用规则（默认 30 秒）。访客不用等：询盘存好就显示提交成功，判断在后台跑，只是邮件和钉钉晚这么几十秒到；后台中途断开也不会丢，3 分钟后补发。</p>';
    echo '<p><input class="regular-text" type="text" name="' . $name('ai_model') . '" value="' . esc_attr($options['ai_model']) . '" placeholder="模型，例如 gpt-4o-mini"></p>';
    echo '<p class="description">三项都填（OpenAI 兼容的 /chat/completions 接口，只认 https）才启用。每条询盘先由服务器查 IP 归属、邮箱域名官网和注册日期、来源渠道，再交给 AI 判断：垃圾询盘钉钉只推「拦截一条垃圾询盘，原因 …」，邮件照发但标题加【AI 拦截】、正文写明原因，标无效、不算转化（误判点「恢复有效」）；正常询盘在邮件和钉钉里带 1–5 星优质度和一句背调。AI 出错或超时时改用上面的规则。不填就不调用 AI。填好保存后用下面的「测试连接」检查。</p></td></tr>';

    echo '<tr><th scope="row">表单报警</th><td>';
    echo '<p><input class="regular-text" type="url" name="' . $name('alert_url') . '" value="' . esc_attr($options['alert_url']) . '" placeholder="报警服务地址，例如 https://form-alert.example.com"></p>';
    echo '<p><input class="regular-text" type="password" autocomplete="new-password" name="' . $name('alert_secret') . '" value="' . esc_attr($options['alert_secret']) . '" placeholder="报警密钥（至少 24 个字符）"></p>';
    echo '<p class="description">两项都填才启用。访客提交失败（断网、服务器出错、验证码加载不出来等）时，浏览器把错误和失败前几步操作（只记点了什么、填过哪些字段名，不记填的内容）发给报警服务；询盘存不进去、通知邮件发不出去时，本站服务器也会报。报警服务合并同类错误后开 GitHub Issue、推钉钉。报警服务每天还会带这个密钥提交一条测试询盘：照常保存后立刻删除，不发邮件、不推钉钉、不算转化。密钥和报警服务里给本站设的必须一样。</p></td></tr>';

    echo '<tr><th scope="row">保存 IP</th><td>';
    $checkbox('store_ip', '询盘保存访客 IP');
    echo '<p class="description">先把隐私政策里关于 IP 的说法改好再勾选。不勾时 IP 照样显示在邮件和钉钉里、IP 黑名单照样生效，只是询盘记录里不留 IP。</p></td></tr>';

    echo '</tbody></table>';
    submit_button();
    echo '</form>';

    // The test button: its own form, so it never saves the settings above. It uses what is saved.
    echo '<h2>测试 AI 连接</h2><p>先点上面的「保存更改」，再点测试：用已保存的设置发两条样例询盘（一条真实买家、一条群发骗局）给 AI，显示结果和耗时。不会存询盘、不发邮件和钉钉。正常情况应是第一条判正常并给星、第二条判垃圾。</p>';
    $results = get_transient('achord_inquiry_ai_test_' . get_current_user_id());
    if (is_array($results)) {
        delete_transient('achord_inquiry_ai_test_' . get_current_user_id());
        foreach ($results as $result) {
            $answer = $result['answer'] ?? null;
            if (is_array($answer)) {
                $text = $answer['spam'] ? '判为垃圾：' . $answer['reason'] : '正常询盘 ' . achord_inquiry_stars_text((int) $answer['stars']) . ($answer['summary'] !== '' ? '，' . $answer['summary'] : '');
                // Connected either way; amber when the verdict is spam so it is not read as a pass at a glance.
                $class = $answer['spam'] ? 'notice-warning' : 'notice-success';
            } else {
                $text = '失败：' . (string) ($result['error'] ?? '');
                $class = 'notice-error';
            }
            echo '<div class="notice ' . $class . ' inline"><p><strong>' . esc_html((string) $result['label']) . '</strong>（' . esc_html((string) $result['seconds']) . ' 秒）：' . esc_html($text) . '</p></div>';
        }
    }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="achord_inquiry_ai_test">';
    wp_nonce_field('achord_inquiry_ai_test');
    submit_button('测试连接', 'secondary', 'submit', false);
    echo '</form></div>';
}

add_action('admin_post_achord_inquiry_ai_test', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('无权操作。', 403);
    }
    check_admin_referer('achord_inquiry_ai_test');
    if (function_exists('set_time_limit')) {
        @set_time_limit(150);
    }
    set_transient('achord_inquiry_ai_test_' . get_current_user_id(), achord_inquiry_ai_test(), 300);
    wp_safe_redirect(admin_url('options-general.php?page=achord-inquiry'));
    exit;
});
