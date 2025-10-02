# andW Debug Viewer 開発ログ 2025-10-02

## 概要
デバッグログ機能の問題解決とログファイル管理方式の改善を実施

## 主要な問題と解決策

### 1. ログ有効化ボタンの問題

**問題**: 「15分間ログ出力を有効化」ボタンを押すと「ログ出力設定の変更に失敗しました」となり、ボタンが緑にならない

**原因**:
- `enable_temp_logging()`メソッドで`update_option`が失敗
- ログファイルが存在しないため、UI表示が「ログ機能無効」と判定される

**解決策**:
- ログ有効化時に確認メッセージをデバッグログファイルに出力
- 設定保存失敗時でもフォールバック処理でログファイルを作成
- 詳細なデバッグログを追加

### 2. ログファイル管理方式の改善

**従来の問題**:
- 同じ`debug.log`ファイルにすべてのログが混在
- wp-configによるログかプラグインによるログかの判別が困難
- 本番環境でのログ削除が危険

**新しい設計**:

#### パターン1: 一時ログ有効時 (`debug-temp.log`)
- **条件**: WP_DEBUG=false の場合のみ有効化可能
- **ファイル**: `wp-content/debug-temp.log`
- **内容**: プラグイン管理期間中のすべてのログ
- **操作**: 本番環境でも制限なしでクリア・ダウンロード可能
- **理由**: 別ファイルなので完全に安全

#### パターン2: WP_DEBUG=true時 (`debug.log`)
- **条件**: 一時ログ有効化は禁止
- **ファイル**: `wp-content/debug.log` (元のファイル)
- **内容**: wp-configで設定されたシステムログ
- **操作**: 本番環境では15分間の一時許可が必要
- **理由**: デバッグ中に介入要素を入れたくない

#### パターン3: WP_DEBUG=false時
- **条件**: 一時ログも無効
- **ファイル**: なし (`get_log_path()` が `null`)
- **表示**: "ログファイルが存在しません"
- **操作**: 不可

## 実装した変更

### 1. ログ有効化処理の修正

**ファイル**: `includes/class-andw-settings.php`

- **L180-187**: WP_DEBUG=trueの場合は一時ログ有効化を禁止
- **L219-224**: ログ有効化成功時の確認メッセージ出力
- **L254-259**: フォールバック処理での確認メッセージ出力
- **L291**: 一時ログ出力先を`debug-temp.log`に変更
- **L307-312**: 期限切れ時は`debug-temp.log`を削除

### 2. ログリーダーの修正

**ファイル**: `includes/class-andw-log-reader.php`

- **L24-42**: ログファイルパスの判定ロジック
  - 一時ログ有効時: `debug-temp.log`
  - WP_DEBUG=true時: `debug.log`
  - WP_DEBUG=false時: `null` (ファイルなし)

### 3. 権限制御の修正

**ファイル**: `includes/class-andw-plugin.php`

- **L196**: 一時ログ有効時は本番環境でも操作可能に変更

## エラー修正

### Fatal Error: Call to undefined method
**問題**: `write_debug_log()`メソッドが存在しない
**解決**: 直接`file_put_contents()`を使用するように変更

## 技術的詳細

### ログファイル切り替えメカニズム

```php
// 一時ログ有効化時の設定
ini_set( 'error_log', WP_CONTENT_DIR . '/debug-temp.log' );

// ログファイルパス判定
public function get_log_path() {
    $settings = new Andw_Settings();

    if ( $settings->is_temp_logging_active() ) {
        return trailingslashit( WP_CONTENT_DIR ) . 'debug-temp.log';
    }

    $wp_debug_enabled = defined( 'WP_DEBUG' ) && WP_DEBUG;
    $wp_debug_log_enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

    if ( $wp_debug_enabled && $wp_debug_log_enabled ) {
        return trailingslashit( WP_CONTENT_DIR ) . self::LOG_RELATIVE_PATH;
    }

    return null; // ログファイルなし
}
```

### 権限制御ロジック

```php
// 一時ログ有効時は本番環境でも操作可能
if ( $is_production && ! $override_active && ! $temp_logging_active ) {
    $can_clear = false;
    $can_download = false;
}
```

## メリット

1. **安全性向上**: 重要なシステムログを誤削除するリスクがない
2. **明確な分離**: プラグイン管理ログと本番ログの完全分離
3. **使いやすさ**: 一時ログ時は制限なしで操作可能
4. **デバッグ配慮**: WP_DEBUG有効時は介入しない設計

## 残課題

- UI表示でのファイル状態の明確化
- エラーメッセージの改善
- ログファイルサイズ制限の検討

## 次回作業予定

### 優先課題: ログクリア・ダウンロードボタンが有効化されない問題

**現状**:
- 今回のロジック変更後も、本番環境での一時許可機能が正しく動作していない
- 15分間のログ出力有効化後も、ログビューアーの「ログをクリア」「ダウンロード」ボタンが有効化されない
- 一時許可を発行した場合でも同様にボタンが無効のまま

**調査ポイント**:
1. 本番環境の一時許可機能の権限判定ロジック
2. UIボタンの有効/無効判定処理
3. 一時ログ有効時とは別の、本番環境用の15分間一時許可機能
4. フロントエンド側のボタン状態制御

**次回作業**:
- ボタンの有効化条件を詳細調査
- 権限チェック処理のデバッグ
- UI表示ロジックの確認

### その他の作業予定

- 実際の動作テスト
- UI/UXの改善
- エラーハンドリングの強化