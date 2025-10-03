# andW Debug Viewer - セッション機能デバッグ作業ログ

## 日付: 2025-10-03

## 🎯 作業概要
WordPressプラグイン「andW Debug Viewer」で、セッションファイルが正常に作成されているにも関わらず、ログクリア・ダウンロードボタンが有効にならない問題の解決に取り組んだ。

## 📋 問題の詳細

### 現象
- 設定ページの「一時的にログを有効化」ボタンは正常に緑色に変化
- セッションファイル（`andw-session.json`）は正常に作成される
- しかし、ログビューワーページの「ログを削除」「ログをダウンロード」ボタンが有効化されない
- 本番環境での「ログを削除」「ログをダウンロード」の一時許可ボタンを押しても変化なし

### 確認済みファイル状態
```json
// andw-session.json
{
    "created_at": 1759445421,
    "expires_at": 1759446321,
    "session_type": "temp_logging",
    "safe_to_clear": true
}
```

### 環境情報
- 環境: production
- WP_DEBUG: false
- WP_DEBUG_LOG: false
- 赤いPRODUCTION表示あり

## 🔧 実施した修正

### 1. セッション情報を権限配列に追加
**ファイル**: `includes/class-andw-plugin.php:242`
```php
return array(
    'environment'                => $environment,
    'is_production'              => $is_production,
    'override_active'            => $override_active,
    'override_expires'           => $override_active ? (int) $settings['production_temp_expiration'] : 0,
    'temp_logging_active'        => $temp_logging_active,
    'temp_logging_expires'       => $temp_logging_active ? (int) $settings['temp_logging_expiration'] : 0,
    'temp_session_active'        => $temp_session_active, // ← 追加
    // ... 他の項目
);
```

### 2. ボタンロジックでセッションを考慮
**ファイル**: `includes/class-andw-admin.php:547-557`
```php
$temp_logging_active = ! empty( $permissions['temp_logging_active'] );
$temp_session_active = ! empty( $permissions['temp_session_active'] ); // ← 追加

// 実際のログ機能状態を確認（セッション有効時も含む）
$actual_logging_works = ( ini_get( 'log_errors' ) && ini_get( 'error_log' ) ) ||
                       ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ||
                       $temp_logging_active ||
                       $temp_session_active; // ← 追加
```

### 3. セッション用ステータス表示を追加
**ファイル**: `includes/class-andw-admin.php:587-590`
```php
} elseif ( $temp_session_active ) {
    echo '<div style="background: #00a32a; color: white; padding: 8px 12px; border-radius: 4px; margin-bottom: 10px; display: inline-block;">';
    echo '<strong>🟢 一時セッション 有効中</strong> - ログ操作が15分間許可されています';
    echo '</div><br>';
```

### 4. タイムスタンプの一貫性を修正
**ファイル**: `includes/class-andw-settings.php:338-347`
```php
// セッション作成時
// Use time() instead of current_time() for consistency across environments
$now = time();
$session_data = array(
    'created_at' => $now,
    'expires_at' => $now + ( 15 * MINUTE_IN_SECONDS ),
    'session_type' => 'temp_logging',
    'safe_to_clear' => true,
);
```

**ファイル**: `includes/class-andw-settings.php:392-394`
```php
// セッション確認時
// Use time() instead of current_time() for consistency with session creation
$current_time = time();
$expires_at = $session_data['expires_at'];
$is_active = $expires_at > $current_time;
```

### 5. 詳細デバッグログを追加

#### 本番環境一時許可ボタンのデバッグ
**ファイル**: `includes/class-andw-admin.php:654-679`
```php
error_log( 'andW Debug Viewer: handle_toggle_production_override() - state: ' . $state );
error_log( 'andW Debug Viewer: handle_toggle_production_override() - 実行前の権限確認' );

// 現在の権限状態を確認
$current_permissions = $this->plugin->get_permissions();
error_log( 'andW Debug Viewer: handle_toggle_production_override() - 実行前の権限: ' . print_r( $current_permissions, true ) );

// ... 処理 ...

// 設定後の権限状態を確認
$after_permissions = $this->plugin->get_permissions();
error_log( 'andW Debug Viewer: handle_toggle_production_override() - 実行後の権限: ' . print_r( $after_permissions, true ) );
```

#### ボタン状態の詳細デバッグ
**ファイル**: `includes/class-andw-admin.php:376-383`
```php
error_log( 'andW Debug Viewer: Button rendering - 権限詳細: ' . print_r( $permissions, true ) );
error_log( 'andW Debug Viewer: Button rendering - can_clear: ' . ( empty( $permissions['can_clear'] ) ? 'false' : 'true' ) );
error_log( 'andW Debug Viewer: Button rendering - can_download: ' . ( empty( $permissions['can_download'] ) ? 'false' : 'true' ) );
error_log( 'andW Debug Viewer: Button rendering - is_production: ' . ( ! empty( $permissions['is_production'] ) ? 'true' : 'false' ) );
error_log( 'andW Debug Viewer: Button rendering - override_active: ' . ( ! empty( $permissions['override_active'] ) ? 'true' : 'false' ) );
error_log( 'andW Debug Viewer: Button rendering - temp_session_active: ' . ( ! empty( $permissions['temp_session_active'] ) ? 'true' : 'false' ) );
```

#### セッション処理の詳細デバッグ
**ファイル**: `includes/class-andw-settings.php:367-399`
```php
error_log( 'andW Debug Viewer: is_temp_session_active() - session_file: ' . $session_file );
error_log( 'andW Debug Viewer: is_temp_session_active() - file_exists: ' . ( file_exists( $session_file ) ? 'true' : 'false' ) );
error_log( 'andW Debug Viewer: is_temp_session_active() - session_content: ' . $session_content );
error_log( 'andW Debug Viewer: is_temp_session_active() - current_time: ' . $current_time );
error_log( 'andW Debug Viewer: is_temp_session_active() - expires_at: ' . $expires_at );
error_log( 'andW Debug Viewer: is_temp_session_active() - is_active: ' . ( $is_active ? 'true' : 'false' ) );
```

## 📈 技術的改善点

### 問題解決のアプローチ
1. **セッション機能の一貫性**: `current_time('timestamp')` vs `time()` の不整合を解決
2. **権限チェックの統合**: 従来のログ機能 + セッション機能の統合
3. **デバッグ可視性**: 全権限フローの詳細ログ出力

### アーキテクチャの変更
- **従来**: `debug-temp.log` 別ファイル方式（無限ループの原因）
- **新方式**: `andw-session.json` + `debug.log` 統合方式

## 🔴 赤いPRODUCTION表示について

### 表示の意味
- **単純な環境識別表示**: 本番環境であることの視覚的警告
- **機能制限はしない**: 表示だけで実際の機能制限は別ロジック

### CSSでの定義
**ファイル**: `assets/css/admin.css:31-33`
```css
.andw-env-production {
    background-color: #d63638; /* 赤色 */
}
```

### 色分けルール
- 🔴 **赤色**: 本番環境 (`production`)
- 🟡 **黄色**: ステージング環境 (`staging`)
- 🟢 **緑色**: 開発環境 (`development`, `local`)

## 🧪 テスト手順

### 次回の検証項目
1. **本番環境一時許可ボタンを押す**
   - debug.logに詳細な実行ログが出力される

2. **ログビューワーページでボタン状態を確認**
   - ボタンの権限状態が詳細にログ出力される

3. **期待されるログエントリ**:
   ```
   [日時] andW Debug Viewer: handle_toggle_production_override() - state: enable
   [日時] andW Debug Viewer: handle_toggle_production_override() - 実行前の権限: Array(...)
   [日時] andW Debug Viewer: handle_toggle_production_override() - 実行後の権限: Array(...)
   [日時] andW Debug Viewer: Button rendering - 権限詳細: Array(...)
   ```

## 🔄 残された課題

### 現在の状況
- セッションファイルは正常作成
- 設定ページボタンは正常動作
- ログビューワーボタンが依然として無効

### 想定される原因
1. **権限判定ロジックの不整合**: セッション情報が権限計算に反映されていない可能性
2. **本番環境固有の設定**: `wp_get_environment_type()` の判定に問題がある可能性
3. **キャッシュ問題**: 権限情報がキャッシュされている可能性

### 次のデバッグポイント
- `get_permissions()` メソッドの詳細フロー確認
- 本番環境でのタイムスタンプ処理確認
- ページリロード時の権限再計算確認

## 📝 作業ファイル一覧

### 修正済みファイル
1. `includes/class-andw-plugin.php` - 権限配列にセッション情報追加
2. `includes/class-andw-admin.php` - ボタンロジック更新、デバッグ追加
3. `includes/class-andw-settings.php` - タイムスタンプ統一、デバッグ追加

### 参照ファイル
1. `assets/css/admin.css` - PRODUCTION表示スタイル確認

## 💡 学んだこと

### WordPressでのタイムスタンプ処理
- `current_time('timestamp')` は timezone 設定の影響を受ける
- `time()` は UTC タイムスタンプで一貫性がある
- プラグイン内での統一が重要

### 権限管理システムの設計
- 複数の権限ソース（設定、セッション、環境）の統合
- デバッグ可視性の重要性
- フロントエンドとバックエンドの権限同期

---

**次回作業時の参照ポイント**: debug.log の詳細ログから、ボタンが無効化される具体的な原因を特定する