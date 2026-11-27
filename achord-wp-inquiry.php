<?php
/**
 * Plugin Name: Achord WP Inquiry
 * Description: 网站询盘的后台部分：接收表单、防垃圾（Turnstile、限流、黑名单）、记录来源渠道/访问设备/IP、存进后台「询盘」、发经典卡片通知邮件、推钉钉；表单发不出去时报警（开 GitHub Issue、推钉钉）。表单长什么样由主题决定；主题用 add_theme_support('achord-inquiry', [...]) 声明后插件才接管，没声明就什么都不做。
 * Version: 0.7.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: Achord
 * License: GPL-2.0-or-later
 * Text Domain: achord-wp-inquiry
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ACHORD_INQUIRY_VERSION', '0.7.0');
define('ACHORD_INQUIRY_FILE', __FILE__);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/context.php';
require_once __DIR__ . '/includes/network.php';
require_once __DIR__ . '/includes/guard.php';
require_once __DIR__ . '/includes/screen.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/notify.php';
require_once __DIR__ . '/includes/alert.php';
require_once __DIR__ . '/includes/submit.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/abilities.php';

// Themes declare support in after_setup_theme (functions.php runs after plugins load), so the hooks go in afterwards.
add_action('after_setup_theme', 'achord_inquiry_boot', 100);
