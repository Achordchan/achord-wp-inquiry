<?php
/**
 * The sender's IP address and the country it points to.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cloudflare's published proxy ranges (https://www.cloudflare.com/ips/). CF-Connecting-IP is believed only from these:
 * anyone else could send the header to pose as another visitor and get round the rate limit.
 */
const ACHORD_INQUIRY_CLOUDFLARE_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
    '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

function achord_inquiry_ip_in_range(string $ip, string $range): bool
{
    if (!str_contains($range, '/')) {
        return false;
    }
    [$subnet, $bits] = explode('/', $range, 2);
    $ip_bin = @inet_pton($ip);
    $subnet_bin = @inet_pton($subnet);
    if ($ip_bin === false || $subnet_bin === false || strlen($ip_bin) !== strlen($subnet_bin)) {
        return false;
    }
    $bits = (int) $bits;
    $bytes = intdiv($bits, 8);
    if (strncmp($ip_bin, $subnet_bin, $bytes) !== 0) {
        return false;
    }
    $rest = $bits % 8;
    return $rest === 0 || ((ord($ip_bin[$bytes]) ^ ord($subnet_bin[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0;
}

/**
 * The sender's IP while finishing an earlier submission from WP-Cron (achord_inquiry_screen_saved()): the IP saved
 * with the inquiry, or '' when IP storage is off, stands in for the cron request's own address. null when not set.
 */
function achord_inquiry_ip_override(?string $ip = null, bool $reset = false): ?string
{
    static $override = null;
    if ($reset) {
        $override = null;
    } elseif ($ip !== null) {
        $override = $ip;
    }
    return $override;
}

/**
 * The visitor's IP address: CF-Connecting-IP when the request really came through Cloudflare, else REMOTE_ADDR (on
 * WordPress.com that is already the visitor's own address). A host behind another reverse proxy, which leaves its own
 * address in REMOTE_ADDR, resolves the visitor through the achord_inquiry_client_ip filter, which receives the peer
 * address and should read a forwarded header only from proxies it trusts.
 */
function achord_inquiry_client_ip(): string
{
    $override = achord_inquiry_ip_override();
    if ($override !== null) {
        return $override;
    }
    $peer = sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $ip = $peer;
    $cloudflare = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cloudflare !== '' && filter_var($cloudflare, FILTER_VALIDATE_IP)) {
        foreach (ACHORD_INQUIRY_CLOUDFLARE_RANGES as $range) {
            if (achord_inquiry_ip_in_range($peer, $range)) {
                $ip = $cloudflare;
                break;
            }
        }
    }
    return (string) apply_filters('achord_inquiry_client_ip', $ip, $peer);
}

/** Whether inquiries keep the sender's IP (询盘设置): off until the privacy policy says they do. */
function achord_inquiry_store_ip(): bool
{
    return (string) (achord_inquiry_options()['store_ip'] ?? '0') === '1';
}

/** The visitor's country code from a CDN header (Cloudflare, CloudFront …), or '' when none sent one. */
function achord_inquiry_country_code_from_request(): string
{
    foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_APPENGINE_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEOIP_COUNTRY_CODE', 'HTTP_GEOIP_COUNTRY_CODE'] as $key) {
        $code = strtoupper(trim((string) ($_SERVER[$key] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $code) && !in_array($code, ['XX', 'ZZ'], true)) {
            return $code;
        }
    }
    return '';
}

/** The country the sender's IP address points to, in Chinese (e.g. 加拿大); empty when it cannot be told. */
function achord_inquiry_ip_country(string $ip): string
{
    $code = achord_inquiry_country_code_from_request();
    if ($code === '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $key = 'achord_inquiry_country_' . md5($ip);
        $code = get_transient($key);
        if ($code === false) {
            $response = wp_remote_get('https://ipinfo.io/' . rawurlencode($ip) . '/country', ['timeout' => 3]);
            $text = strtoupper(trim((string) wp_remote_retrieve_body($response)));
            $code = !is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200 && preg_match('/^[A-Z]{2}$/', $text) ? $text : '';
            set_transient($key, $code, $code !== '' ? WEEK_IN_SECONDS : HOUR_IN_SECONDS);
        }
    }
    if ($code === '') {
        return '';
    }
    static $names = null;
    $names ??= require __DIR__ . '/country-names-zh.php';
    return $names[$code] ?? $code;
}
