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
    $post_id = achord_inquiry_save($fields, $block_reason);
    if (is_wp_error($post_id)) {
        achord_inquiry_fail('saveFailed', 'error', 500);
    }
    if ($block_reason !== '') {
        achord_inquiry_succeed(0);
    }
    achord_inquiry_notify((int) $post_id, $fields);
    achord_inquiry_succeed((int) $post_id);
}
