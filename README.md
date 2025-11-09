# Telegram File Upload - WordPress Plugin

![Version](https://img.shields.io/badge/version-2.6.0-blue.svg)
![WordPress](https://img.shields.io/badge/wordpress-5.0%2B-green.svg)
![PHP](https://img.shields.io/badge/php-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/license-GPL--2.0-red.svg)

A powerful WordPress plugin that allows you to upload files directly to Telegram and manage them through your WordPress admin panel. Features client-side upload to save up to **99.99% VPS bandwidth**.

## ✨ Features

### 🚀 Client-Side Upload (NEW in v2.6.0)
- **Direct browser to Telegram upload** - Bypasses VPS completely
- **Saves 99.99% bandwidth** - Only 2KB metadata vs 8MB+ file transfer
- **Progress tracking** - Real-time upload progress
- **Auto-retry** - Automatic retry on failure (up to 3 attempts)
- **Secure tokens** - One-time upload tokens with 5-minute expiration

### 📤 Upload Methods
- **Client-Side Upload** - Browser uploads directly to Telegram (recommended)
- **Server-Side Upload** - Traditional upload through VPS
- **Large File Support** - Files up to 50MB
- **File Types** - All file types supported

### 🔒 Access Control
- **Password Protection** - Secure files with passwords
- **Expiration Dates** - Auto-expire files after set date
- **Download Limits** - Limit number of downloads per file
- **Access Logging** - Track who accessed which files
- **IP Tracking** - Monitor download sources

### 📊 Analytics
- **Download Tracking** - Real-time download statistics
- **User Analytics** - Track downloads by user
- **Date Range Filtering** - Analyze downloads by period
- **Category Breakdown** - Statistics by file category
- **Export Data** - Export analytics to CSV

### 🎯 WordPress Integration
- **Shortcodes** - Embed download buttons anywhere
- **Admin Interface** - Beautiful Tailwind CSS admin panel
- **Categories** - Organize files by category
- **Search & Filter** - Find files quickly
- **Bulk Actions** - Manage multiple files at once

### 🛡️ Security
- ✅ WordPress authentication required
- ✅ One-time upload tokens (5-min expiration)
- ✅ Rate limiting (100 uploads/day/user)
- ✅ Nonce verification for all actions
- ✅ File verification via Telegram API
- ✅ Access control enforcement

## 📋 Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Telegram Bot Token
- Telegram Channel/Group ID

## 🔧 Installation

1. **Download the plugin**
   ```bash
   git clone https://github.com/username/telegram-upload.git
   ```

2. **Upload to WordPress**
   - Upload `telegram-upload` folder to `/wp-content/plugins/`
   - Or upload zip file through WordPress admin

3. **Activate the plugin**
   - Go to WordPress Admin → Plugins
   - Find "Telegram File Upload"
   - Click "Activate"

4. **Configure settings**
   - Go to WordPress Admin → Telegram Upload → Settings
   - Enter your Telegram Bot Token
   - Enter your Chat ID
   - Choose upload method (Client-side recommended)
   - Save settings

## ⚙️ Configuration

### Getting Telegram Bot Token

1. Open Telegram and search for [@BotFather](https://t.me/botfather)
2. Send `/newbot` command
3. Follow the instructions to create your bot
4. Copy the bot token (looks like: `123456789:ABCdefGHIjklMNOpqrsTUVwxyz`)

### Getting Chat ID

1. Add your bot to a channel or group
2. Send a message in the channel/group
3. Visit: `https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getUpdates`
4. Find your chat ID in the response (looks like: `-1001234567890`)

## 📖 Usage

### Upload Files

#### Method 1: Client-Side Upload (Recommended)
1. Go to **Telegram Upload** in WordPress admin
2. Select **Client-Side Upload** method in settings
3. Click **Upload File** button
4. Select file(s) from your computer
5. Files upload directly from browser to Telegram
6. **Result**: 99.99% bandwidth saved! ✨

#### Method 2: Server-Side Upload
1. Select **Server-Side Upload** method in settings
2. Upload file through WordPress
3. File transfers through VPS to Telegram

### Download Files

Files can be downloaded by:
- Clicking download button in admin panel
- Using shortcodes in posts/pages
- Direct URL with access control

### Shortcodes

**Single File Download**
```
[telegram_file id="123"]
```

**File List by Category**
```
[telegram_files category="documents"]
```

**Custom Button Text**
```
[telegram_file id="123" text="Download PDF"]
```

## 📊 Bandwidth Savings Comparison

### Traditional Server-Side Upload
```
User uploads 8MB file:
  Browser → VPS: 8 MB
  VPS → Telegram: 8 MB
  Total VPS bandwidth: 16 MB
```

### Client-Side Upload (v2.6.0)
```
User uploads 8MB file:
  Browser → Telegram: 8 MB (direct)
  Browser → VPS: 2 KB (metadata only)
  Total VPS bandwidth: 2 KB
  
  Savings: 99.99% 🎉
```

## 🎯 Performance

| File Size | Server-Side | Client-Side | Savings |
|-----------|-------------|-------------|---------|
| 1 MB | 2 MB | 2 KB | 99.90% |
| 8 MB | 16 MB | 2 KB | 99.99% |
| 50 MB | 100 MB | 2 KB | 99.99% |

## 🗂️ File Structure

```
telegram-upload/
├── telegram-upload.php              # Main plugin file
├── .gitignore                       # Git ignore rules
│
├── includes/                        # PHP classes
│   ├── FileSplitter.php            # Large file handling
│   ├── GeneralSettings.php         # Settings management
│   ├── TelegramAccessControl.php   # Access control
│   ├── TelegramAnalytics.php       # Analytics tracking
│   ├── TelegramAsyncUploader.php   # Background upload
│   ├── TelegramCategories.php      # Category management
│   ├── TelegramDownloadProxy.php   # Download proxy
│   ├── TelegramShortcodes.php      # WordPress shortcodes
│   ├── TelegramUploadAPI.php       # REST API
│   ├── TelegramUploader.php        # Main uploader
│   ├── TelegramUploaderCore.php    # Upload logic
│   ├── TelegramUploaderDisplay.php # Admin UI
│   └── TelegramUploadToken.php     # Token management
│
├── assets/                          # Frontend assets
│   ├── client-upload.js            # Client-side upload
│   ├── shortcode-scripts.js        # Shortcode JavaScript
│   └── shortcode-styles.css        # Shortcode styles
│
└── docs/                            # Documentation
    ├── API_DOCUMENTATION.md
    ├── QUICK_START.md
    └── ...
```

## 🔌 API Endpoints

### Request Upload Token
```
POST /wp-json/telegram/v1/request-upload
```

### Save Upload Metadata
```
POST /wp-json/telegram/v1/save-upload
```

### Check Upload Status
```
GET /wp-json/telegram/v1/upload-status/{token}
```

See [API_DOCUMENTATION.md](docs/API_DOCUMENTATION.md) for full API reference.

## 🐛 Troubleshooting

### Download returns "0"
- **Fix**: Update to v2.6.0+
- **Cause**: Action/nonce mismatch fixed in latest version

### Large files error "File not found"
- **Fix**: Update to v2.6.0+
- **Cause**: Telegram API limitation for files >20MB
- **Solution**: Plugin now uses direct URL fallback

### Client-side upload not working
- **Check**: Browser console for errors (F12)
- **Check**: WordPress REST API enabled
- **Check**: Bot token and chat ID correct
- **Check**: Browser supports Fetch API (modern browsers)

### Enable Debug Mode
```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Check logs at: `/wp-content/debug.log`

## 📚 Documentation

- [Quick Start Guide](QUICK_START.md) - Get started in 5 minutes
- [Client-Side Upload Guide](CLIENT_SIDE_UPLOAD_v2.6.0.md) - Complete guide to client-side upload
- [Access Control Guide](ACCESS_CONTROL_GUIDE.md) - Secure your files
- [Analytics Guide](ANALYTICS_GUIDE.md) - Track downloads
- [Shortcodes Guide](SHORTCODES_GUIDE.md) - Use shortcodes
- [API Documentation](docs/API_DOCUMENTATION.md) - REST API reference
- [Deployment Guide](DEPLOY.txt) - Deploy to VPS

## 🔄 Changelog

### Version 2.6.0 (November 9, 2025)
- ✨ **NEW**: Client-side upload feature (99.99% bandwidth savings)
- ✨ **NEW**: One-time upload tokens with expiration
- ✨ **NEW**: Upload progress tracking
- ✨ **NEW**: Auto-retry on failure
- 🐛 **FIX**: Download link returned "0"
- 🐛 **FIX**: Large files (>20MB) error "File not found"
- 🐛 **FIX**: WordPress nonce missing
- 🐛 **FIX**: Script loading issue
- 🐛 **FIX**: Settings class dependency
- 📚 Documentation cleanup (18 files removed)

See [RELEASE_NOTES_v2.6.0.md](RELEASE_NOTES_v2.6.0.md) for full changelog.

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📄 License

This project is licensed under the GPL-2.0 License - see the LICENSE file for details.

## 🙏 Acknowledgments

- [Telegram Bot API](https://core.telegram.org/bots/api) - Official API documentation
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/) - WordPress development
- [Tailwind CSS](https://tailwindcss.com/) - Admin interface styling

## 📞 Support

- **Documentation**: See [docs/](docs/) folder
- **Issues**: [GitHub Issues](https://github.com/username/telegram-upload/issues)
- **Email**: support@example.com

## 🌟 Show Your Support

Give a ⭐️ if this project helped you!

## 📈 Statistics

- **Lines of Code**: ~5,800
- **PHP Classes**: 13
- **JavaScript Files**: 3
- **Documentation Files**: 20+
- **Supported File Types**: All
- **Max File Size**: 50 MB
- **Bandwidth Savings**: Up to 99.99%

---

**Made with ❤️ for WordPress & Telegram**

*Last updated: November 9, 2025*
