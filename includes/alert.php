<?php
/**
 * 表单报警：询盘发不出去时，告诉报警服务（monitor/worker.js，跑在 Cloudflare 上），由它合并同类错误后开 GitHub Issue、推钉钉。
 *
 * 三条路：
 * - 访客的浏览器：assets/form-alert.js 在页面内存里记最近几步操作（只记点了什么、填过哪些字段名，不记填的内容），
 *   提交失败时连同错误发到报警服务的 /report。
 * - 本站服务器：询盘存不进去、通知邮件发不出去时，发到 /server（带报警密钥）。只带询盘编号和错误原因，不带客户资料。
 * - 每日自检：报警服务每天带报警密钥（请求头 X-Achord-Monitor）按访客的流程提交一条测试询盘。认出密钥就跳过人机验证、
 *   限流和黑名单，照常校验随机数、照常保存，然后立刻删掉：不发邮件、不推钉钉、不计转化。答复里附上本站的通知配置是否完好。
 *
 * 设置 → 询盘设置里填「报警服务地址」和「报警密钥」才启用；没填时什么都不做。
 */

if (!defined('ABSPATH')) {
    exit;
}

// 发信成功、发信失败分两个选项存：两封通知同时发完时各写各的，成功不会盖掉同时发生的失败，反过来也一样。
const ACHORD_INQUIRY_MAIL_OK = 'achord_inquiry_mail_ok_at';
const ACHORD_INQUIRY_MAIL_FAIL = 'achord_inquiry_mail_fail';

/** 报警服务的地址（只认 https，去掉末尾斜杠）；没填返回空串，三条路都不启用。 */
function achord_inquiry_alert_endpoint(): string
{
    $url = trim((string) (achord_inquiry_options()['alert_url'] ?? ''));
    return preg_match('#^https://[^\s/]+#i', $url) ? untrailingslashit($url) : '';
}

/** 报警密钥（服务器上报和每日自检共用）；太短的不算，免得一个随手填的词就能绕过人机验证。 */
function achord_inquiry_alert_secret(): string
{
    $secret = trim((string) (achord_inquiry_options()['alert_secret'] ?? ''));
    return strlen($secret) >= 24 ? $secret : '';
}

/** 报警服务认站点用的名字：本站域名，去掉 www.。 */
function achord_inquiry_alert_site(): string
{
    $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    return preg_replace('/^www\./', '', $host) ?? $host;
}

/** 报警服务地址和报警密钥都填好了才算启用；任何一项清空，浏览器端、页头记录器都不再加载。 */
function achord_inquiry_alert_enabled(): bool
{
    return achord_inquiry_alert_endpoint() !== '' && achord_inquiry_alert_secret() !== '';
}

/** 浏览器端报警脚本：启用后在每个页面加载（主题去掉的是 achord-inquiry，不是这个）。 */
function achord_inquiry_enqueue_alert_script(): void
{
    if (!achord_inquiry_alert_enabled()) {
        return;
    }
    $endpoint = achord_inquiry_alert_endpoint();
    $prefix = achord_inquiry_config()['prefix'];
    // 提交地址里出现这些片段的 POST 请求才盯：询盘表单和目录下载表单。主题有别的表单可以用过滤器加。
    $watch = (array) apply_filters('achord_inquiry_alert_watch', [$prefix . 'inquiry_submit=1', $prefix . 'catalog_request=1']);
    wp_enqueue_script('achord-form-alert', plugins_url('assets/form-alert.js', ACHORD_INQUIRY_FILE), [], ACHORD_INQUIRY_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
    wp_add_inline_script('achord-form-alert', 'window.achordFormAlertConfig=' . wp_json_encode([
        'endpoint' => $endpoint . '/report',
        'site' => achord_inquiry_alert_site(),
        'watch' => array_values(array_filter(array_map('strval', $watch))),
    ]) . ';', 'before');
}

/**
 * 页头最前面放一小段记录器：人机验证脚本如果在报警脚本（页脚、defer）运行之前就加载失败，这个失败事件不会重放，
 * 报警脚本就看不到。记录器先把失败的地址记下，报警脚本启动后补报。
 */
function achord_inquiry_print_alert_early(): void
{
    if (!achord_inquiry_alert_enabled()) {
        return;
    }
    $js = "window.achordFormAlertEarly=[];window.addEventListener('error',function(e){var t=e.target;"
        . "if(t&&t.tagName==='SCRIPT'&&String(t.src||'').indexOf('challenges.cloudflare.com')>-1){window.achordFormAlertEarly.push(String(t.src))}},true);";
    if (function_exists('wp_print_inline_script_tag')) {
        wp_print_inline_script_tag($js, ['id' => 'achord-form-alert-early']);
    } else {
        echo '<script id="achord-form-alert-early">' . $js . "</script>\n";
    }
}

/** 去掉看起来像邮箱、电话的串，再截短：错误信息里万一带了访客填的内容，也不会报出去。 */
function achord_inquiry_alert_redact(string $text, int $length = 300): string
{
    $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[已隐去]', $text);
    $text = (string) preg_replace('/\+?\d[\d\s().\-]{6,}\d/', '[已隐去]', $text);
    $text = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($text)));
    return mb_substr($text, 0, $length);
}

/**
 * 服务器端报警：发到报警服务的 /server，不等答复（访客那边不被拖慢）。同一请求里同一种错误只报一次。
 * $kind：save_failed（询盘存不进去）、mail_failed（通知邮件发不出去）。
 */
function achord_inquiry_alert(string $kind, string $message, array $extra = []): void
{
    static $sent = [];
    $endpoint = achord_inquiry_alert_endpoint();
    $secret = achord_inquiry_alert_secret();
    if ($endpoint === '' || $secret === '' || isset($sent[$kind])) {
        return;
    }
    $sent[$kind] = true;
    $body = [
        'site' => achord_inquiry_alert_site(),
        'kind' => $kind,
        'message' => achord_inquiry_alert_redact($message),
        'inquiry' => (int) ($extra['inquiry'] ?? 0),
        'form' => sanitize_key((string) ($extra['form'] ?? 'inquiry')) ?: 'inquiry',
        'plugin' => ACHORD_INQUIRY_VERSION,
    ];
    wp_remote_post($endpoint . '/server', [
        'timeout' => 3,
        'blocking' => false,
        'headers' => ['Authorization' => 'Bearer ' . $secret, 'Content-Type' => 'application/json'],
        'body' => wp_json_encode($body),
    ]);
}

/** wp_mail 失败时 WordPress 给的原因，留给紧接着的报警用。 */
function achord_inquiry_last_mail_error(?string $set = null): string
{
    static $error = '';
    if ($set !== null) {
        $error = $set;
    }
    return $error;
}

/** 记下通知邮件最近一次发成功、发失败的时间，自检答复里带上；失败就报警。 */
function achord_inquiry_mail_result(bool $sent, int $post_id, string $lead_type = ''): void
{
    if ($sent) {
        update_option(ACHORD_INQUIRY_MAIL_OK, microtime(true), false); // 带微秒：同一秒里先成后败也分得出先后
        return;
    }
    $reason = achord_inquiry_alert_redact(achord_inquiry_last_mail_error() ?: 'wp_mail 返回失败', 200);
    // 失败时间和原因放同一个选项里一次写入，读到的时间和原因总是同一次失败的。
    update_option(ACHORD_INQUIRY_MAIL_FAIL, ['at' => microtime(true), 'reason' => $reason], false);
    achord_inquiry_alert('mail_failed', $reason, ['inquiry' => $post_id, 'form' => $lead_type ?: 'inquiry']);
}

/** 这次请求是不是报警服务的每日自检（请求头 X-Achord-Monitor 等于报警密钥）。 */
function achord_inquiry_is_monitor(): bool
{
    // 报警关掉了（地址或密钥没填好）就不认自检：清掉地址就能停掉每日自检，被拦下的自检请求走上面的拒收。
    if (!achord_inquiry_alert_enabled()) {
        return false;
    }
    $secret = achord_inquiry_alert_secret();
    $given = (string) ($_SERVER['HTTP_X_ACHORD_MONITOR'] ?? '');
    return $given !== '' && hash_equals($secret, $given);
}

/** 请求带了自检请求头（不管对不对）。认不出的自检请求要拒收，不能当真询盘收下。 */
function achord_inquiry_monitor_header_given(): bool
{
    return trim((string) ($_SERVER['HTTP_X_ACHORD_MONITOR'] ?? '')) !== '';
}

/** 本站通知配置是否完好，自检答复里带给报警服务判断。 */
function achord_inquiry_health(): array
{
    $fail = get_option(ACHORD_INQUIRY_MAIL_FAIL, []);
    $fail = is_array($fail) ? $fail : [];
    $options = achord_inquiry_options();
    $turnstile = $options['turnstile_enabled'] === '1';
    // 最近一条真实询盘（已发布，不含被拦截的草稿，也不含标了无效的垃圾询盘）的时间，报警服务据此发现「很久没有询盘」。
    $latest = get_posts([
        'post_type' => achord_inquiry_post_type(),
        'post_status' => 'publish',
        'numberposts' => 1,
        'orderby' => 'date',
        'order' => 'DESC',
        'suppress_filters' => true,
        'meta_query' => [['key' => '_invalid', 'compare' => 'NOT EXISTS']],
    ]);
    $latest_at = $latest && isset($latest[0]->post_date_gmt) ? (int) strtotime($latest[0]->post_date_gmt . ' UTC') : 0;
    return [
        'plugin' => ACHORD_INQUIRY_VERSION,
        'recipient_ok' => (bool) is_email(achord_inquiry_recipient()), // is_email 成功时返回邮箱本身，只报真假，不把收件邮箱带进 Issue
        'turnstile_on' => $turnstile,
        'turnstile_ready' => !$turnstile || achord_inquiry_turnstile_enabled(),
        'dingtalk' => function_exists('achord_dingtalk_dispatch'),
        'mail_ok_at' => (float) get_option(ACHORD_INQUIRY_MAIL_OK, 0),
        'mail_fail_at' => (float) ($fail['at'] ?? 0),
        'mail_fail_reason' => (string) ($fail['reason'] ?? ''),
        'last_inquiry_at' => $latest_at,
    ];
}

/** 自检提交：照常保存（检验保存这一步），马上彻底删掉，不发任何通知，答复里带上配置状态。 */
function achord_inquiry_monitor_answer(array $fields): void
{
    $fields['source_entry'] = '每日自检';
    $post_id = achord_inquiry_save($fields);
    if (is_wp_error($post_id)) {
        wp_send_json_error(['message' => achord_inquiry_alert_redact($post_id->get_error_message()), 'monitor' => true, 'health' => achord_inquiry_health()], 500);
    }
    if (!wp_delete_post((int) $post_id, true)) {
        // 删不掉就不能说自检正常：这条测试询盘会留在后台、混进询盘统计。
        wp_send_json_error(['message' => sprintf('测试询盘已保存，但删不掉（编号 %d），请到后台询盘列表手动删除', (int) $post_id), 'monitor' => true, 'saved' => true, 'health' => achord_inquiry_health()], 500);
    }
    wp_send_json_success(['monitor' => true, 'saved' => true, 'health' => achord_inquiry_health()]);
}

/** 插件启用时挂上（achord_inquiry_boot）。 */
function achord_inquiry_alert_hooks(): void
{
    add_action('wp_enqueue_scripts', 'achord_inquiry_enqueue_alert_script');
    // 第 1 位：比主题、插件在页头打印的脚本都早（WordPress 的页头脚本在第 8 位）。
    add_action('wp_head', 'achord_inquiry_print_alert_early', 1);
    add_action('wp_mail_failed', static function ($error): void {
        achord_inquiry_last_mail_error(is_object($error) && method_exists($error, 'get_error_message') ? (string) $error->get_error_message() : '');
    });
}
