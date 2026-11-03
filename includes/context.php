<?php
/**
 * Where an inquiry came from: the page and button, the ad or site that brought the visitor, and the device.
 * Ported from the crane site (bobliftcrane inc/inquiry-mail.php and inc/forms.php), which the loader site copied.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which ad brought the inquiry (sent by assets/inquiry.js in hidden {prefix}attr_* fields, only when the visitor agreed to
 * advertising cookies), with the longest value each may have. Saved with the inquiry as attr_<name> and shown in the mail.
 */
const ACHORD_INQUIRY_ATTRIBUTION = [
    'gclid' => 255, 'gbraid' => 255, 'wbraid' => 255, 'gad_source' => 120, 'gad_campaignid' => 120,
    'utm_source' => 120, 'utm_medium' => 120, 'utm_campaign' => 120, 'utm_term' => 120, 'utm_content' => 120,
    'landing' => 300, 'first_seen' => 40, 'referrer' => 120,
];

/** Chinese labels of the attribution fields, for the mail and the admin inquiry box. */
const ACHORD_INQUIRY_ATTRIBUTION_LABELS = [
    'gclid' => 'gclid', 'gbraid' => 'gbraid', 'wbraid' => 'wbraid', 'gad_source' => 'gad_source', 'gad_campaignid' => '广告系列编号',
    'utm_source' => 'UTM 来源', 'utm_medium' => 'UTM 媒介', 'utm_campaign' => 'UTM 系列', 'utm_term' => 'UTM 关键词', 'utm_content' => 'UTM 内容',
    'landing' => '落地页', 'first_seen' => '落地时间', 'referrer' => '进站前网站',
];

/** One posted value as stored: one printable line, no markup, within its length; a click ID that is cut matches nothing, so it is dropped. */
function achord_inquiry_attribution_clean(string $name, $value): string
{
    if (!isset(ACHORD_INQUIRY_ATTRIBUTION[$name]) || !is_scalar($value)) {
        return '';
    }
    $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1f\x7f<>]/u', ' ', (string) $value)));
    if (in_array($name, ['gclid', 'gbraid', 'wbraid'], true)) {
        return strlen($text) <= ACHORD_INQUIRY_ATTRIBUTION[$name] && preg_match('/^[A-Za-z0-9_.~=-]+$/', $text) ? $text : '';
    }
    if ($name === 'referrer') {
        return achord_inquiry_referrer_host($text);
    }
    return mb_substr($text, 0, ACHORD_INQUIRY_ATTRIBUTION[$name]);
}

/** A referring site as kept: its host name only (no path, no query), lower case, without www.; empty when it is not one. */
function achord_inquiry_referrer_host(string $value): string
{
    $host = strtolower(trim($value));
    $host = (string) preg_replace('/^www\./', '', $host);
    return strlen($host) <= 120 && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $host) ? $host : '';
}

/**
 * The page an inquiry was sent from, split for reading: the address without its ad tags, the page's title (sent by the
 * form, without the brand) and the ad tags (gclid, utm_* …) on their own.
 */
function achord_inquiry_source_page(array $fields): array
{
    $url = trim((string) ($fields['source_page'] ?? ''));
    $parts = $url !== '' ? wp_parse_url($url) : [];
    $parts = is_array($parts) ? $parts : [];
    // A relative referer (/contact.html?gclid=…) is read against this site's address.
    if ($url !== '' && empty($parts['host'])) {
        $home = wp_parse_url(home_url('/'));
        $parts = array_merge(is_array($home) ? array_intersect_key($home, array_flip(['scheme', 'host', 'port'])) : [], $parts);
    }
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    // Ad and campaign tags are listed on their own; anything else in the query (?page_id=, ?s=, ?product= …) picks the
    // page, so it stays in the address.
    $tracking = [];
    foreach ($query as $name => $value) {
        if (is_scalar($value) && preg_match('/^(?:utm_\w+|gclid|gbraid|wbraid|gad_\w+|fbclid|msclkid|ttclid|li_fat_id|_gl|yclid|dclid)$/i', (string) $name)) {
            $tracking[(string) $name] = (string) $value;
            unset($query[$name]);
        }
    }
    $path = (string) ($parts['path'] ?? '/');
    $clean = '';
    if (!empty($parts['host'])) {
        $clean = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path . ($query ? '?' . http_build_query($query) : '');
    }
    $title = trim((string) ($fields['source_title'] ?? ''));
    // The brand at either end of a title ("Product | BOB-LIFT", "BOB-LIFT | Home") says nothing here.
    $brand = trim((string) apply_filters('achord_inquiry_title_brand', 'BOB-LIFT'));
    if ($brand !== '') {
        $quoted = preg_quote($brand, '/');
        $title = trim((string) preg_replace(['/\s*[|｜–—-]\s*' . $quoted . '\s*$/u', '/^' . $quoted . '\s*[|｜–—-]\s*/u'], '', $title));
    }
    // The home page in any language (/, /zh/ …), with nothing in the query that picks another page, is named as such.
    if ($clean !== '' && !$query && preg_match('#^/(?:[a-z]{2}/?)?$#', $path)) {
        $title = '首页' . ($title !== '' ? ' · ' . $title : '');
    }
    return ['url' => $clean, 'title' => $title, 'params' => array_filter($tracking, 'strlen')];
}

/**
 * How the visitor reached the site, for the sales team: [channel, detail]. A Google Ads click ID (kept since landing, or on
 * the page the form was sent from) wins, then campaign tags, then the site the visitor came from (kept since landing when
 * advertising was agreed to, else only the referrer of the page the form was sent from, which needs no storage).
 */
function achord_inquiry_channel(array $fields): array
{
    $source = achord_inquiry_source_page($fields);
    $value = static fn(string $name): string => (string) ($fields['attr_' . $name] ?? $source['params'][$name] ?? '');
    if ($value('gclid') !== '' || $value('gbraid') !== '' || $value('wbraid') !== '' || $value('gad_source') !== '') {
        $detail = $value('utm_term') !== '' ? '关键词 ' . $value('utm_term') : ($value('gad_campaignid') !== '' ? '广告系列 ' . $value('gad_campaignid') : '');
        return ['Google Ads 广告', $detail];
    }
    // Any campaign tag marks a marketing link; the detail is whichever of source, medium and campaign it carries.
    $tags = array_filter(array_map($value, ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content']), 'strlen');
    if ($tags) {
        return ['营销链接', implode(' / ', array_slice(array_values($tags), 0, 3))];
    }
    $host = (string) (($fields['attr_referrer'] ?? '') !== '' ? $fields['attr_referrer'] : ($fields['page_referrer'] ?? ''));
    if ($host !== '') {
        $named = static function (array $list) use ($host): string {
            foreach ($list as $pattern => $name) {
                if (preg_match('/(?:^|\.)' . $pattern . '$/', $host)) {
                    return $name;
                }
            }
            return '';
        };
        // AI assistants first: gemini.google.com is not Google Search.
        $ai = $named(['chatgpt\.com' => 'ChatGPT', 'openai\.com' => 'ChatGPT', 'perplexity\.ai' => 'Perplexity', 'gemini\.google\.com' => 'Gemini', 'copilot\.microsoft\.com' => 'Copilot', 'claude\.ai' => 'Claude', 'deepseek\.com' => 'DeepSeek']);
        if ($ai !== '') {
            return ['AI 助手推荐', $ai];
        }
        $ads = $named(['googleadservices\.com' => 'Google Ads', 'doubleclick\.net' => 'Google Ads', 'syndicatedsearch\.goog' => 'Google 搜索网络合作伙伴']);
        if ($ads !== '') {
            return ['Google Ads 广告', $ads . '（没有点击编号）'];
        }
        // Search engines by their search hosts only: docs.google.com or mail.yahoo.com is a link, not a search.
        $search = '';
        foreach (['/^(?:m\.)?google\.(?:com?\.)?[a-z]{2,3}$/' => 'Google', '/^(?:cn\.)?bing\.com$/' => 'Bing', '/^(?:[a-z]{2}\.)?search\.yahoo\.(?:com|co\.jp)$/' => 'Yahoo', '/^yandex\.(?:com|ru|com\.tr|by|kz|uz)$/' => 'Yandex', '/^ya\.ru$/' => 'Yandex', '/^(?:m\.)?baidu\.com$/' => '百度', '/^(?:html\.)?duckduckgo\.com$/' => 'DuckDuckGo', '/^ecosia\.org$/' => 'Ecosia', '/^(?:m\.)?search\.naver\.com$/' => 'Naver', '/^(?:search\.)?seznam\.cz$/' => 'Seznam', '/^(?:m\.)?sogou\.com$/' => '搜狗', '/^(?:m\.)?so\.com$/' => '360 搜索', '/^search\.brave\.com$/' => 'Brave'] as $pattern => $name) {
            if (preg_match($pattern, $host)) {
                $search = $name;
                break;
            }
        }
        if ($search !== '') {
            return ['自然搜索', $search];
        }
        $social = $named(['facebook\.com' => 'Facebook', 'fb\.com' => 'Facebook', 'instagram\.com' => 'Instagram', 'linkedin\.com' => 'LinkedIn', 'lnkd\.in' => 'LinkedIn', 'youtube\.com' => 'YouTube', 'youtu\.be' => 'YouTube', 'x\.com' => 'X', 't\.co' => 'X', 'twitter\.com' => 'X', 'whatsapp\.com' => 'WhatsApp', 'tiktok\.com' => 'TikTok', 'vk\.com' => 'VK', 'pinterest\.[a-z.]+' => 'Pinterest', 'reddit\.com' => 'Reddit', 't\.me' => 'Telegram']);
        if ($social !== '') {
            return ['社交媒体', $social];
        }
        return ['外部网站', $host];
    }
    if (($fields['ad_consent'] ?? '') === 'false') {
        return ['来源未知', '访客没同意广告 Cookie，进站来源没有记录'];
    }
    return ['直接访问', '直接输入网址或收藏夹，也可能是来源被浏览器隐藏'];
}

/**
 * The visitor's device from the browser's User-Agent, e.g. "手机 · iPhone · Safari": what kind, which system, which browser
 * or app. iPadOS Safari calls itself a Mac; the form says whether the screen takes touch, which tells the two apart.
 */
function achord_inquiry_device(string $agent, bool $touch = false): string
{
    if ($agent === '') {
        return '';
    }
    $has = static fn(string $pattern): bool => (bool) preg_match('/' . $pattern . '/i', $agent);
    $ipad = $has('iPad') || ($has('Macintosh') && $touch);
    $system = match (true) {
        $ipad => 'iPad',
        $has('iPhone|iPod') => 'iPhone',
        $has('HarmonyOS|OpenHarmony') => '鸿蒙',
        $has('Android') => 'Android',
        $has('Windows') => 'Windows',
        $has('Macintosh|Mac OS X') => 'Mac',
        $has('CrOS') => 'ChromeOS',
        $has('Linux') => 'Linux',
        default => '',
    };
    $kind = match (true) {
        $ipad, $has('Tablet') || ($has('Android') && !$has('Mobile')) => '平板',
        $has('Mobi|iPhone|iPod|Android|HarmonyOS') => '手机',
        $has('bot|crawl|spider') => '程序',
        default => '电脑',
    };
    $browser = match (true) {
        $has('MicroMessenger') => '微信',
        $has('WhatsApp') => 'WhatsApp',
        $has('FBAN|FBAV|FB_IAB') => 'Facebook 内置浏览器',
        $has('Instagram') => 'Instagram 内置浏览器',
        $has('LinkedInApp') => 'LinkedIn 内置浏览器',
        $has('Edg(?:e|A|iOS)?\/') => 'Edge',
        $has('SamsungBrowser') => '三星浏览器',
        $has('OPR\/|Opera') => 'Opera',
        $has('YaBrowser') => 'Yandex 浏览器',
        $has('Firefox|FxiOS') => 'Firefox',
        $has('Chrome|CriOS') => 'Chrome',
        $has('Safari') => 'Safari',
        default => '',
    };
    return implode(' · ', array_filter([$kind, $system, $browser], 'strlen'));
}

/**
 * The attribution fields of the current POST, without the prefix. A theme that offers no advertising consent (no Google
 * Ads ID set) refuses them through achord_inquiry_accept_attribution, even from a page cached earlier.
 */
function achord_inquiry_attribution_from_post(): array
{
    if (!apply_filters('achord_inquiry_accept_attribution', true)) {
        return [];
    }
    $found = [];
    foreach (array_keys(ACHORD_INQUIRY_ATTRIBUTION) as $name) {
        $value = achord_inquiry_attribution_clean($name, wp_unslash($_POST[achord_inquiry_name('attr_' . $name)] ?? ''));
        if ($value !== '') {
            $found[$name] = $value;
        }
    }
    return $found;
}

/** The value of a posted field as one plain line, within $length characters. */
function achord_inquiry_posted_line(string $name, int $length): string
{
    $value = $_POST[$name] ?? '';
    return is_scalar($value) ? mb_substr(sanitize_text_field(wp_unslash((string) $value)), 0, $length) : '';
}

/**
 * Everything the request tells about where the inquiry came from, as saved with it: the button (source_entry), the page
 * and its title, the ad click IDs and tags kept since landing (attr_*), the site that linked to the page, whether
 * advertising was agreed to, the device, the channel those add up to, and the sender's IP when 询盘设置 keeps it.
 */
function achord_inquiry_collect_context(): array
{
    $fields = [];
    $fields['source_entry'] = achord_inquiry_posted_line('source_entry', 120);
    $fields['source_page'] = esc_url_raw((string) wp_get_referer());
    $fields['source_title'] = achord_inquiry_posted_line('source_title', 200);
    foreach (achord_inquiry_attribution_from_post() as $name => $value) {
        $fields['attr_' . $name] = $value;
    }
    // Sent whatever the cookie choice, as nothing is stored for them in the browser.
    $fields['page_referrer'] = achord_inquiry_referrer_host((string) wp_unslash($_POST[achord_inquiry_name('page_referrer')] ?? ''));
    if ($fields['page_referrer'] === preg_replace('/^www\./', '', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)))) {
        $fields['page_referrer'] = '';
    }
    $consent = (string) wp_unslash($_POST[achord_inquiry_name('ad_consent')] ?? '');
    $fields['ad_consent'] = in_array($consent, ['true', 'false'], true) ? $consent : '';
    $agent = mb_substr(sanitize_text_field(wp_unslash((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))), 0, 512);
    $fields['device'] = achord_inquiry_device($agent, ($_POST[achord_inquiry_name('touch')] ?? '') === '1');
    $fields['channel'] = implode(' · ', array_filter(achord_inquiry_channel($fields), 'strlen'));
    $fields['ip'] = achord_inquiry_store_ip() ? achord_inquiry_client_ip() : '';
    $fields['language'] = sanitize_key((string) wp_unslash($_POST[achord_inquiry_name('lang')] ?? ''));
    return $fields;
}
