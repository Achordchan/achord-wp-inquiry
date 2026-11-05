=== Achord WP Inquiry ===
Contributors: achord
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.2.0
License: GPLv2 or later

网站询盘的后台：接收、防垃圾、来源渠道、存储、通知邮件、钉钉。表单外观由主题负责。

== Changelog ==

= 0.2.0 =
* 询盘可「标为无效」（垃圾、与产品无关、测试）：后台列表行操作「标为无效 / 恢复有效」，列表显示「无效 · 原因」；管理员可经接口只写 meta.<rest_record>_invalid 一个字段（其他字段仍只读），记录里多出 invalid。询盘本身、状态、通知都不动。

= 0.1.0 =
* 从起重机站主题抽出：提交处理、Turnstile、每 IP 十分钟 5 条限流、黑名单（存成已拦截草稿）、来源渠道/访问设备/IP/国家、后台「询盘」列表和详情、只读接口、经典卡片通知邮件、推钉钉、设置 → 询盘设置。
* 主题用 add_theme_support('achord-inquiry', [...]) 声明后才启用；没声明时插件什么都不做。
