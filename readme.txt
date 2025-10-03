=== andW Debug Viewer ===
Contributors: yasuo3o3
Tags: debug, logging, tools, admin
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

安全に `wp-content/debug.log` を閲覧・管理できる管理画面ツール。

== Description ==

andW Debug Viewer は WordPress の `wp-content/debug.log` を管理画面から安全に参照・管理するためのユーティリティです。大容量ログでも直近に絞って取得し、権限や環境に応じて操作を制限します。

* 直近 N 行または直近 M 分のログを切り替えつつ表示
* REST API ベースの非同期ビューアーで自動更新/一時停止に対応
* ファイルサイズや更新時刻などのメタ情報を表示
* ログのクリアや（任意で）ダウンロードを REST API から実行
* 本番環境では既定で閲覧のみ。15分間の一時許可を発行して操作可能
* マルチサイトはネットワーク管理画面を優先し、サイト管理者には閲覧のみ（オプションで許可可）
* すべての操作にノンス・権限チェックを実装

== Installation ==

1. プラグイン ZIP をアップロードするか、フォルダーごと `wp-content/plugins/` 配下に配置します。
2. 「プラグイン」画面で **andW Debug Viewer** を有効化します。
3. 管理画面のトップレベルメニュー「andW Debug Viewer」からビューアーへアクセスできます。

== Frequently Asked Questions ==

= 本番環境でクリアやダウンロードができません =

本番環境（`wp_get_environment_type() === 'production'`）では安全のため既定で閲覧のみ有効です。設定タブから 15 分間だけ一時許可を発行できます。

= ログが存在しない場合はどう表示されますか？ =

ログファイルが見つからない場合は、その旨とファイル権限の確認メッセージを表示します。ログが空の場合は空のテキストエリアが表示されます。

= マルチサイトでの挙動は？ =

ネットワーク管理画面では全機能を利用できます。個別サイトの管理画面では既定で閲覧のみとなり、ネットワーク設定でオプションとして操作を許可できます。

== Screenshots ==

1. ビューアー画面：直近行数/分数切り替えとログ表示

== Changelog ==

= 0.01 =
* 初期リリース
