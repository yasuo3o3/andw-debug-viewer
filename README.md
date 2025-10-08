# andW Debug Viewer

[![WordPress](https://img.shields.io/badge/WordPress-6.0+-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.1+-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2+-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A WordPress plugin for safely viewing and managing `wp-content/debug.log` from the admin dashboard.

## Features

- **Safe Log Viewing**: View recent N lines or M minutes of debug logs
- **REST API Integration**: Asynchronous viewer with auto-refresh and pause functionality
- **Production Safety**: Read-only default in production with temporary override option
- **Multisite Support**: Network admin priority with configurable site admin permissions
- **Security First**: Comprehensive nonce and capability checks for all operations

## Installation

### From WordPress Admin
1. Upload the plugin ZIP file via **Plugins → Add New → Upload Plugin**
2. Activate **andW Debug Viewer** from the Plugins screen
3. Access via **andW Debug Viewer** in the admin menu

### Manual Installation
1. Download and extract the plugin files
2. Upload the `andw-debug-viewer` folder to `/wp-content/plugins/`
3. Activate the plugin through the WordPress admin

## Requirements

- **WordPress**: 6.0 or higher
- **PHP**: 8.1 or higher
- **User Capability**: `manage_options` or `manage_network_options`

## Usage

### Basic Operation
1. Navigate to **andW Debug Viewer** in your admin menu
2. Choose between **Lines** or **Minutes** view mode
3. Set the number of recent entries to display
4. Use **Refresh** for manual updates or enable **Auto-refresh**

### Production Environment
- By default, production environments allow read-only access
- Use the **Settings** tab to enable temporary 15-minute override for log operations
- Clear and download functions require explicit permission

### Multisite Networks
- Network admins have full access to all features
- Individual site admins have read-only access by default
- Network settings allow enabling site admin operations

## Security Features

- **CSRF Protection**: WordPress nonces on all forms and AJAX requests
- **Capability Checks**: `current_user_can()` verification on all endpoints
- **Input Sanitization**: `sanitize_text_field()` and related functions
- **Output Escaping**: `esc_html()`, `esc_attr()`, `esc_url()` throughout
- **REST API Security**: `permission_callback` implementation

## Development

This plugin follows WordPress coding standards and best practices:

- **WPCS Compliant**: WordPress Coding Standards
- **Plugin Check Ready**: Passes WordPress.org Plugin Check
- **Security Focused**: Follows OWASP guidelines for WordPress
- **Translation Ready**: All strings use WordPress i18n functions

## WordPress.org Submission

This plugin is prepared for submission to the WordPress.org Plugin Directory:

- ✅ Follows WordPress Plugin Directory guidelines
- ✅ Security review completed
- ✅ WPCS and Plugin Check validation
- ✅ Comprehensive documentation

## License

This plugin is licensed under the GPL v2 or later.

```
andW Debug Viewer
Copyright (C) 2024 yasuo3o3

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

## Support

For issues and feature requests, please use the WordPress.org support forums once the plugin is published.

---

**日本語**

WordPress の `wp-content/debug.log` を管理画面から安全に閲覧・管理するためのプラグインです。本番環境での安全性を重視し、権限チェック・nonce検証・入出力エスケープを徹底実装しています。WordPress.org への申請準備完了済みです。