=== Nttung File Upload for Telegram ===
Contributors: nttungdev
Tags: telegram, upload, file-manager, bot, download
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 2.6.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Upload files directly to Telegram from WordPress with client-side upload (99.99% bandwidth savings), access control, and analytics.

== Description ==

**Nttung File Upload for Telegram** is a powerful WordPress plugin that allows you to upload files directly to Telegram and manage them through your WordPress admin panel.

= 🚀 Key Features =

* **Client-Side Upload** - Browser uploads directly to Telegram, saving 99.99% VPS bandwidth
* **Server-Side Upload** - Traditional upload method also available  
* **Large File Support** - Handle files up to 50MB
* **Access Control** - Password protection, expiration dates, download limits
* **Download Analytics** - Track downloads with detailed statistics
* **File Categories** - Organize files by category
* **WordPress Shortcodes** - Embed download buttons anywhere
* **Beautiful Admin Interface** - Modern Tailwind CSS design

= 💡 Why Client-Side Upload? =

Traditional server-side upload uses double bandwidth:
* Browser → VPS: 8 MB
* VPS → Telegram: 8 MB  
* **Total: 16 MB**

Client-side upload saves bandwidth:
* Browser → Telegram: 8 MB (direct)
* Browser → VPS: 2 KB (metadata only)
* **Total: 2 KB** ✨

**Result: 99.99% bandwidth savings!**

= 🔒 Security Features =

* WordPress authentication required
* One-time upload tokens (5-min expiration)
* Rate limiting (100 uploads/day/user)
* Nonce verification for all actions
* File verification via Telegram API
* Access control enforcement

= 📊 Analytics =

* Real-time download tracking
* User statistics
* Date range filtering
* Category breakdown
* Export to CSV

= 🎯 Perfect For =

* File sharing services
* Digital product delivery
* Private file distribution
* Team collaboration
* Content creators

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Go to Plugins → Add New
3. Search for "Telegram File Upload"
4. Click "Install Now" then "Activate"

= Manual Installation =

1. Download the plugin zip file
2. Go to Plugins → Add New → Upload Plugin
3. Choose the zip file and click "Install Now"
4. Activate the plugin

= Configuration =

1. Go to WordPress Admin → Telegram Upload → Settings
2. Get your Telegram Bot Token:
   * Open Telegram and search for @BotFather
   * Send `/newbot` command
   * Follow instructions and copy the token
3. Get your Chat ID:
   * Add your bot to a channel/group
   * Send a message
   * Visit: `https://api.telegram.org/bot<YOUR_TOKEN>/getUpdates`
   * Find your chat ID in the response
4. Enter Bot Token and Chat ID in settings
5. Choose upload method (Client-side recommended)
6. Save settings

== Frequently Asked Questions ==

= Do I need a Telegram account? =

Yes, you need a Telegram Bot Token and Channel/Group ID. See installation instructions for setup.

= Is client-side upload secure? =

Yes! Client-side upload uses:
* One-time tokens with 5-minute expiration
* WordPress authentication
* Rate limiting (100 uploads/day/user)
* File verification via Telegram API

= What file types are supported? =

All file types are supported, up to 50MB per file.

= Does this work with large files? =

Yes! Files up to 50MB are fully supported. Files >20MB use direct Telegram URLs for download.

= Can I password protect files? =

Yes! The plugin includes comprehensive access control:
* Password protection
* Expiration dates
* Download limits
* Access logging

= How do I embed download buttons? =

Use shortcodes:

Single file: `[telegram_file id="123"]`
By category: `[telegram_files category="documents"]`
Custom text: `[telegram_file id="123" text="Download PDF"]`

= Is this plugin free? =

Yes! This plugin is 100% free and open source (GPL v2).

= Does it track users? =

No external tracking. Analytics are stored locally in your WordPress database only.

== Screenshots ==

1. Admin interface with file list
2. Upload form with client-side upload option
3. Access control settings
4. Analytics dashboard
5. Settings page

== Changelog ==

= 2.6.2 - November 17, 2025 =
* **FIX:** Bundled Tailwind CSS v2.2.19 and Chart.js v3.9.1 locally (removed all CDN calls)
* **FIX:** Extracted inline scripts to separate files (admin-settings.js, analytics-dashboard.js)
* **FIX:** Extracted inline styles to separate files (analytics-dashboard.css)
* **IMPROVED:** Proper wp_enqueue_script/style usage throughout
* **IMPROVED:** Better code organization and maintainability

= 2.6.1 - November 17, 2025 =
* **FIX:** Plugin name changed to comply with WordPress.org trademark guidelines
* **FIX:** Text domain updated to match plugin slug
* **FIX:** Repository URL updated to correct GitHub URL
* **FIX:** Input sanitization for all $_SERVER, $_GET, $_POST variables
* **FIX:** Output escaping using wp_json_encode and esc_* functions
* **FIX:** Nonce verification with proper sanitization
* **FIX:** Permission callbacks for all REST API endpoints
* **FIX:** Tailwind CSS and Chart.js now bundled locally (removed CDN)
* **FIX:** All inline scripts/styles now use wp_add_inline_script/style
* **IMPROVED:** Security hardening throughout the plugin
* **IMPROVED:** Compliance with WordPress coding standards

= 2.6.0 - November 9, 2025 =
* **NEW:** Client-side upload feature (99.99% bandwidth savings)
* **NEW:** One-time upload tokens with 5-minute expiration
* **NEW:** Upload progress tracking
* **NEW:** Auto-retry on upload failure (up to 3 attempts)
* **FIX:** Download link returned "0" error
* **FIX:** Large files (>20MB) "File not found" error
* **FIX:** WordPress nonce missing in REST API calls
* **FIX:** JavaScript not loading on upload page
* **FIX:** Settings class dependency during activation
* **IMPROVED:** Better error messages from Telegram API
* **IMPROVED:** Documentation cleanup (18 files removed)
* **IMPROVED:** Code quality (zero PHP/JavaScript errors)

= 2.5.0 =
* Added access control features
* Added analytics tracking
* Added file categories
* Bug fixes and improvements

= 2.4.0 =
* Added shortcode support
* Improved admin interface
* Performance improvements

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.6.2 =
WordPress.org compliance update. Bundled all external resources locally and improved script enqueuing. All users should update.

= 2.6.1 =
Security and compliance update. Fixes input sanitization, output escaping, and WordPress.org plugin directory requirements. Recommended update for all users.

= 2.6.0 =
Major update with client-side upload feature that saves 99.99% VPS bandwidth. Several critical bug fixes included. Recommended update for all users.

== Additional Info ==

= Support =

* Documentation: See docs/ folder in plugin directory or visit https://nttung.dev
* GitHub: https://github.com/nttungdev/nttung-file-upload-for-telegram
* Issues: Report bugs on GitHub Issues page

= Requirements =

* WordPress 5.0 or higher
* PHP 7.4 or higher
* Telegram Bot Token
* Telegram Channel/Group ID

= Privacy Policy =

This plugin does not track users or send data to external services except:
* Telegram API calls (when you configure your own bot token)
* All analytics data is stored locally in WordPress database

== Credits ==

* Built for WordPress
* Uses Telegram Bot API
* Styled with Tailwind CSS
