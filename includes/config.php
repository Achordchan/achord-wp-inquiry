<?php
/**
 * What a site tells the plugin, and the plugin's own settings.
 *
 * A theme opts in with add_theme_support('achord-inquiry', [...]) in its functions.php; without it the plugin registers
 * nothing, so it can be installed before the theme that uses it is deployed. The keys it may pass are the defaults below.
 * 'prefix' names every posted field and endpoint (prefix "boblift_" keeps the crane site's form, as cached pages still
 * post it: boblift_inquiry_submit, boblift_submit_inquiry, boblift_inquiry_nonce, boblift_inquiry_website,
 * boblift_inquiry_response, boblift_lang, boblift_attr_*, boblift_page_referrer, boblift_ad_consent, boblift_touch).
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The site's settings for the plugin: theme support merged over the defaults, then the achord_inquiry_config filter. */
function achord_inquiry_config(): array
{
    $defaults = [
        // Short name of the site in the sales team's language, for the mail's headline and subject and DingTalk's title.
        'site_label' => '网站',
        'dingtalk_emoji' => '📩',
        // The source the DingTalk plugin sees, to pick robots by (its 「表单」 filter).
        'dingtalk_source' => 'achord:inquiry',
        'prefix' => 'achord_',
        'post_type' => 'inquiry',
        'rest_base' => 'inquiries',
        // Read-only REST field and meta key with every saved field of an inquiry.
        'rest_record' => 'achord_inquiry',
        // Fields the form must fill in (the standard inquiry form).
        'required' => ['email', 'message'],
        // Where the visitor goes after sending, and where a failed form without JavaScript is sent back to.
        'success_url' => '',
        'fallback_url' => '',
        // Forms whose submit fills in the hidden source fields (assets/inquiry.js); [data-achord-inquiry] always counts.
        'form_selector' => '',
        // localStorage key of the ad attribution record (kept as it was, so visitors' records survive a move to the plugin).
        'attribution_key' => 'achord_inquiry_attribution',
        // Mail logo (an absolute address); empty shows the site label as text.
        'logo_url' => '',
    ];
    $theme = function_exists('get_theme_support') ? get_theme_support('achord-inquiry') : false;
    $given = is_array($theme) && isset($theme[0]) && is_array($theme[0]) ? $theme[0] : [];
    $config = array_merge($defaults, array_intersect_key($given, $defaults));
    $config = (array) apply_filters('achord_inquiry_config', $config);
    $config['prefix'] = preg_match('/^[a-z][a-z0-9_]*_$/', (string) $config['prefix']) ? (string) $config['prefix'] : 'achord_';
    return $config;
}

/** Whether the active theme has handed its inquiries to the plugin. */
function achord_inquiry_active(): bool
{
    return function_exists('current_theme_supports') && current_theme_supports('achord-inquiry');
}

/** A posted field's or endpoint's name for this site, e.g. achord_inquiry_name('inquiry_nonce') → boblift_inquiry_nonce. */
function achord_inquiry_name(string $suffix): string
{
    return achord_inquiry_config()['prefix'] . $suffix;
}

const ACHORD_INQUIRY_OPTION = 'achord_inquiry_options';

/**
 * The settings of 设置 → 询盘设置. Until they are saved there, a theme's existing values can stand in through
 * achord_inquiry_default_options (so moving to the plugin needs nothing typed again).
 */
function achord_inquiry_options(): array
{
    $defaults = [
        'recipient' => '',
        'turnstile_enabled' => '0',
        'turnstile_local_mode' => '0',
        'turnstile_site_key' => '',
        'turnstile_secret_key' => '',
        'blocklist' => '',
        'store_ip' => '0',
        'suspect_check' => '1',
        'ai_base_url' => '',
        'ai_api_key' => '',
        'ai_model' => '',
        'ai_timeout' => '20',
    ];
    $defaults = array_merge($defaults, array_intersect_key((array) apply_filters('achord_inquiry_default_options', $defaults), $defaults));
    $saved = get_option(ACHORD_INQUIRY_OPTION, null);
    return is_array($saved) ? array_merge($defaults, array_intersect_key($saved, $defaults)) : $defaults;
}

/** Words the visitor sees, in English by default; a multilingual theme answers achord_inquiry_message with its translation. */
function achord_inquiry_message(string $key): string
{
    $defaults = [
        'invalidRequest' => 'Invalid inquiry request. Please refresh and try again.',
        'requiredFields' => 'Please enter a valid email address and project details.',
        'verificationRequired' => 'Please complete the verification and try again.',
        'tooMany' => 'Too many inquiries were sent from your network. Please wait a few minutes and try again, or email us directly.',
        'saveFailed' => 'The inquiry could not be sent. Please try again.',
        'success' => 'Inquiry sent. Our team will contact you soon.',
    ];
    return (string) apply_filters('achord_inquiry_message', $defaults[$key] ?? '', $key);
}

/**
 * The form's fields: [name => [label (mail), short (DingTalk, 2–4 characters), admin (inquiry box), type, long]].
 * type email/url/textarea/text decides how it is cleaned; long puts it on its own line in DingTalk. Sites add or relabel
 * fields with achord_inquiry_fields.
 */
function achord_inquiry_fields(): array
{
    $fields = [
        'name' => ['label' => '姓名', 'short' => '姓名', 'admin' => '姓名'],
        'email' => ['label' => '邮箱', 'short' => '邮箱', 'admin' => '邮箱', 'type' => 'email'],
        'phone' => ['label' => '电话 / WhatsApp', 'short' => '电话', 'admin' => '电话 / WhatsApp'],
        'company' => ['label' => '公司', 'short' => '公司', 'admin' => '公司'],
        'website' => ['label' => '网站', 'short' => '网站', 'admin' => '网站', 'type' => 'url'],
        'country' => ['label' => '国家（客户填写）', 'short' => '国家', 'admin' => '国家'],
        'product' => ['label' => '产品', 'short' => '产品', 'admin' => '意向产品', 'mail' => false],
        'product_slug' => ['label' => '产品标识', 'short' => '', 'admin' => '产品标识', 'mail' => false],
        'quantity' => ['label' => '数量', 'short' => '数量', 'admin' => '数量'],
        'message' => ['label' => '留言', 'short' => '留言', 'admin' => '项目详情', 'type' => 'textarea', 'long' => true, 'mail' => false],
    ];
    $fields = (array) apply_filters('achord_inquiry_fields', $fields);
    foreach ($fields as $name => $field) {
        $fields[$name] = array_merge(['label' => $name, 'short' => '', 'admin' => '', 'type' => 'textarea', 'long' => false, 'mail' => true], is_array($field) ? $field : []);
        $fields[$name]['admin'] = $fields[$name]['admin'] !== '' ? $fields[$name]['admin'] : $fields[$name]['label'];
    }
    return $fields;
}

/**
 * Kinds of lead besides the standard inquiry (lead_type), e.g. a PDF catalog request: the words its mail and DingTalk
 * message use. The theme that sends them registers them with achord_inquiry_lead_types.
 */
function achord_inquiry_lead_type(array $fields): array
{
    $label = (string) achord_inquiry_config()['site_label'];
    $types = (array) apply_filters('achord_inquiry_lead_types', []);
    $type = (string) ($fields['lead_type'] ?? '');
    $words = isset($types[$type]) && is_array($types[$type]) ? $types[$type] : [];
    return array_merge([
        'type' => $type,
        'headline' => '新询盘通知',
        'intro' => $label . '收到一条新询盘，内容如下，请及时跟进。',
        'subject' => '【' . $label . '】询盘飞奔而来，请及时跟进：',
        'kind' => '',
        'section' => '产品信息',
        'form_name' => '询盘表单',
        'dingtalk_title' => '',
        'show_message' => true,
        'title' => 'Inquiry from %s - %s',
    ], $words);
}
