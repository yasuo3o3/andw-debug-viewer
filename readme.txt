=== andW Debug Viewer ===
Contributors: yasuo3o3
Tags: debug, logging, tools, admin
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safely view and manage `wp-content/debug.log` from the WordPress admin dashboard.

== Description ==

andW Debug Viewer is a utility for safely viewing and managing WordPress `wp-content/debug.log` from the admin dashboard. It efficiently handles large log files by fetching recent entries and restricts operations based on user permissions and environment.

**Key Features:**
* View recent N lines or recent M minutes of logs with toggle options
* Asynchronous REST API-based viewer with auto-refresh and pause functionality
* Display file metadata including size and modification time
* Clear and download logs via REST API endpoints
* Production environment protection with temporary 15-minute override for operations
* Multisite support with network admin priority and optional site admin permissions
* Comprehensive nonce and capability checks for all operations

**Security & Safety:**
* All operations require appropriate user capabilities (`manage_options` or `manage_network_options`)
* CSRF protection via WordPress nonces on all forms and AJAX requests
* Production environment defaults to read-only access with temporary override option
* Input sanitization and output escaping following WordPress standards

**日本語説明:**
andW Debug Viewer は WordPress の `wp-content/debug.log` を管理画面から安全に参照・管理するためのユーティリティです。大容量ログでも直近に絞って取得し、権限や環境に応じて操作を制限します。

== Installation ==

1. Upload the plugin ZIP file or place the entire folder under `wp-content/plugins/`.
2. Activate **andW Debug Viewer** from the Plugins screen.
3. Access the viewer from the top-level admin menu "andW Debug Viewer".

**日本語インストール手順:**
1. プラグイン ZIP をアップロードするか、フォルダーごと `wp-content/plugins/` 配下に配置します。
2. 「プラグイン」画面で **andW Debug Viewer** を有効化します。
3. 管理画面のトップレベルメニュー「andW Debug Viewer」からビューアーへアクセスできます。

== Frequently Asked Questions ==

= Cannot clear or download in production environment =

In production environments (`wp_get_environment_type() === 'production'`), read-only access is enabled by default for safety. You can issue a temporary 15-minute override from the Settings tab.

= How is it displayed when logs don't exist? =

If the log file is not found, a message about file permissions will be displayed. If the log is empty, an empty textarea will be shown.

= How does it behave in multisite? =

Network admin screens have full functionality. Individual site admin screens default to read-only access, with optional operation permissions configurable in network settings.

**日本語FAQ:**
= 本番環境でクリアやダウンロードができません =
本番環境では安全のため既定で閲覧のみ有効です。設定タブから 15 分間だけ一時許可を発行できます。

= マルチサイトでの挙動は？ =
ネットワーク管理画面では全機能を利用できます。個別サイトの管理画面では既定で閲覧のみとなり、ネットワーク設定でオプションとして操作を許可できます。

== Screenshots ==

1. Viewer screen: Recent lines/minutes toggle and log display

== Changelog ==

= 0.1.0 =
* WordPress.org submission preparation and review response
* readme.txt version unification fixes
* Distribution ZIP configuration optimization
* Comments and documentation improvements

= 0.0.1 =
* Initial release
