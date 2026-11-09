<?php
/**
 * Keeping spam out: Cloudflare Turnstile, a per-IP rate limit and the blocklist (询盘设置).
 *
 * Blocklist: email addresses, whole email domains (@example.com) and IP addresses or ranges (203.0.113.7,
 * 203.0.113.0/24), one per line or comma separated. A matching inquiry is still saved, as a draft marked 已拦截 with the
 * reason, so nothing is lost; it sends no email, no DingTalk message, and gets no ?sent= thank-you page, so it is never
 * reported as an ad conversion. The sender sees the usual success message and learns nothing.
 */

if (!defined('ABSPATH')) {
    exit;
}

function achord_inquiry_turnstile_config(): array
{
    $options = achord_inquiry_options();
    $local = (string) $options['turnstile_local_mode'] === '1';
    return [
        'enabled' => (string) $options['turnstile_enabled'] === '1',
        // Cloudflare's always-passing test keys, for a local copy of the site.
        'site_key' => trim($local ? '1x00000000000000000000AA' : (string) $options['turnstile_site_key']),
        'secret_key' => trim($local ? '1x0000000000000000000000000000000AA' : (string) $options['turnstile_secret_key']),
    ];
}

function achord_inquiry_turnstile_enabled(): bool
{
    $config = achord_inquiry_turnstile_config();
    return $config['enabled'] && $config['site_key'] !== '' && $config['secret_key'] !== '';
}

/**
 * The Turnstile box for a form. $lazy prints a placeholder (data-turnstile-widget) for a theme that renders it only when
 * its dialog opens; otherwise Cloudflare's script is enqueued and renders it on load.
 */
function achord_inquiry_turnstile_widget(string $class = '', bool $lazy = false): string
{
    if (!achord_inquiry_turnstile_enabled()) {
        return '';
    }
    $key = esc_attr(achord_inquiry_turnstile_config()['site_key']);
    if (!$lazy) {
        wp_enqueue_script('achord-inquiry-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', [], null, ['strategy' => 'defer', 'in_footer' => true]);
    }
    $inner = $lazy ? '<div data-turnstile-widget data-sitekey="' . $key . '" data-theme="light"></div>' : '<div class="cf-turnstile" data-sitekey="' . $key . '" data-theme="light"></div>';
    return '<div class="achord-inquiry-turnstile ' . esc_attr($class) . '">' . $inner . '</div>';
}

function achord_inquiry_verify_turnstile(): bool
{
    if (!achord_inquiry_turnstile_enabled()) {
        return true;
    }
    $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field((string) wp_unslash($_POST['cf-turnstile-response'])) : '';
    if ($token === '') {
        return false;
    }
    $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'timeout' => 8,
        'body' => ['secret' => achord_inquiry_turnstile_config()['secret_key'], 'response' => $token, 'remoteip' => achord_inquiry_client_ip()],
    ]);
    if (is_wp_error($response)) {
        return false;
    }
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    return is_array($body) && !empty($body['success']);
}

/** How many inquiries one IP address may send in one fixed 10-minute window before the form asks it to wait. */
const ACHORD_INQUIRY_RATE_LIMIT = 5;
const ACHORD_INQUIRY_RATE_WINDOW = 600;

/**
 * Take one of this IP's inquiry slots in the current window; false when they are all taken. The count lives in its own
 * options row per IP and window, raised by a single conditional UPDATE, so two requests can never both take the last
 * slot (transients are a read then a write). Rows of finished windows are removed as new ones are made.
 */
function achord_inquiry_reserve_slot(): bool
{
    global $wpdb;
    $window = intdiv(time(), ACHORD_INQUIRY_RATE_WINDOW);
    $name = '_achord_inquiry_rate_' . md5(achord_inquiry_client_ip()) . '_' . $window;
    $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'no')", $name));
    $taken = (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s AND option_value + 0 < %d", $name, ACHORD_INQUIRY_RATE_LIMIT));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND SUBSTRING_INDEX(option_name, '_', -1) + 0 < %d", $wpdb->esc_like('_achord_inquiry_rate_') . '%', $window - 1));
    return $taken === 1;
}

/** The blocklist entries as typed, lower-cased, without blanks or duplicates. */
function achord_inquiry_blocklist(): array
{
    $entries = preg_split('/[\s,;]+/', strtolower((string) achord_inquiry_options()['blocklist'])) ?: [];
    return array_values(array_unique(array_filter(array_map('trim', $entries), 'strlen')));
}

/** One mailbox as Gmail delivers it: Gmail ignores dots and a +tag, so k.habib+x@gmail.com is khabib@gmail.com. */
function achord_inquiry_mailbox(string $email): string
{
    $email = strtolower(trim($email));
    $at = strrpos($email, '@');
    if ($at === false || $at === 0) {
        return $email;
    }
    $local = substr($email, 0, $at);
    $domain = substr($email, $at + 1);
    if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
        $local = str_replace('.', '', explode('+', $local, 2)[0]);
        $domain = 'gmail.com';
    }
    return $local . '@' . $domain;
}

/** An address range as typed (203.0.113.0/24, 2001:db8::/32); a mistyped one is ignored rather than read as /0, which matches everyone. */
function achord_inquiry_valid_range(string $range): bool
{
    $parts = explode('/', $range);
    if (count($parts) !== 2 || !filter_var($parts[0], FILTER_VALIDATE_IP) || !ctype_digit($parts[1])) {
        return false;
    }
    $bits = (int) $parts[1];
    return $bits >= 1 && $bits <= (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32 : 128);
}

/** Why this sender is blocked (e.g. 邮箱 spam@example.com), or '' when nothing on the blocklist matches. */
function achord_inquiry_block_reason(string $email, string $ip): string
{
    $email = achord_inquiry_mailbox($email);
    $domain = str_contains($email, '@') ? substr($email, (int) strrpos($email, '@')) : '';
    foreach (achord_inquiry_blocklist() as $entry) {
        if (str_contains($entry, '@')) {
            // A whole domain is compared the way mailboxes are: @googlemail.com is the same as @gmail.com.
            $entry_domain = str_starts_with($entry, '@') ? substr(achord_inquiry_mailbox('x' . $entry), 1) : '';
            if ($email !== '' && ($entry_domain !== '' ? $entry_domain === $domain : achord_inquiry_mailbox($entry) === $email)) {
                return '邮箱 ' . $entry;
            }
            continue;
        }
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            continue;
        }
        if (str_contains($entry, '/') ? achord_inquiry_valid_range($entry) && achord_inquiry_ip_in_range($ip, $entry) : (filter_var($entry, FILTER_VALIDATE_IP) && inet_pton($entry) === inet_pton($ip))) {
            return 'IP ' . $entry;
        }
    }
    return '';
}

/** The reason as saved: an IP entry can be the sender's own address, so without IP storage it names only the kind of match. */
function achord_inquiry_saved_block_reason(string $reason): string
{
    return !achord_inquiry_store_ip() && str_starts_with($reason, 'IP ') ? 'IP 黑名单' : $reason;
}

/*
 * Suspected spam: the people who get past Turnstile and the blocklist are humans typing one of a few mass templates
 * ("please send your catalogue and price list", "are you the right person", "when can we meet on Zoom/Teams"), each time
 * from a new address, often through a VPN or a rented server. Such an inquiry is still saved and still sent to sales,
 * with 【疑似垃圾】 in front of the mail subject and the DingTalk title, but it is marked invalid with the reason and gets
 * no ?sent= thank-you page, so it is not reported to GA4 or Google Ads. 恢复有效 in the list clears the mark.
 */

/** Phrases of the mass templates; two different ones in a message that names nothing about a product make it suspect. */
const ACHORD_INQUIRY_SCAM_PHRASES = [
    '/\bprice\s*-?lists?\b/i',
    '/\bcatalog(ue)?s?\b/i',
    '/\bMOQ\b/',
    '/\bright person\b/i',
    '/\b(appropriate|relevant|right) (sales|person|department|representative)/i',
    '/\b(zoom|ms teams|microsoft teams|teams|google meet|skype|virtual meeting|online meeting|video call)\b/i',
    '/\b(place|placing) (an |our |a |some )?(orders?|purchase)\b/i',
    '/\bbusiness (partnership|cooperation|relationship)\b/i',
    '/\b(purchas(e|ing)|procurement|sourcing) (manager|officer|director|department)\b/i',
    '/\bstrong interest\b/i',
    '/\bcompany profile\b/i',
    '/\bfor our (perusal|view|review) and selection/i',
];

/** Words a buyer of lifting equipment uses about the machine itself (any of them clears the template check). */
const ACHORD_INQUIRY_PRODUCT_WORDS = '/(\bcranes?\b|\btons?\b|\btonnes?\b|\d\s*t\b|\bkg\b|\bbooms?\b|\bwinch|\bjib\b|\bknuckle|\btelescopic|\bmarine\b|\bforklift|\boutriggers?\b|\bchassis\b|\breach\b|\bheight\b|\bmet(er|re)s?\b|\d\s*m\b|\bcapacity\b|\bloader\b|\bdumper|\bskid\b|\bsq\s?\d)/i';

/** Network owners whose addresses are servers or VPN exits, not homes, offices or phones (matched in ipinfo's org). */
const ACHORD_INQUIRY_HOSTING_ORGS = [
    'hosting', 'hosted', 'datacenter', 'data center', 'datacamp', 'datapacket', 'worldstream', 'm247', 'vpn', 'digitalocean',
    'ovh', 'hetzner', 'linode', 'akamai', 'vultr', 'choopa', 'contabo', 'leaseweb', 'amazon', 'google llc', 'microsoft',
    'oracle', 'alibaba', 'tencent', 'private layer', 'packethub', 'clouvider', 'tzulo', 'cdn77', 'hostinger', 'ionos',
    'colocrossing', 'quadranet', 'psychz', 'zenlayer', 'g-core', 'gcore', 'servers', 'cloudflare', 'performive',
    'hostroyale', 'hydra communications', 'kamatera', 'scaleway', 'upcloud', 'ipxo', 'stark industries',
];

/** Whether the 自动标记疑似垃圾 setting is on (it is unless unticked in 询盘设置). */
function achord_inquiry_suspect_check_enabled(): bool
{
    return (string) (achord_inquiry_options()['suspect_check'] ?? '1') === '1';
}

/** The distinct template phrases found in a message, as matched (e.g. ['price list', 'catalogue']). */
function achord_inquiry_scam_phrases(string $message): array
{
    $found = [];
    foreach (ACHORD_INQUIRY_SCAM_PHRASES as $pattern) {
        if (preg_match($pattern, $message, $match)) {
            $found[] = strtolower($match[0]);
        }
    }
    return array_values(array_unique($found));
}

/** Whether the message says anything about the machine (a capacity, a boom, a crane …). */
function achord_inquiry_mentions_product(string $message): bool
{
    return (bool) preg_match(ACHORD_INQUIRY_PRODUCT_WORDS, $message);
}

/** Who owns the IP's network per ipinfo.io (e.g. AS49981 WorldStream B.V.), cached for a week; '' when unknown. */
function achord_inquiry_ip_org(string $ip): string
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return '';
    }
    $key = 'achord_inquiry_org_' . md5($ip);
    $org = get_transient($key);
    if ($org === false) {
        $response = wp_remote_get('https://ipinfo.io/' . rawurlencode($ip) . '/org', ['timeout' => 3]);
        $text = trim((string) wp_remote_retrieve_body($response));
        $org = !is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200 && $text !== '' && strlen($text) < 200 && !str_starts_with($text, '{') ? $text : '';
        set_transient($key, $org, $org !== '' ? WEEK_IN_SECONDS : HOUR_IN_SECONDS);
    }
    return (string) $org;
}

/** The network owner when it is a server or VPN provider, else ''. */
function achord_inquiry_hosting_org(string $ip): string
{
    $org = achord_inquiry_ip_org($ip);
    $lower = strtolower($org);
    foreach (ACHORD_INQUIRY_HOSTING_ORGS as $word) {
        if ($lower !== '' && str_contains($lower, $word)) {
            return $org;
        }
    }
    return '';
}

/**
 * Why an inquiry looks like spam, or '' when it does not: two or more template phrases in a message that says nothing
 * about the machine, or a server/VPN address with such a message.
 */
function achord_inquiry_suspect_reason(array $fields, string $ip): string
{
    $message = (string) ($fields['message'] ?? '');
    if (achord_inquiry_mentions_product($message)) {
        return '';
    }
    $reasons = [];
    $phrases = achord_inquiry_scam_phrases($message);
    if (count($phrases) >= 2) {
        $reasons[] = '群发模板话术（' . implode('、', array_slice($phrases, 0, 4)) . '）';
    }
    $hosting = achord_inquiry_hosting_org($ip);
    if ($hosting !== '') {
        $reasons[] = '机房/VPN 网络（' . $hosting . '）';
    }
    return implode('；', $reasons);
}
