<?php
// 不依赖 WordPress 的单元测试：php tests/test.php。只桩出插件用到的 WordPress 函数。

define('ABSPATH', __DIR__);
define('WEEK_IN_SECONDS', 604800);
define('HOUR_IN_SECONDS', 3600);

$GLOBALS['options'] = [];
$GLOBALS['filters'] = [];
$GLOBALS['actions'] = [];
$GLOBALS['meta'] = [];
$GLOBALS['mails'] = [];
$GLOBALS['dingtalk'] = [];
$GLOBALS['theme_support'] = ['achord-inquiry' => [['site_label' => '测试站', 'prefix' => 'boblift_', 'success_url' => 'https://example.com/success.html']]];

class JsonExit extends Exception
{
    public bool $success;
    public array $data;
    public int $status;
    public function __construct(bool $success, $data, int $status = 200)
    {
        parent::__construct('json');
        $this->success = $success;
        $this->data = is_array($data) ? $data : [];
        $this->status = $status;
    }
}
class WP_Error
{
    public function __construct(public string $code = '', public string $message = '', public $data = null) {}
}
class WP_Post
{
    public int $ID = 0;
}

function get_theme_support($f) { return $GLOBALS['theme_support'][$f] ?? false; }
function current_theme_supports($f) { return isset($GLOBALS['theme_support'][$f]); }
function apply_filters($h, $v, ...$a) { return isset($GLOBALS['filters'][$h]) ? $GLOBALS['filters'][$h]($v, ...$a) : $v; }
function add_filter($h, $cb, $p = 10, $n = 1) {}
function add_action($h, $cb, $p = 10, $n = 1) { $GLOBALS['actions'][$h][] = $cb; }
function do_action($h, ...$a) { foreach ($GLOBALS['actions'][$h] ?? [] as $cb) { $cb(...$a); } }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; return true; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function home_url($p = '') { return 'https://example.com' . $p; }
function admin_url($p = '') { return 'https://example.com/wp-admin/' . $p; }
function untrailingslashit($s) { return rtrim($s, '/'); }
function sanitize_text_field($s) { return trim(preg_replace('/\s+/', ' ', strip_tags((string) $s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_email($s) { return filter_var(trim((string) $s), FILTER_VALIDATE_EMAIL) ?: ''; }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_html_class($s) { return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $s); }
function is_email($s) { return (bool) filter_var((string) $s, FILTER_VALIDATE_EMAIL); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url_raw($s) { return preg_match('#^(https?://|/)#', (string) $s) ? (string) $s : ''; }
function wp_unslash($v) { return $v; }
function wp_slash($v) { return addslashes((string) $v); }
function wp_get_referer() { return $GLOBALS['referer'] ?? false; }
function wp_specialchars_decode($s, $q = 0) { return htmlspecialchars_decode((string) $s, ENT_QUOTES); }
function get_bloginfo($k) { return 'Test Site'; }
function wp_date($f, $t = null) { return gmdate($f, $t ?? 1767225600); }
function current_time($f) { return gmdate($f, 1767225600); }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function wp_remote_get($u, $a = []) { $GLOBALS['gets'][] = $u; return ['code' => 200, 'body' => 'CA']; }
function wp_remote_post($u, $a = []) { return ['code' => 200, 'body' => '{"success":true}']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_verify_nonce($n, $a) { return $n === 'good-' . $a; }
function wp_send_json_success($d = null, $s = 200) { throw new JsonExit(true, $d, $s); }
function wp_send_json_error($d = null, $s = 400) { throw new JsonExit(false, $d, $s); }
function wp_die($m = '', $t = '', $a = []) { throw new JsonExit(false, ['message' => $m], 403); }
function add_query_arg($k, $v, $u) { return $u . (str_contains($u, '?') ? '&' : '?') . $k . '=' . $v; }
function post_type_exists($t) { return true; }
function wp_insert_post($a, $e = false) { $a['post_title'] = stripslashes($a['post_title']); $GLOBALS['inserted'][] = $a; return count($GLOBALS['inserted']) + 100; }
// 和 WordPress 一样，存之前先 unslash。
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = stripslashes((string) $v); return true; }
function get_post_meta($id, $k = '', $single = false) { return $k === '' ? array_map(fn($v) => [$v], $GLOBALS['meta'][$id] ?? []) : ($GLOBALS['meta'][$id][$k] ?? ''); }
function wp_mail($to, $subject, $body, $headers = []) { $GLOBALS['mails'][] = compact('to', 'subject', 'body', 'headers'); return true; }
function achord_dingtalk_dispatch($message, $source) { $GLOBALS['dingtalk'][] = [$message, $source]; }

class FakeWpdb
{
    public string $options = 'wp_options';
    public array $rows = [];
    public function prepare($sql, ...$args) { return [$sql, $args]; }
    public function esc_like($s) { return $s; }
    public function query($q)
    {
        [$sql, $args] = $q;
        if (str_starts_with($sql, 'INSERT IGNORE')) {
            $this->rows[$args[0]] ??= 0;
            return 1;
        }
        if (str_starts_with($sql, 'UPDATE')) {
            if (($this->rows[$args[0]] ?? 0) < $args[1]) {
                $this->rows[$args[0]]++;
                return 1;
            }
            return 0;
        }
        return 0;
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();

require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/context.php';
require dirname(__DIR__) . '/includes/network.php';
require dirname(__DIR__) . '/includes/guard.php';
require dirname(__DIR__) . '/includes/store.php';
require dirname(__DIR__) . '/includes/notify.php';
require dirname(__DIR__) . '/includes/submit.php';

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    if (!$ok) {
        $failures++;
    }
}

// 配置：主题声明的值生效，乱写的前缀退回默认。
$config = achord_inquiry_config();
check($config['site_label'] === '测试站' && $config['prefix'] === 'boblift_' && $config['post_type'] === 'inquiry', '读主题声明的配置');
check(achord_inquiry_name('inquiry_nonce') === 'boblift_inquiry_nonce', '字段名带站点前缀（起重机站旧表单的名字不变）');
$GLOBALS['theme_support']['achord-inquiry'][0]['prefix'] = 'Bad-Prefix';
check(achord_inquiry_config()['prefix'] === 'achord_', '不合法的前缀退回 achord_');
$GLOBALS['theme_support']['achord-inquiry'][0]['prefix'] = 'boblift_';

// 设置：没保存过时用主题给的默认值；保存后以保存的为准。
$GLOBALS['filters']['achord_inquiry_default_options'] = fn($d) => ['blocklist' => "spam@example.com\n@bad.test\n203.0.113.0/24", 'turnstile_site_key' => 'site'] + $d;
check(achord_inquiry_options()['turnstile_site_key'] === 'site', '没保存时用主题的现有设置');
$GLOBALS['options'][ACHORD_INQUIRY_OPTION] = ['turnstile_site_key' => 'saved', 'blocklist' => "spam@example.com\n@bad.test\n203.0.113.0/24\n0.0.0.0/0\n198.51.100.9"];
check(achord_inquiry_options()['turnstile_site_key'] === 'saved', '保存后以插件设置为准');

// 黑名单。
check(achord_inquiry_block_reason('Spam@Example.com', '') === '邮箱 spam@example.com', '邮箱黑名单，不分大小写');
check(achord_inquiry_block_reason('a@bad.test', '') === '邮箱 @bad.test', '整个域名');
check(achord_inquiry_block_reason('x@ok.test', '203.0.113.77') === 'IP 203.0.113.0/24', 'IP 段');
check(achord_inquiry_block_reason('x@ok.test', '198.51.100.9') === 'IP 198.51.100.9', '单个 IP');
check(achord_inquiry_block_reason('x@ok.test', '192.0.2.1') === '', '写错的 /0 不会拦所有人');
check(achord_inquiry_mailbox('K.Habib+x@googlemail.com') === 'khabib@gmail.com', 'Gmail 忽略点和 +标签');
check(achord_inquiry_saved_block_reason('IP 198.51.100.9') === 'IP 黑名单', '不存 IP 时拦截原因不写具体 IP');

// IP：只信 Cloudflare 网段发来的 CF-Connecting-IP。
$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.5';
check(achord_inquiry_client_ip() === '198.51.100.20', '非 Cloudflare 来的转发头不信');
$_SERVER['REMOTE_ADDR'] = '172.70.1.1';
check(achord_inquiry_client_ip() === '203.0.113.5', 'Cloudflare 来的转发头采信');
unset($_SERVER['HTTP_CF_CONNECTING_IP']);
check(achord_inquiry_ip_country('8.8.8.8') === '加拿大', 'IP 查国家并转中文');

// 来源渠道。
check(achord_inquiry_channel(['attr_gclid' => 'abc', 'attr_utm_term' => 'knuckle crane']) === ['Google Ads 广告', '关键词 knuckle crane'], '点击编号算 Google 广告');
check(achord_inquiry_channel(['source_page' => 'https://example.com/p/?gclid=zz&gad_campaignid=123']) === ['Google Ads 广告', '广告系列 123'], '提交页带点击编号也算');
check(achord_inquiry_channel(['attr_utm_source' => 'newsletter', 'attr_utm_medium' => 'email']) === ['营销链接', 'newsletter / email'], 'utm 算营销链接');
check(achord_inquiry_channel(['page_referrer' => 'google.com.sg']) === ['自然搜索', 'Google'], 'Google 自然搜索');
check(achord_inquiry_channel(['page_referrer' => 'gemini.google.com']) === ['AI 助手推荐', 'Gemini'], 'Gemini 不算 Google 搜索');
check(achord_inquiry_channel(['page_referrer' => 'docs.google.com']) === ['外部网站', 'docs.google.com'], 'docs.google.com 是外部网站');
check(achord_inquiry_channel(['page_referrer' => 'm.facebook.com']) === ['社交媒体', 'Facebook'], '社交媒体');
check(achord_inquiry_channel(['ad_consent' => 'false'])[0] === '来源未知', '没同意 Cookie 时说明来源未知');
check(achord_inquiry_channel([])[0] === '直接访问', '什么都没有算直接访问');

// 访问设备。
check(achord_inquiry_device('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1') === '手机 · iPhone · Safari', 'iPhone Safari');
check(achord_inquiry_device('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15', true) === '平板 · iPad · Safari', '能触屏的 Mac 是 iPad');
check(achord_inquiry_device('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 Edg/130.0') === '电脑 · Windows · Edge', 'Windows Edge');
check(achord_inquiry_device('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36 [FBAN/FBIOS]') === '手机 · Android · Facebook 内置浏览器', 'Facebook 内置浏览器');

// 广告参数清洗。
check(achord_inquiry_attribution_clean('gclid', 'Cj0K_abc-1.2~=') === 'Cj0K_abc-1.2~=', '点击编号原样保留');
check(achord_inquiry_attribution_clean('gclid', 'abc<script>') === '', '带标记的点击编号整个丢掉');
check(achord_inquiry_attribution_clean('referrer', 'https://www.Google.com/x') === '', '进站前网站只认主机名');
check(achord_inquiry_attribution_clean('referrer', 'www.Bing.com') === 'bing.com', '主机名去 www 转小写');
check(achord_inquiry_attribution_clean('nope', 'x') === '', '不认识的参数不收');

// 来源页面：广告参数拆出来，标题去掉品牌。
$page = achord_inquiry_source_page(['source_page' => '/products/sq5?gclid=abc&utm_source=g&page_id=3', 'source_title' => 'SQ5 Crane | BOB-LIFT']);
check($page['url'] === 'https://example.com/products/sq5?page_id=3' && $page['title'] === 'SQ5 Crane' && $page['params'] === ['gclid' => 'abc', 'utm_source' => 'g'], '来源页面拆分');
check(achord_inquiry_source_page(['source_page' => 'https://example.com/zh/', 'source_title' => 'BOB-LIFT | Home'])['title'] === '首页 · Home', '各语种首页叫首页');

// 提交：整个流程。
function submit(array $post): JsonExit
{
    $_POST = $post + ['boblift_inquiry_response' => 'json', 'boblift_inquiry_nonce' => 'good-boblift_submit_inquiry'];
    try {
        achord_inquiry_handle();
    } catch (JsonExit $e) {
        return $e;
    }
    throw new RuntimeException('handler did not answer');
}
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile/15E148 Safari/604.1';
$GLOBALS['referer'] = 'https://example.com/products/sq5/?gclid=page1';

$r = submit(['boblift_inquiry_nonce' => 'bad', 'email' => 'a@b.test', 'message' => 'x']);
check(!$r->success && $r->status === 403, '随机数不对拒收');

$r = submit(['boblift_inquiry_website' => 'http://spam', 'email' => 'a@b.test', 'message' => 'x']);
check($r->success && $r->data['id'] === 0 && !$GLOBALS['mails'], '蜜罐：假装成功，不存不发');

$r = submit(['email' => 'a@b.test', 'message' => '']);
check(!$r->success && $r->data['message'] === achord_inquiry_message('requiredFields'), '缺必填项');

$r = submit(['email' => 'spam@example.com', 'message' => 'buy']);
check($r->success && $r->data['id'] === 0 && !$GLOBALS['mails'] && str_starts_with(end($GLOBALS['inserted'])['post_title'], '[已拦截] ') && end($GLOBALS['inserted'])['post_status'] === 'draft', '黑名单：存成已拦截草稿、不发邮件');

$GLOBALS['options'][ACHORD_INQUIRY_OPTION]['store_ip'] = '1';
$r = submit([
    'name' => 'Ana', 'email' => 'ana@client.test', 'phone' => '+1 555', 'product' => 'SQ5 Crane', 'message' => "Need 2 units\nto Lagos, drawings in C:\\Projects\\crane",
    'source_entry' => 'Product page', 'source_title' => 'SQ5 | BOB-LIFT', 'boblift_lang' => 'es', 'boblift_touch' => '0',
    'boblift_attr_gclid' => 'click1', 'boblift_attr_utm_term' => 'knuckle', 'boblift_page_referrer' => 'www.google.com', 'boblift_ad_consent' => 'true',
]);
$id = $r->data['id'];
$saved = $GLOBALS['meta'][$id] ?? [];
check($r->success && $id > 0 && $r->data['redirect'] === 'https://example.com/success.html?sent=' . $id, '成功：返回编号和带 ?sent= 的感谢页');
check(($saved['_email'] ?? '') === 'ana@client.test' && ($saved['_attr_gclid'] ?? '') === 'click1' && ($saved['_channel'] ?? '') === 'Google Ads 广告 · 关键词 knuckle' && ($saved['_device'] ?? '') === '手机 · iPhone · Safari' && ($saved['_ip'] ?? '') === '192.0.2.10' && ($saved['_language'] ?? '') === 'es', '存下字段、来源、设备、IP、语言');
check(($saved['_mail_sent'] ?? '') === '1', '记下邮件已发');
check(str_ends_with($saved['_message'] ?? '', 'C:\\Projects\\crane'), '留言里的反斜杠原样存下');
$mail = end($GLOBALS['mails']);
check($mail['subject'] === '【测试站】询盘飞奔而来，请及时跟进：ana@client.test', '邮件主题带站点名');
check(str_contains($mail['body'], '测试站收到一条新询盘') && str_contains($mail['body'], 'Google Ads 广告') && str_contains($mail['body'], '西班牙语') && str_contains($mail['body'], '192.0.2.10') && str_contains($mail['body'], "Need 2 units<br>\nto Lagos"), '邮件正文有来源、语言、IP、留言');
check(str_contains($mail['body'], '提交页 gclid') && str_contains($mail['body'], 'page1') && str_contains($mail['body'], '访客点过不止一次广告'), '提交页的另一次点击单独列出');
check(in_array('Reply-To: ana@client.test', $mail['headers'], true), '回复直接到客户');
[$ding, $source] = end($GLOBALS['dingtalk']);
check($source === 'achord:inquiry' && $ding['title'] === '📩 测试站新询盘' && $ding['headline'] === 'SQ5 Crane', '推钉钉：标题和产品');
$labels = array_column($ding['fields'], 'label');
check($labels === ['姓名', '邮箱', '电话', '产品', '留言'] && end($ding['fields'])['long'] === true, '钉钉字段用短名，留言单独成段');
$meta_labels = array_column($ding['meta'], 'label');
check(in_array('来源渠道', $meta_labels, true) && in_array('访问设备', $meta_labels, true) && in_array('IP 地址', $meta_labels, true) && in_array('来源国家', $meta_labels, true), '钉钉有来源渠道、设备、国家、IP');

// 主题注册的线索类型（目录下载）：自己的标题、不显示留言。
$GLOBALS['filters']['achord_inquiry_lead_types'] = fn($t) => $t + ['catalog_request' => ['headline' => '新目录下载请求', 'kind' => '目录下载', 'dingtalk_title' => '📘 测试站目录下载', 'show_message' => false, 'subject' => '【测试站】客户索取目录：']];
[$subject, $body] = achord_inquiry_admin_mail(['email' => 'c@d.test', 'lead_type' => 'catalog_request', 'message' => 'PDF catalog request'], 9);
check($subject === '【测试站】客户索取目录：c@d.test' && str_contains($body, '新目录下载请求') && str_contains($body, '目录下载') && !str_contains($body, 'PDF catalog request'), '目录下载用自己的说法，不显示留言');
check(achord_inquiry_dingtalk_message(9, ['email' => 'c@d.test', 'lead_type' => 'catalog_request', 'message' => 'x'])['title'] === '📘 测试站目录下载', '目录下载的钉钉标题');

// 主题加的字段（装载站的 WhatsApp）进邮件和钉钉。
$GLOBALS['filters']['achord_inquiry_fields'] = fn($f) => $f + ['whatsapp' => ['label' => 'WhatsApp', 'short' => 'WhatsApp', 'type' => 'text']];
check(str_contains(achord_inquiry_admin_mail(['email' => 'e@f.test', 'whatsapp' => '+234 801'], 1)[1], '+234 801'), '主题加的字段进邮件');
check(in_array('WhatsApp', array_column(achord_inquiry_dingtalk_message(1, ['email' => 'e@f.test', 'whatsapp' => '+234 801'])['fields'], 'label'), true), '主题加的字段进钉钉');

// 收件人：后台填了就用后台的，主题给的只在没填时用。
$GLOBALS['filters']['achord_inquiry_recipient'] = fn($e) => 'theme@site.test';
$GLOBALS['options'][ACHORD_INQUIRY_OPTION]['recipient'] = 'sales@site.test';
check(achord_inquiry_recipient() === 'sales@site.test', '后台填的收件人优先于主题');
$GLOBALS['options'][ACHORD_INQUIRY_OPTION]['recipient'] = '';
check(achord_inquiry_recipient() === 'theme@site.test', '后台没填时用主题给的');
unset($GLOBALS['filters']['achord_inquiry_recipient']);
$GLOBALS['options']['admin_email'] = 'admin@site.test';
check(achord_inquiry_recipient() === 'admin@site.test', '都没有时发给管理员邮箱');

// 限流：同一 IP 十分钟内第 6 条要等。
$GLOBALS['wpdb']->rows = [];
$results = [];
for ($i = 0; $i < 6; $i++) {
    $results[] = achord_inquiry_reserve_slot();
}
check($results === [true, true, true, true, true, false], '同一 IP 十分钟 5 条');

// 表单要输出的隐藏字段。
function wp_nonce_field($a, $n, $r = true, $e = true) { return '<input type="hidden" name="' . $n . '" value="good-' . $a . '">'; }
function wp_get_document_title() { return 'Contact | BOB-LIFT'; }
$hidden = achord_inquiry_hidden_fields(['json' => true, 'source' => 'Header button', 'language' => 'fr']);
check(str_contains($hidden, 'name="action" value="boblift_submit_inquiry"') && str_contains($hidden, 'name="boblift_inquiry_response" value="json"') && str_contains($hidden, 'name="boblift_lang" value="fr"') && str_contains($hidden, 'name="boblift_inquiry_nonce"') && str_contains($hidden, 'name="boblift_inquiry_website"') && str_contains($hidden, 'value="Header button"'), '隐藏字段齐全');

// 标为无效：只改这一个标记，询盘本身不动；接口只认 meta.<rest_record>_invalid。
function delete_post_meta($id, $k) { unset($GLOBALS['meta'][$id][$k]); return true; }
$GLOBALS['theme_support']['achord-inquiry'][0]['rest_record'] = 'boblift_inquiry';
check(achord_inquiry_invalid_rest_name() === 'boblift_inquiry_invalid', '无效标记的接口名跟着 rest_record');
$GLOBALS['meta'][501] = ['_name' => 'Cynrhia', '_message' => 'What is best for farming dry land'];
achord_inquiry_set_invalid(501, ' 与产品无关 ');
check(achord_inquiry_record(501)['invalid'] === '与产品无关' && achord_inquiry_record(501)['name'] === 'Cynrhia', '标为无效后记录里带 invalid，其他字段不变');
achord_inquiry_set_invalid(501, '');
check(!isset(achord_inquiry_record(501)['invalid']), '清空原因即恢复有效');
class FakeRestRequest
{
    public function __construct(private array $json, private array $body = [], private array $query = []) {}
    public function get_json_params() { return $this->json; }
    public function get_body_params() { return $this->body; }
    public function get_query_params() { return $this->query; }
}
$writable = ['date', 'date_gmt', 'slug', 'status', 'password', 'title', 'content', 'author', 'excerpt', 'meta', 'template', 'id'];
$refused = fn(array $json, array $body = [], array $query = []) => achord_inquiry_rest_update_refused_params(new FakeRestRequest($json, $body, $query), $writable);
check($refused(['id' => 501, 'meta' => ['boblift_inquiry_invalid' => '与产品无关']]) === [], '只带无效标记的更新放行');
check($refused(['meta' => ['boblift_inquiry_invalid' => '']]) === [], '清空标记也放行');
check($refused(['title' => 'x', 'meta' => ['boblift_inquiry_invalid' => 'spam']]) === ['title'], '顺带改标题的拒绝');
check($refused(['status' => 'draft']) === ['status', 'meta.boblift_inquiry_invalid (missing)'], '改状态的拒绝');
check($refused(['meta' => ['boblift_inquiry_invalid' => 'spam', 'jetpack_seo_noindex' => true]]) === ['meta.jetpack_seo_noindex'], '顺带改别的 meta 的拒绝');
check($refused(['meta' => ['boblift_inquiry_invalid' => ['x']]]) === ['meta.boblift_inquiry_invalid (not text)'], '标记不是文字的拒绝');
check($refused(['meta' => ['boblift_inquiry_invalid' => 'spam']], [], ['title' => 'changed', 'status' => 'publish']) === ['status', 'title'], '网址参数里顺带改标题/状态的拒绝');
check($refused([], [], ['meta' => ['jetpack_seo_noindex' => true]]) === ['meta.boblift_inquiry_invalid (missing)', 'meta.jetpack_seo_noindex'], '网址参数里塞别的 meta 的拒绝');
check($refused(['meta' => ['boblift_inquiry_invalid' => 'spam']], ['_wpnonce' => 'abc'], ['context' => 'edit', '_fields' => 'id,meta', 'http_envelope' => '1']) === [], '不写入的参数（context、_fields、_wpnonce、代理自带参数）照样放行');


// 疑似垃圾：2026-11 起重机站真实询盘原文（垃圾 4 条、真客户 6 条）。
$spam = [
    'Duarte' => "Hello\r\nWe are interested in your products and would like to explore a potential business partnership.\r\nPlease send us your latest product catalogue, company profile, price list, MOQ, and Lead time.\r\nMoreso, when would you be available for a Zoom,Teams or Google virtual Meeting so our IT Department can schedule for the online meeting to discuss further on our order specifications.",
    'Bill' => "Dear sir/madam,\r\nI hope this email finds you well.\r\nI am writing to express our strong interest in the Company's product.\r\nAre you the right person to discuss our needs with or Could you please redirect me to the appropriate sales representative to discuss more about our needs.",
    'Yaron' => "Good day,\r\nPlease be informed that we are interested in placing orders for some of your products.\r\nKindly provide your latest updated catalog and price list for our perusal to enable us to proceed with the order without delay.",
    'Khalifa' => "Hello Dear,\r\nThis is Khalifa Habib Purchase Manager at Boodai Trading Co. Ltd,\r\nWe wish to place an order on your products,\r\nPlease revert back with your updated catalogs and price list for our view and selections.",
];
foreach ($spam as $who => $text) {
    check(achord_inquiry_suspect_reason(['message' => $text], '192.0.2.10') !== '', "疑似垃圾：{$who} 的模板话术");
}
$real = [
    'Julian' => '6 metros',
    'Hamrajh' => "10 tons. we have a hino 500 chassis \r\nCrane must have winch and remote control",
    'Clinton' => "Hi \r\nWe are looking to have 2 tail lifts fitted to JAC X200 1 x Jhb 1 x Cpt\r\nCould you please send us your best offer.",
    'Rasil' => 'До 1т',
    'Artur' => 'Hello! We have purchased a QQZ-50 (SQ3.2 ZA3) crane-manipulator. Please could you provide us with the operating and maintenance manual?',
    'Catalogue buyer' => 'Please send your catalogue and price list for a 5 ton knuckle boom crane.',
];
foreach ($real as $who => $text) {
    check(achord_inquiry_suspect_reason(['message' => $text], '192.0.2.10') === '', "真客户不误判：{$who}");
}
$GLOBALS['transients']['achord_inquiry_org_' . md5('190.2.151.135')] = 'AS49981 WorldStream B.V.';
foreach ($real as $who => $text) {
    check(achord_inquiry_suspect_reason(['message' => $text], '190.2.151.135') === '', "真客户走 VPN 也不误判：{$who}");
}
check(achord_inquiry_scam_phrases('Are you the right person? Please send the price list.') === ['price list', 'right person'], '同一句话不重复算');
// 机房/VPN 网络：留言没提设备才算，提了设备不算。
$GLOBALS['transients']['achord_inquiry_org_' . md5('190.2.151.135')] = 'AS49981 WorldStream B.V.';
$GLOBALS['transients']['achord_inquiry_org_' . md5('179.19.132.98')] = 'AS271773 PARTNERS TELECOM COLOMBIA SAS';
check(str_contains(achord_inquiry_suspect_reason(['message' => 'We need your offer.'], '190.2.151.135'), 'WorldStream'), '机房 IP + 没提设备：疑似');
check(achord_inquiry_suspect_reason(['message' => 'Need a 3 ton crane'], '190.2.151.135') === '', '机房 IP 但写了设备：不算');
check(achord_inquiry_suspect_reason(['message' => 'We need your offer.'], '179.19.132.98') === '', '家用/手机网络：不算');

// 疑似垃圾整个流程：存下、发邮件钉钉带【疑似垃圾】、标无效、不给 ?sent=。
$_SERVER['REMOTE_ADDR'] = '192.0.2.77'; // 前面的提交已用完 192.0.2.10 的限流名额
$GLOBALS['mails'] = [];
$GLOBALS['dingtalk'] = [];
$r = submit(['name' => 'Yaron', 'email' => 'yaron@scam.test', 'message' => $spam['Yaron']]);
$id = $r->data['id'] ?? -1;
$last = end($GLOBALS['inserted']);
check($r->success && $id === 0 && !str_contains($r->data['redirect'], 'sent='), '疑似垃圾：感谢页不带编号，不计转化');
check($last['post_status'] === 'publish' && !str_starts_with($last['post_title'], '[已拦截]'), '疑似垃圾：照常存成询盘');
$saved_id = count($GLOBALS['inserted']) + 100;
check(str_starts_with((string) ($GLOBALS['meta'][$saved_id]['_invalid'] ?? ''), '自动判定疑似垃圾：群发模板话术'), '疑似垃圾：自动标为无效并写明原因');
check(count($GLOBALS['mails']) === 1 && str_starts_with($GLOBALS['mails'][0]['subject'], '【疑似垃圾】'), '疑似垃圾：邮件照发，标题带【疑似垃圾】');
check(!$GLOBALS['dingtalk'] || str_starts_with($GLOBALS['dingtalk'][0][0]['title'], '【疑似垃圾】'), '疑似垃圾：钉钉标题带【疑似垃圾】');
$GLOBALS['options'][ACHORD_INQUIRY_OPTION]['suspect_check'] = '0';
$r = submit(['name' => 'Yaron', 'email' => 'yaron@scam.test', 'message' => $spam['Yaron']]);
check($r->success && $r->data['id'] > 0, '关掉自动标记：照旧算正常询盘');
unset($GLOBALS['options'][ACHORD_INQUIRY_OPTION]['suspect_check']);

echo $failures ? "\n$failures failed\n" : "\nall passed\n";
exit($failures ? 1 : 0);
