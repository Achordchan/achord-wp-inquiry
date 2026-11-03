<?php
/**
 * Telling the sales team: the classic card email (the crane site's design, which the loader site shares) and the DingTalk
 * message through the achord-wp-dingtalk plugin. Both run for every saved inquiry that is not blocked.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Where notifications go: 询盘设置's address; only when none is saved, what the theme gives (achord_inquiry_recipient);
 * else admin_email.
 */
function achord_inquiry_recipient(): string
{
    $email = sanitize_email((string) achord_inquiry_options()['recipient']);
    if (is_email($email)) {
        return $email;
    }
    $email = sanitize_email((string) apply_filters('achord_inquiry_recipient', ''));
    return is_email($email) ? $email : (string) get_option('admin_email');
}

/** Who the email comes from: the site title, at the site's own domain so receiving servers accept it. */
function achord_inquiry_mail_from_header(): string
{
    $host = preg_replace('/^www\./i', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
    $name = trim(wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES));
    $name = (string) apply_filters('achord_inquiry_mail_from_name', $name !== '' ? $name : (string) $host);
    return 'From: ' . str_replace(['"', "\r", "\n"], '', $name) . ' <wordpress@' . $host . '>';
}

/** The logo at the top of the card, from an absolute address (mail programs have no page to resolve a relative one against). */
function achord_inquiry_mail_logo(): string
{
    $src = (string) achord_inquiry_config()['logo_url'];
    if (str_starts_with($src, '//')) {
        $src = 'https:' . $src;
    } elseif (str_starts_with($src, '/')) {
        $src = untrailingslashit(home_url()) . $src;
    }
    if (!preg_match('#^https?://#i', $src)) {
        return '<div style="font-size:18px;font-weight:bold;color:#0b1f33;">' . esc_html(trim(wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES))) . '</div>';
    }
    return '<img src="' . esc_url($src) . '" width="120" alt="' . esc_attr((string) get_bloginfo('name')) . '" style="display:block;width:120px;height:auto;border:0;">';
}

/**
 * The block under 产品信息: what the theme renders for the inquiry's products (achord_inquiry_mail_products_html, e.g.
 * cards with photo and model), else the product as typed, else 未选择产品. Returns [html, kind] where kind is the badge
 * beside 来源页面 (产品询盘 or 询盘表单).
 */
function achord_inquiry_mail_products(array $fields): array
{
    $html = (string) apply_filters('achord_inquiry_mail_products_html', '', $fields);
    $product = trim((string) ($fields['product'] ?? ''));
    if ($html === '' && $product !== '') {
        $html = '<table width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:10px;border:1px solid #e3e8ef;border-radius:4px;border-collapse:separate;"><tr><td style="padding:16px 18px;font-size:17px;line-height:1.45;font-weight:bold;color:#0b1f33;">' . nl2br(esc_html($product), false) . '</td></tr></table>';
    }
    if ($html === '') {
        return ['<div style="padding:14px 18px;border:1px solid #e3e8ef;border-radius:4px;font-size:15px;color:#64748b;">未选择产品</div>', '询盘表单'];
    }
    return [$html, '产品询盘'];
}

/**
 * The ad tracking values at the foot of the mail, for whoever checks the ads: the click IDs and tags kept since the visitor
 * landed, then any the page the form was sent from carried that differ from them (a second ad click shows up there). The
 * sales team reads the channel at 来源渠道; this block is only the raw values behind it. Empty when there are none.
 */
function achord_inquiry_attribution_block(array $fields): string
{
    $line = static fn(string $label, string $value): string => '<div style="padding:2px 0;font-size:12px;line-height:1.6;color:#64748b;word-break:break-all;">' . esc_html($label) . ' <span style="color:#334155;">' . esc_html($value) . '</span></div>';
    $rows = '';
    foreach (ACHORD_INQUIRY_ATTRIBUTION_LABELS as $name => $label) {
        $value = (string) ($fields['attr_' . $name] ?? '');
        if ($value !== '') {
            $rows .= $line($label, $value);
        }
    }
    $clicks = 0;
    foreach (achord_inquiry_source_page($fields)['params'] as $name => $value) {
        $kept = (string) ($fields['attr_' . $name] ?? '');
        if ($kept === $value) {
            continue;
        }
        $rows .= $line('提交页 ' . (ACHORD_INQUIRY_ATTRIBUTION_LABELS[$name] ?? $name), $value);
        $clicks += $kept !== '' && in_array($name, ['gclid', 'gbraid', 'wbraid'], true) ? 1 : 0;
    }
    if ($rows === '') {
        return '';
    }
    // The kept record is the latest click: an older tab never overwrites it, so the form's page can hold an earlier one.
    $note = $clicks ? '<div style="padding:2px 0 4px;font-size:12px;line-height:1.6;color:#64748b;">访客点过不止一次广告：上面是最近一次点击，「提交页」是填表那个页面打开时的点击。</div>' : '';
    return '<tr><td style="padding:8px 36px 28px;"><table width="100%" cellspacing="0" cellpadding="0" style="border-top:1px dashed #e3e8ef;"><tr><td style="padding:14px 0 0;"><div style="padding-bottom:6px;font-size:12px;font-weight:bold;color:#94a3b8;">广告追踪参数（看广告效果用，跟进客户可以不看）</div>' . $note . $rows . '</td></tr></table></td></tr>';
}

/** The language of the page the form was sent from, in Chinese. */
function achord_inquiry_language_name(string $code): string
{
    $names = (array) apply_filters('achord_inquiry_language_names', ['en' => '英语', 'zh' => '中文', 'ja' => '日语', 'es' => '西班牙语', 'ru' => '俄语', 'fr' => '法语', 'ar' => '阿拉伯语', 'de' => '德语', 'pt' => '葡萄牙语', 'it' => '意大利语', 'ko' => '韩语']);
    return (string) ($names[$code] ?? ($code !== '' ? $code : '英语'));
}

/** The notification the sales team receives, as [subject, html body]. */
function achord_inquiry_admin_mail(array $fields, int $post_id): array
{
    $type = achord_inquiry_lead_type($fields);
    $font = "font-family:-apple-system,BlinkMacSystemFont,'PingFang SC','Microsoft YaHei','Helvetica Neue',Arial,sans-serif;";
    $heading = static fn(string $text): string => '<tr><td style="padding:28px 36px 12px;font-size:13px;font-weight:bold;letter-spacing:1px;color:#64748b;">' . $text . '</td></tr>';
    $row = static fn(string $label, string $value): string => '<tr><td width="130" style="width:130px;padding:13px 12px 13px 0;border-bottom:1px solid #edf0f4;font-size:14px;color:#64748b;vertical-align:top;">' . $label . '</td><td style="padding:13px 0;border-bottom:1px solid #edf0f4;font-size:16px;color:#0b1f33;line-height:1.6;vertical-align:top;word-break:break-word;">' . $value . '</td></tr>';
    $meta = static fn(string $label, string $value): string => '<td width="33%" style="width:33%;padding:16px 18px;vertical-align:top;"><div style="font-size:12px;color:#64748b;padding-bottom:4px;">' . $label . '</div><div style="font-size:15px;font-weight:bold;color:#0b1f33;line-height:1.4;word-break:break-word;">' . $value . '</div></td>';

    $email = (string) ($fields['email'] ?? '');
    $rows = [];
    foreach (achord_inquiry_fields() as $name => $field) {
        if (!$field['mail']) {
            continue;
        }
        if ($name === 'email') {
            $rows[] = $row(esc_html($field['label']), '<a href="mailto:' . esc_attr($email) . '" style="color:#215bdb;text-decoration:none;">' . esc_html($email) . '</a>');
            continue;
        }
        $value = trim((string) ($fields[$name] ?? ''));
        if ($value === '') {
            continue;
        }
        $html = $field['type'] === 'url'
            ? '<a href="' . esc_url(preg_match('#^https?://#i', $value) ? $value : 'https://' . $value) . '" style="color:#215bdb;text-decoration:none;">' . esc_html($value) . '</a>'
            : nl2br(esc_html($value), false);
        $rows[] = $row(esc_html($field['label']), $html);
    }
    // Rows a theme adds after the form's fields (the crane site's discontinued models), as [label, html].
    foreach ((array) apply_filters('achord_inquiry_mail_rows', [], $fields) as $extra) {
        if (is_array($extra) && count($extra) === 2) {
            $rows[] = $row(esc_html((string) $extra[0]), (string) $extra[1]);
        }
    }

    [$product_html, $kind] = achord_inquiry_mail_products($fields);
    if ($type['kind'] !== '') {
        $kind = $type['kind'];
    }
    $entry = trim((string) ($fields['source_entry'] ?? ''));
    $source = achord_inquiry_source_page($fields);
    $page_html = '—';
    if ($source['url'] !== '') {
        $page_html = ($source['title'] !== '' ? '<div style="font-size:16px;font-weight:bold;color:#0b1f33;padding-bottom:2px;">' . esc_html($source['title']) . '</div>' : '')
            . '<a href="' . esc_url($source['url']) . '" target="_blank" style="color:#215bdb;text-decoration:none;">' . esc_html($source['url']) . '</a>';
    }
    $ip = achord_inquiry_client_ip();
    $country = $ip !== '' ? (achord_inquiry_ip_country($ip) ?: '未能识别') : '未能识别';
    $time = wp_date('Y-m-d H:i');
    $small = static fn(string $text): string => $text !== '' ? '<div style="padding-top:2px;font-size:12px;font-weight:normal;color:#64748b;">' . esc_html($text) . '</div>' : '';
    [$channel, $channel_detail] = achord_inquiry_channel($fields);
    $device = explode(' · ', (string) ($fields['device'] ?? ''), 2);
    $device_html = $device[0] !== '' ? esc_html($device[0]) . $small((string) ($device[1] ?? '')) : '未能识别';
    $message = $type['show_message'] ? trim((string) ($fields['message'] ?? '')) : '';
    $message_block = $message !== '' ? $heading('留言') . '<tr><td style="padding:0 36px;"><div style="padding:16px 20px;background:#f5f7fa;border-radius:4px;font-size:16px;line-height:1.75;color:#0b1f33;word-break:break-word;">' . nl2br(esc_html($message), false) . '</div></td></tr>' : '';
    $admin_link = $post_id > 0 ? '<a href="' . esc_url(admin_url('post.php?post=' . $post_id . '&action=edit')) . '" style="color:#94a3b8;">网站后台「询盘」</a>' : '网站后台「询盘」';
    $host = esc_html((string) wp_parse_url(home_url(), PHP_URL_HOST));

    $body = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head><body style="margin:0;padding:0;background:#eef1f5;">'
        . '<table width="100%" cellspacing="0" cellpadding="0" style="width:100%;background:#eef1f5;-webkit-text-size-adjust:100%;text-size-adjust:100%;' . $font . '"><tr><td align="center" style="padding:32px 0;">'
        . '<table width="660" cellspacing="0" cellpadding="0" style="width:660px;background:#ffffff;border:1px solid #e3e8ef;border-radius:4px;border-collapse:separate;overflow:hidden;text-align:left;">'
        . '<tr><td height="6" style="height:6px;background:#215bdb;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:22px 36px;border-bottom:1px solid #edf0f4;"><table width="100%" cellspacing="0" cellpadding="0"><tr><td style="vertical-align:middle;">' . achord_inquiry_mail_logo() . '</td><td align="right" style="vertical-align:middle;font-size:13px;color:#64748b;">' . esc_html($time) . '</td></tr></table></td></tr>'
        . '<tr><td style="padding:30px 36px 0;"><div style="font-size:26px;line-height:1.3;font-weight:bold;color:#0b1f33;">' . esc_html($type['headline']) . '</div><div style="padding-top:10px;font-size:15px;line-height:1.7;color:#475569;">' . esc_html($type['intro']) . '</div></td></tr>'
        . '<tr><td style="padding:20px 36px 0;"><table width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7fa;border-radius:4px;"><tr><td style="padding:14px 18px;"><div style="font-size:12px;color:#64748b;padding-bottom:6px;">来源页面 &nbsp;<span style="display:inline-block;padding:2px 8px;border-radius:2px;background:#e3ebfc;color:#215bdb;font-size:12px;font-weight:bold;">' . esc_html($kind) . '</span>' . ($entry !== '' ? ' &nbsp;<span style="font-size:12px;color:#475569;">入口：' . esc_html($entry) . '</span>' : '') . '</div><div style="font-size:14px;line-height:1.6;word-break:break-all;">' . $page_html . '</div></td></tr></table></td></tr>'
        . $heading(esc_html($type['section'])) . '<tr><td style="padding:0 36px;">' . $product_html . '</td></tr>'
        . $heading('客户信息') . '<tr><td style="padding:0 36px;"><table width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #edf0f4;">' . implode('', $rows) . '</table></td></tr>'
        . $message_block
        . '<tr><td style="padding:24px 36px 0;"><table width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #edf0f4;border-radius:4px;border-collapse:separate;"><tr>' . $meta('来源渠道', esc_html($channel) . $small($channel_detail)) . $meta('访问设备', $device_html) . $meta('来源国家', esc_html($country)) . '</tr><tr>' . $meta('页面语言', esc_html(achord_inquiry_language_name((string) ($fields['language'] ?? '')))) . $meta('提交时间', esc_html($time)) . $meta('IP 地址', $ip !== '' ? esc_html($ip) : '未能识别') . '</tr></table></td></tr>'
        . '<tr><td align="center" style="padding:32px 36px 12px;"><table cellspacing="0" cellpadding="0"><tr><td align="center" style="background:#215bdb;border-radius:2px;"><a href="mailto:' . esc_attr($email) . '" style="display:inline-block;width:260px;line-height:50px;font-size:17px;font-weight:bold;color:#ffffff;text-decoration:none;text-align:center;letter-spacing:1px;">立即回复客户</a></td></tr></table></td></tr>'
        . '<tr><td align="center" style="padding:0 36px 32px;font-size:13px;line-height:1.6;color:#64748b;">也可以直接回复这封邮件，回信会发到 ' . esc_html($email) . '</td></tr>'
        . achord_inquiry_attribution_block($fields)
        . '</table>'
        . '<table width="660" cellspacing="0" cellpadding="0" style="width:660px;"><tr><td align="center" style="padding:18px 36px 0;font-size:12px;line-height:1.7;color:#94a3b8;">此邮件由 ' . $host . ' ' . esc_html($type['form_name']) . '自动发送 · 副本见' . $admin_link . '</td></tr></table>'
        . '</td></tr></table></body></html>';

    $subject = (string) apply_filters('achord_inquiry_mail_subject', $type['subject'] . $email, $fields);
    return [$subject, $body];
}

/** Send the sales notification for a saved inquiry; whether it went is kept as _mail_sent (1/0). */
function achord_inquiry_send_mail(array $fields, int $post_id): bool
{
    do_action('achord_inquiry_before_mail', $fields, $post_id);
    [$subject, $body] = achord_inquiry_admin_mail($fields, $post_id);
    $reply_to = 'Reply-To: ' . str_replace(["\r", "\n"], '', (string) ($fields['email'] ?? ''));
    $sent = (bool) wp_mail(achord_inquiry_recipient(), $subject, $body, ['Content-Type: text/html; charset=UTF-8', achord_inquiry_mail_from_header(), $reply_to]);
    if ($post_id > 0) {
        update_post_meta($post_id, '_mail_sent', $sent ? '1' : '0');
    }
    return $sent;
}

/**
 * The DingTalk message for a saved inquiry, as achord_dingtalk_dispatch() takes it: the form's fields under short labels,
 * then where it came from (page, button, channel, device, landing page, country, IP, time) and two links.
 */
function achord_inquiry_dingtalk_message(int $post_id, array $fields): array
{
    $type = achord_inquiry_lead_type($fields);
    $config = achord_inquiry_config();
    $rows = [];
    foreach (achord_inquiry_fields() as $name => $field) {
        $value = trim((string) ($fields[$name] ?? ''));
        if ($value === '' || $field['short'] === '' || ($name === 'message' && !$type['show_message'])) {
            continue;
        }
        $rows[] = ['label' => $field['short'], 'value' => $value, 'long' => (bool) $field['long']];
    }
    $rows = (array) apply_filters('achord_inquiry_dingtalk_rows', $rows, $fields);

    $source = achord_inquiry_source_page($fields);
    $meta = [];
    if ($source['title'] !== '') {
        $meta[] = ['label' => '来源页面', 'icon' => '📄', 'value' => $source['title']];
    }
    if ($source['url'] !== '') {
        $meta[] = ['label' => '页面地址', 'icon' => '🔗', 'value' => $source['url'], 'link' => true];
    }
    if (($fields['source_entry'] ?? '') !== '') {
        $meta[] = ['label' => '入口', 'icon' => '🔘', 'value' => (string) $fields['source_entry']];
    }
    $meta[] = ['label' => '来源渠道', 'icon' => '🚦', 'value' => implode(' · ', array_filter(achord_inquiry_channel($fields), 'strlen'))];
    if (($fields['device'] ?? '') !== '') {
        $meta[] = ['label' => '访问设备', 'icon' => str_starts_with((string) $fields['device'], '电脑') ? '💻' : '📱', 'value' => (string) $fields['device']];
    }
    // The page address keeps the parameters that pick a page (?page_id=); only a trailing slash and a # are ignored.
    $landing = (string) ($fields['attr_landing'] ?? '');
    $page_key = static fn(string $url): string => (string) preg_replace('#/(?=\?|$)#', '', (string) preg_replace('/#.*$/s', '', $url), 1);
    if ($landing !== '' && $page_key($landing) !== $page_key($source['url'])) {
        $meta[] = ['label' => '落地页', 'icon' => '🧭', 'value' => $landing, 'link' => true];
    }
    if ($post_id > 0 && get_post_meta($post_id, '_mail_sent', true) === '0') {
        $meta[] = ['label' => '注意', 'icon' => '⚠️', 'value' => '通知邮件没有发出去，请到后台查看这条询盘'];
    }
    $ip = achord_inquiry_client_ip();
    $country = $ip !== '' ? achord_inquiry_ip_country($ip) : '';
    if ($country !== '') {
        $meta[] = ['label' => '来源国家', 'icon' => '🌍', 'value' => $country];
    }
    if ($ip !== '') {
        $meta[] = ['label' => 'IP 地址', 'icon' => '🖥️', 'value' => $ip];
    }
    $meta[] = ['label' => '时间', 'icon' => '🕒', 'value' => wp_date('Y-m-d H:i')];

    $links = [];
    if (is_email((string) ($fields['email'] ?? ''))) {
        $links[] = ['label' => '✉️ 回复邮件', 'url' => 'mailto:' . $fields['email']];
    }
    if ($post_id > 0) {
        $links[] = ['label' => '📋 后台查看', 'url' => admin_url('post.php?post=' . $post_id . '&action=edit')];
    }
    $title = $type['dingtalk_title'] !== '' ? $type['dingtalk_title'] : $config['dingtalk_emoji'] . ' ' . $config['site_label'] . '新询盘';
    return [
        'title' => $title,
        'headline' => trim((string) ($fields['product'] ?? '')),
        'fields' => array_values($rows),
        'meta' => $meta,
        'links' => $links,
    ];
}

/** Mail, the achord_inquiry_submitted action (for other notifiers) and DingTalk, for a saved inquiry that is not blocked. */
function achord_inquiry_notify(int $post_id, array $fields): void
{
    achord_inquiry_send_mail($fields, $post_id);
    do_action('achord_inquiry_submitted', $post_id, $fields);
    if ($post_id > 0 && function_exists('achord_dingtalk_dispatch') && apply_filters('achord_inquiry_send_dingtalk', true, $fields)) {
        achord_dingtalk_dispatch(achord_inquiry_dingtalk_message($post_id, $fields), (string) achord_inquiry_config()['dingtalk_source']);
    }
}
