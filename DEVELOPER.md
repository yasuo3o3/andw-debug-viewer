# Developer Documentation

## Development Environment Setup

### Prerequisites
- **WordPress**: 6.0 or higher
- **PHP**: 8.1 or higher
- **Composer**: For dependency management
- **Node.js**: For frontend tooling (optional)

### Installation
```bash
# Clone repository
git clone <repository-url> andw-debug-viewer
cd andw-debug-viewer

# Install dependencies
composer install

# If using Node.js tooling
npm install
```

## Code Quality & Standards

### WordPress Coding Standards (WPCS)

#### Setup
```bash
# Install WPCS via Composer (already included in composer.json)
composer install

# Configure PHPCS to use WordPress standards
vendor/bin/phpcs --config-set installed_paths vendor/wp-coding-standards/wpcs
```

#### Running PHPCS
```bash
# Check all PHP files
vendor/bin/phpcs

# Check specific file
vendor/bin/phpcs includes/class-andw-plugin.php

# Auto-fix issues where possible
vendor/bin/phpcbf

# Custom command (defined in composer.json)
composer phpcs
```

#### PHPCS Configuration
The project uses `.phpcs.xml.dist` with the following standards:
- WordPress
- WordPress-Extra
- WordPress-Docs

### Plugin Check

#### Installation
```bash
# Install Plugin Check plugin in your WordPress development environment
wp plugin install plugin-check --activate
```

#### Running Plugin Check
```bash
# Via WP-CLI
wp plugin-check andw-debug-viewer

# Via WordPress Admin
Navigate to Tools → Plugin Check → Select "andW Debug Viewer"
```

#### Required Checks
- [ ] Security checks (nonce, sanitization, escaping)
- [ ] Performance checks
- [ ] WordPress.org guidelines compliance
- [ ] Translation readiness

## Testing

### Manual Testing Checklist

#### Basic Functionality
- [ ] Plugin activation/deactivation
- [ ] Admin menu appears correctly
- [ ] Log viewer displays properly
- [ ] REST API endpoints respond correctly
- [ ] Nonce verification works
- [ ] Capability checks function

#### Environment Testing
- [ ] Production environment (read-only default)
- [ ] Development environment (full access)
- [ ] Multisite network admin
- [ ] Multisite individual sites

#### Security Testing
- [ ] Non-admin users cannot access functionality
- [ ] CSRF attacks prevented by nonces
- [ ] Input sanitization prevents XSS
- [ ] SQL injection prevention (via $wpdb->prepare)

### Automated Testing (Future Implementation)

#### PHPUnit Setup (Planned)
```bash
# Install PHPUnit
composer require --dev phpunit/phpunit

# Run tests
vendor/bin/phpunit
```

#### Test Categories
- Unit tests for individual classes/methods
- Integration tests for WordPress hooks/filters
- REST API endpoint tests
- Security validation tests

## Build Process

### Development Build
```bash
# Syntax check all PHP files
find . -name "*.php" -exec php -l {} \;

# Run PHPCS
composer phpcs

# Run Plugin Check
wp plugin-check andw-debug-viewer
```

### Release Build
```bash
# 1. Update version numbers (use VERSION-UP.md process)
# 2. Run full code quality checks
composer phpcs
wp plugin-check andw-debug-viewer

# 3. Create distribution ZIP
git archive --format=zip --output=../andw-debug-viewer.zip --prefix=andw-debug-viewer/ HEAD

# 4. Verify ZIP contents
unzip -l ../andw-debug-viewer.zip
```

### Distribution ZIP Verification
Ensure the ZIP includes:
- ✅ Main plugin file (`andw-debug-viewer.php`)
- ✅ All includes/ directory files
- ✅ Assets (CSS/JS) for functionality
- ✅ Language files
- ✅ readme.txt
- ❌ Development files (.git, .gitignore, composer.json, etc.)

## Version Management

### Version Update Process
Follow `/docs/VERSION-UP.md` for comprehensive version updates:

1. **Search & Replace**: Update version in all relevant files
2. **Update Files**:
   - `andw-debug-viewer.php` (header + constant)
   - `readme.txt` (Stable tag + Changelog)
   - `languages/*.pot` (Project-Id-Version)
3. **Documentation**: Update CHANGELOG.txt with new version

### Release Checklist
- [ ] Version numbers consistent across all files
- [ ] CHANGELOG.txt updated with release notes
- [ ] readme.txt changelog updated
- [ ] Language files updated
- [ ] All tests passing
- [ ] Plugin Check validation complete
- [ ] Distribution ZIP verified

## Code Architecture

### Core Classes

#### `Andw_Plugin`
- Main plugin bootstrap
- Singleton pattern implementation
- Hook registration and initialization

#### `Andw_Settings`
- Settings management and defaults
- Option persistence and retrieval
- Environment-specific configurations

#### `Andw_Log_Reader`
- Debug log file operations
- Line/minute-based filtering
- File metadata retrieval

#### `Andw_Rest_Controller`
- REST API endpoint registration
- Permission callbacks implementation
- Request/response handling

#### `Andw_Admin`
- Admin interface rendering
- Form handling and processing
- Asset enqueueing

### Security Implementation

#### Input Validation
```php
// Sanitize text inputs
$value = sanitize_text_field( wp_unslash( $_POST['field'] ) );

// Validate nonces
wp_verify_nonce( $nonce, 'action_name' );

// Check capabilities
current_user_can( 'manage_options' );
```

#### Output Escaping
```php
// HTML content
echo esc_html( $text );

// HTML attributes
echo esc_attr( $attribute );

// URLs
echo esc_url( $url );

// Complex HTML (trusted content only)
echo wp_kses_post( $html );
```

## CI/CD Implementation (Future)

### GitHub Actions Workflow (Planned)

#### Basic Workflow
```yaml
name: WordPress Plugin CI
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.1
      - name: Install dependencies
        run: composer install
      - name: Run PHPCS
        run: composer phpcs
      - name: Run Plugin Check
        run: wp plugin-check andw-debug-viewer
```

#### Integration Testing
- WordPress compatibility testing (multiple versions)
- PHP compatibility testing (8.1, 8.2, 8.3)
- Multisite testing
- Plugin conflict testing

### Deployment Automation (Future)

#### WordPress.org SVN Integration
```bash
# Automated SVN deployment script
scripts/deploy-to-svn.sh
```

#### Release Automation
- Automated version bumping
- Distribution ZIP creation
- GitHub release creation
- WordPress.org submission

## WordPress.org Submission

### Pre-submission Checklist
- [ ] Plugin follows WordPress Plugin Directory guidelines
- [ ] Security review completed (no vulnerabilities)
- [ ] Code follows WordPress Coding Standards
- [ ] Plugin Check validation passes
- [ ] Documentation complete (readme.txt)
- [ ] Translation ready (Text Domain properly set)
- [ ] GPL-compatible licensing
- [ ] No premium functionality restrictions

### Submission Process
1. **Review Documentation**: Ensure readme.txt follows WordPress.org format
2. **Final Testing**: Complete testing across environments
3. **ZIP Creation**: Create clean distribution ZIP
4. **Submit**: Upload to WordPress.org Plugin Directory

### Post-approval Maintenance
- Regular WordPress version compatibility testing
- Security updates as needed
- Community support via WordPress.org forums
- Feature development based on user feedback

---

**日本語開発ガイド**

このプラグインはWordPress.org申請準備完了済みです。開発時はWPCS準拠・Plugin Check通過・セキュリティベストプラクティス遵守を徹底してください。CI/CD導入により品質管理の自動化を予定しています。