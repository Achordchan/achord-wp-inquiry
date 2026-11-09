# Achord WP Inquiry

BOB-LIFT 各站共用的询盘后台。表单长什么样由主题决定，提交之后的事都归插件：

- 接收提交：随机数、蜜罐、必填项、Cloudflare Turnstile、每个 IP 十分钟 5 条、黑名单
- 筛查：设了 AI 接口就先由 AI 判垃圾并给 1–5 星背调（服务器先查 IP 归属、邮箱域名官网和注册日期、来源渠道），垃圾不发邮件、钉钉推「拦截一条垃圾询盘」、不计转化；没设或超时用规则判「疑似垃圾」
- 来源：前端脚本 `assets/inquiry.js` 在提交时补上进站来源（上一站域名、广告点击编号和 utm，后者只在访客同意广告 Cookie 时记 90 天），后台算出来源渠道、访问设备、IP 和国家
- 存储：后台「询盘」列表（每条询盘一篇私有文章，字段存在 `_字段名`），列表列、只读详情框、复制客户信息按钮，管理员只读接口 `/wp/v2/<rest_base>`；「标为无效」（列表行操作，或管理员经接口只写 `meta.<rest_record>_invalid` 一个字段写原因、写空字符串恢复），日报统计真实询盘时排除带 `invalid` 的
- 通知：经典卡片邮件（来源渠道、设备、国家、语言、时间、IP 两行六格，广告原始参数放底部），装了 achord-wp-dingtalk 时推钉钉
- 设置：后台「设置 → 询盘设置」，也可经 `/wp/v2/settings` 的 `achord_inquiry_options` 读写

## 主题怎么接

在主题 `functions.php` 里声明（不声明插件就什么都不做，所以可以先装插件再部署主题）：

```php
add_theme_support('achord-inquiry', [
    'site_label' => '装载设备站',          // 邮件标题、主题和钉钉标题里的站名
    'dingtalk_emoji' => '🚜',
    'dingtalk_source' => 'bobliftloader:inquiry',
    'prefix' => 'bl_',                     // 所有提交字段和接口名的前缀
    'post_type' => 'inquiry',
    'rest_base' => 'inquiries',
    'rest_record' => 'bl_inquiry',
    'required' => ['name', 'email'],
    'success_url' => home_url('/thank-you/'),
    'form_selector' => '.bl-inquiry-form',
    'attribution_key' => 'bl_attribution',  // 沿用原来的 localStorage 名，访客已有的广告记录不丢
    'logo_url' => get_theme_file_uri('assets/logo.png'),
]);
```

表单里输出隐藏字段，提交到插件：

```php
<form method="post" action="<?= esc_url(achord_inquiry_action_url()) ?>" data-achord-inquiry>
  <?= achord_inquiry_hidden_fields(['source' => '产品页表单']) ?>
  <input name="name"> <input name="email" type="email"> <textarea name="message"></textarea>
  <?= achord_inquiry_turnstile_widget() ?>
</form>
```

用脚本提交时传 `'json' => true`，插件返回 `{message, id, redirect}`。不用脚本时成功跳到 `success_url?sent=<编号>`，失败回到原页面带 `?inquiry=missing|verification|error`。

Cookie 横幅是主题的，告诉插件脚本访客的选择：

```js
window.achordInquiryConsent = { tracking: true, advertising: true, agreed: () => true /* false / null */ };
document.dispatchEvent(new Event('achord-inquiry:consent')); // 访客改了选择时
```

## 过滤器

| 过滤器 | 用途 |
|---|---|
| `achord_inquiry_config` | 改配置 |
| `achord_inquiry_default_options` | 询盘设置没保存前，用主题原有的值（收件人、Turnstile、黑名单） |
| `achord_inquiry_fields` | 加字段或改名称（邮件名、钉钉短名、后台名、类型） |
| `achord_inquiry_message` | 访客看到的提示语（多语种主题在这里翻译） |
| `achord_inquiry_lead_types` | 询盘以外的线索类型（如目录下载）的邮件和钉钉说法 |
| `achord_inquiry_submission_fields` | 提交后补字段 |
| `achord_inquiry_mail_products_html` | 邮件「产品信息」一节（产品图、型号卡片） |
| `achord_inquiry_mail_rows` | 客户信息表后加行 |
| `achord_inquiry_mail_subject` | 邮件主题 |
| `achord_inquiry_recipient` | 询盘设置没填收件人时的地址 |
| `achord_inquiry_ai_catalog` | 给 AI 的网站与产品说明（默认是站名、副标题和 product 文章标题） |
| `achord_inquiry_dingtalk_rows` | 钉钉字段 |
| `achord_inquiry_product_column` | 后台列表「意向产品」一列 |
| `achord_inquiry_accept_attribution` | 是否收广告参数（没有广告同意项时返回 false） |
| `achord_inquiry_client_ip` | 自建反向代理时取真实 IP |

动作：`achord_inquiry_before_handle`（多语种主题切语言）、`achord_inquiry_before_mail`、`achord_inquiry_submitted`（询盘已存、已发邮件）。

## 测试

```
php tests/test.php
node --check assets/inquiry.js
node tests/inquiry.test.js
```
