<?php
/**
 * The standard inquiry form: what the theme prints inside its <form>, the script that adds where the visitor came from,
 * and the handler that checks, saves and sends it.
 *
 * The form posts to /?{prefix}inquiry_submit=1 with action={prefix}submit_inquiry (admin-post.php also works). A field
 * {prefix}inquiry_response=json, an Accept: application/json header or X-Requested-With: XMLHttpRequest gets JSON back
 * ({message, id, redirect}); otherwise the visitor is sent to the thank-you page with ?sent=<id>.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Add the plugin's hooks, if the theme has opted in (after_setup_theme, priority 100). */
function achord_inquiry_boot(): void
{
    if (!achord_inquiry_active()) {
        return;
    }
    achord_inquiry_store_hooks();
    add_action('init', 'achord_inquiry_front_controller', 0);
    add_action('admin_post_' . achord_inquiry_name('submit_inquiry'), 'achord_inquiry_handle');
    add_action('admin_post_nopriv_' . achord_inquiry_name('submit_inquiry'), 'achord_inquiry_handle');
    add_action('wp_enqueue_scripts', 'achord_inquiry_enqueue_script');
    add_action('achord_inquiry_unscreened', 'achord_inquiry_handle_unscreened');
    do_action('achord_inquiry_booted');
}

function achord_inquiry_front_controller(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || (string) ($_GET[achord_inquiry_name('inquiry_submit')] ?? '') !== '1') {
        return;
    }
    if (sanitize_key((string) wp_unslash($_POST['action'] ?? '')) !== achord_inquiry_name('submit_inquiry')) {
        return;
    }
    achord_inquiry_handle();
}

/** Where the form posts. */
function achord_inquiry_action_url(): string
{
    return add_query_arg(achord_inquiry_name('inquiry_submit'), '1', home_url('/'));
}

/**
 * The hidden fields the form needs, for the theme to print inside its <form>: the action, the nonce, a field only bots
 * fill in, the button that opened the form (source_entry), the page's title and language, and json when the theme sends
 * the form by script. The script (assets/inquiry.js) adds where the visitor came from when the form is sent.
 */
function achord_inquiry_hidden_fields(array $args = []): string
{
    $hidden = static fn(string $name, string $value, string $extra = ''): string => '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . $extra . '>';
    $html = $hidden('action', achord_inquiry_name('submit_inquiry'));
    if (!empty($args['json'])) {
        $html .= $hidden(achord_inquiry_name('inquiry_response'), 'json');
    }
    $language = (string) ($args['language'] ?? apply_filters('achord_inquiry_page_language', ''));
    if ($language !== '') {
        $html .= $hidden(achord_inquiry_name('lang'), $language);
    }
    $html .= wp_nonce_field(achord_inquiry_name('submit_inquiry'), achord_inquiry_name('inquiry_nonce'), true, false);
    $html .= $hidden('source_entry', (string) ($args['source'] ?? ''), ' data-inquiry-source-field');
    $html .= $hidden('source_title', (string) ($args['title'] ?? wp_get_document_title()));
    $html .= '<label class="screen-reader-text" aria-hidden="true" style="position:absolute;left:-9999px">Website <input type="text" name="' . esc_attr(achord_inquiry_name('inquiry_website')) . '" tabindex="-1" autocomplete="off"></label>';
    return $html;
}

function achord_inquiry_enqueue_script(): void
{
    $config = achord_inquiry_config();
    wp_enqueue_script('achord-inquiry', plugins_url('assets/inquiry.js', ACHORD_INQUIRY_FILE), [], ACHORD_INQUIRY_VERSION, ['strategy' => 'defer', 'in_footer' => true]);
    wp_add_inline_script('achord-inquiry', 'window.achordInquiryConfig=' . wp_json_encode([
        'prefix' => $config['prefix'],
        'selector' => trim('[data-achord-inquiry]' . ($config['form_selector'] !== '' ? ', ' . $config['form_selector'] : '')),
        'storageKey' => $config['attribution_key'],
    ]) . ';', 'before');
}

function achord_inquiry_wants_json(): bool
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $requested_with = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $format = sanitize_key((string) wp_unslash($_POST[achord_inquiry_name('inquiry_response')] ?? ''));
    return $format === 'json' || str_contains($accept, 'application/json') || $requested_with === 'xmlhttprequest';
}

/** Turn the visitor away with a message: JSON for a scripted form, else back to the form's page with ?inquiry=<status>. */
function achord_inquiry_fail(string $message_key, string $status = 'missing', int $code = 400): void
{
    $message = achord_inquiry_message($message_key);
    if (achord_inquiry_wants_json()) {
        wp_send_json_error(['message' => $message], $code);
    }
    $back = wp_get_referer() ?: ((string) achord_inquiry_config()['fallback_url'] ?: home_url('/'));
    wp_safe_redirect(add_query_arg('inquiry', $status, $back));
    exit;
}

/**
 * Answer a sent inquiry: JSON for a scripted form, else the thank-you page. Its address carries the saved inquiry's
 * number (?sent=), which the theme reports once as its conversion. A blocked or bot inquiry (id 0) gets the same answer
 * without the number, so it is never counted.
 */
function achord_inquiry_succeed(int $post_id): void
{
    $base = (string) achord_inquiry_config()['success_url'];
    $base = $base !== '' ? $base : home_url('/');
    $redirect = $post_id > 0 ? add_query_arg('sent', $post_id, $base) : $base;
    if (achord_inquiry_wants_json()) {
        wp_send_json_success(['message' => achord_inquiry_message('success'), 'id' => $post_id, 'redirect' => $redirect]);
    }
    wp_safe_redirect($redirect);
    exit;
}

/** The form's fields from the POST, each cleaned for its type. */
function achord_inquiry_posted_fields(): array
{
    $fields = [];
    foreach (achord_inquiry_fields() as $name => $field) {
        $value = $_POST[$name] ?? '';
        $value = is_scalar($value) ? (string) wp_unslash($value) : '';
        $value = match ($field['type']) {
            'email' => sanitize_email($value),
            'url', 'text' => mb_substr(sanitize_text_field($value), 0, 300),
            default => mb_substr(sanitize_textarea_field($value), 0, 5000),
        };
        $fields[$name] = $value;
    }
    return $fields;
}

function achord_inquiry_handle(): void
{
    // A multilingual theme switches to the page's language here, so messages come back in it.
    do_action('achord_inquiry_before_handle');
    $nonce = sanitize_text_field((string) wp_unslash($_POST[achord_inquiry_name('inquiry_nonce')] ?? ''));
    if ($nonce === '' || !wp_verify_nonce($nonce, achord_inquiry_name('submit_inquiry'))) {
        if (achord_inquiry_wants_json()) {
            wp_send_json_error(['message' => achord_inquiry_message('invalidRequest')], 403);
        }
        wp_die(esc_html(achord_inquiry_message('invalidRequest')), '', ['response' => 403]);
    }
    // A field only a script fills in: anything in it means a bot filled every box. It is told the inquiry went through.
    if (trim((string) wp_unslash($_POST[achord_inquiry_name('inquiry_website')] ?? '')) !== '') {
        achord_inquiry_succeed(0);
    }

    $fields = achord_inquiry_posted_fields() + achord_inquiry_collect_context();
    // A theme adds what it works out from the form (the crane site: discontinued models of the chosen products).
    $fields = (array) apply_filters('achord_inquiry_submission_fields', $fields);

    foreach ((array) achord_inquiry_config()['required'] as $name) {
        if (trim((string) ($fields[$name] ?? '')) === '') {
            achord_inquiry_fail('requiredFields');
        }
    }
    if (!is_email((string) ($fields['email'] ?? ''))) {
        achord_inquiry_fail('requiredFields');
    }
    if (!achord_inquiry_verify_turnstile()) {
        achord_inquiry_fail('verificationRequired', 'verification');
    }
    // The slot is taken before anything is saved, so parallel submissions cannot all slip under the limit.
    if (!achord_inquiry_reserve_slot()) {
        achord_inquiry_fail('tooMany', 'error', 429);
    }
    // A sender on the blocklist is saved as a blocked draft and told it went through: no mail, no DingTalk, no conversion.
    $block_reason = achord_inquiry_block_reason((string) $fields['email'], achord_inquiry_client_ip());
    if ($block_reason !== '') {
        $post_id = achord_inquiry_save($fields, $block_reason);
        if (is_wp_error($post_id)) {
            achord_inquiry_fail('saveFailed', 'error', 500);
        }
        achord_inquiry_succeed(0);
    }
    // Saved before screening, so a request cut off while waiting for the AI loses nothing: a safety net 3 minutes on
    // sends the notification of any inquiry the screening never finished (achord_inquiry_handle_unscreened()).
    $post_id = achord_inquiry_save($fields);
    if (is_wp_error($post_id)) {
        achord_inquiry_fail('saveFailed', 'error', 500);
    }
    $post_id = (int) $post_id;
    wp_schedule_single_event(time() + 180, 'achord_inquiry_unscreened', [$post_id]);
    // Screening (screen.php): the AI's verdict, stars and background note, or the rules when there is no AI.
    $screen = achord_inquiry_screen($fields, achord_inquiry_client_ip());
    $fields['screen_verdict'] = $screen['verdict'];
    $fields['screen_reason'] = $screen['reason'];
    $fields['screen_stars'] = $screen['stars'] > 0 ? (string) $screen['stars'] : '';
    $fields['screen_summary'] = $screen['summary'];
    $fields['screened_by'] = $screen['by'];
    foreach (['screen_verdict', 'screen_reason', 'screen_stars', 'screen_summary', 'screened_by'] as $key) {
        if ($fields[$key] !== '') {
            update_post_meta($post_id, '_' . $key, wp_slash($fields[$key]));
        }
    }
    $answer = achord_inquiry_finish_screened($post_id, $fields);
    achord_inquiry_succeed($answer);
}

/**
 * What the verdict asks for, then marks the inquiry done (_screen_done) and lifts the safety net; returns the id the
 * thank-you page may carry (0 for spam and suspect, so no conversion). Spam: marked invalid, a one-line DingTalk notice,
 * no email (恢复有效 sends it). Suspect: marked invalid, sent flagged. Otherwise sent with its stars.
 */
function achord_inquiry_finish_screened(int $post_id, array $fields): int
{
    $verdict = (string) ($fields['screen_verdict'] ?? '');
    $reason = (string) ($fields['screen_reason'] ?? '');
    $by = (string) ($fields['screened_by'] ?? '');
    if ($verdict === 'spam') {
        achord_inquiry_set_invalid($post_id, '已拦截（' . $by . '）：' . $reason, true);
        if (function_exists('achord_dingtalk_dispatch') && apply_filters('achord_inquiry_send_dingtalk', true, $fields)) {
            achord_dingtalk_dispatch(achord_inquiry_dingtalk_blocked_message($post_id, $fields), (string) achord_inquiry_config()['dingtalk_source']);
        }
    } else {
        if ($verdict === 'suspect') {
            achord_inquiry_set_invalid($post_id, '自动判定疑似垃圾：' . $reason, true);
            $fields['suspect'] = $reason;
        }
        if (get_post_meta($post_id, '_mail_sent', true) === '') {
            achord_inquiry_notify($post_id, $fields);
        }
    }
    update_post_meta($post_id, '_screen_done', '1');
    wp_clear_scheduled_hook('achord_inquiry_unscreened', [$post_id]);
    return in_array($verdict, ['spam', 'suspect'], true) ? 0 : $post_id;
}

/**
 * The safety net: an inquiry whose request was cut off before its screening finished (waiting for the AI, or after the
 * verdict but before its actions) is finished here, once.
 */
function achord_inquiry_handle_unscreened($post_id): void
{
    $post_id = (int) $post_id;
    if ($post_id <= 0 || get_post_meta($post_id, '_screen_done', true) === '1') {
        return;
    }
    // Deleted, trashed or not an inquiry: nothing to send.
    $status = get_post_status($post_id);
    if ($status === false || $status === 'trash' || get_post_type($post_id) !== achord_inquiry_post_type()) {
        return;
    }
    // An administrator marked it invalid meanwhile: their call stands, nothing is sent. The screening's own mark
    // (_invalid_auto, spam or suspect cut off before its notice) is finished below instead.
    if (get_post_meta($post_id, '_invalid', true) !== '' && get_post_meta($post_id, '_invalid_auto', true) !== '1') {
        update_post_meta($post_id, '_screen_done', '1');
        return;
    }
    // A verdict already saved is carried out as it stands; without one, the inquiry goes to sales as an ordinary one.
    $fields = achord_inquiry_record($post_id);
    if (($fields['screen_verdict'] ?? '') === '') {
        $fields['screen_verdict'] = 'unfinished';
        $fields['screened_by'] = '未完成（提交请求中途断开，按普通询盘补发）';
        update_post_meta($post_id, '_screen_verdict', 'unfinished');
        update_post_meta($post_id, '_screened_by', wp_slash($fields['screened_by']));
    }
    // Sent from WP-Cron: the sender's saved IP (or none) stands in for the cron request's own address.
    achord_inquiry_ip_override((string) ($fields['ip'] ?? ''));
    try {
        achord_inquiry_finish_screened($post_id, $fields);
    } finally {
        achord_inquiry_ip_override(null, true);
    }
}
