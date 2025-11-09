# Download Counter Feature

## Overview
The Telegram Upload Plugin now tracks how many times each file has been downloaded. This helps you monitor file popularity and usage statistics.

## Features

### 📊 Download Tracking
- **Automatic Counter**: Every download increments the counter
- **Real-time Updates**: Counter updates immediately on each download
- **Visual Indicators**: Color-coded badges show download activity
  - 🟢 **Green badge**: Files with downloads (active)
  - ⚪ **Gray badge**: Files with zero downloads (new)

### 📈 Display Format
The download count appears in the admin file list as a badge:
```
📥 125  (popular file)
📥 5    (moderate activity)
📥 0    (not yet downloaded)
```

## Database Schema

### New Column
```sql
download_count INT(11) DEFAULT 0 NOT NULL
```

### Migration
For existing installations, the plugin automatically adds the `download_count` column when you:
1. Reactivate the plugin
2. Run the activation script: `./activate.sh`
3. Or manually visit: `/wp-content/plugins/telegram-upload/migrate-download-count.php`

## How It Works

### 1. Download Process
```php
// When a file is downloaded via TelegramDownloadProxy
$wpdb->query($wpdb->prepare(
    "UPDATE {$table} SET download_count = download_count + 1 WHERE id = %d",
    $file_id
));
```

### 2. Cache Invalidation
After incrementing the counter, the cache is invalidated to show fresh data:
```php
wp_cache_delete($cache_key);
```

### 3. Display in Admin
The counter appears with visual styling:
```php
// Green badge for files with downloads
<span class="bg-green-100 text-green-800">📥 125</span>

// Gray badge for new files
<span class="bg-gray-100 text-gray-600">📥 0</span>
```

## Usage Examples

### Viewing Download Stats
1. Go to **WordPress Admin** → **Telegram Upload**
2. Check the **Downloads** column in the file list
3. Files are sorted by upload date, but you can see popularity at a glance

### Sorting by Popularity (Future Enhancement)
You can manually query to find most popular files:
```sql
SELECT file_name, download_count 
FROM wp_telegram_uploaded_files 
ORDER BY download_count DESC 
LIMIT 10;
```

## Technical Details

### Counter Increment Location
File: `includes/TelegramDownloadProxy.php`
Method: `serve_file()`
Lines: After file validation, before streaming

### Counter Display Location
File: `includes/TelegramUploaderDisplay.php`
Method: `render_uploaded_files()`
Column: 4th column (between "Uploaded" and "Shortcode")

### Fallback Handling
For older database records without the column:
```php
$download_count = isset($entry->download_count) ? intval($entry->download_count) : 0;
```

## Performance Considerations

### Caching Strategy
- File metadata cached for 1 hour
- Cache invalidated on counter update
- Minimal performance impact (single UPDATE query)

### Database Impact
- Lightweight INT column
- Indexed by primary key (id)
- No additional indexes needed

## Migration Guide

### For Fresh Installations
✅ No action needed - column created automatically

### For Existing Installations

#### Option 1: Automatic (Recommended)
```bash
cd /Volumes/Manager\ Data/Project/nttung.dev/wp-content/plugins/telegram-upload/
./activate.sh
```

#### Option 2: WordPress Admin
1. Go to **Plugins**
2. **Deactivate** Telegram Upload
3. **Activate** Telegram Upload
4. Migration runs automatically

#### Option 3: Manual SQL
```sql
ALTER TABLE wp_telegram_uploaded_files 
ADD COLUMN download_count INT(11) DEFAULT 0 NOT NULL;
```

#### Option 4: Browser Script
Visit in browser (admin only):
```
https://nttung.dev/wp-content/plugins/telegram-upload/migrate-download-count.php
```

## Security

### Download Validation
- Nonce verification before counter increment
- Prepared statements prevent SQL injection
- File existence validated before counting

### Admin Only Migration
```php
if (!current_user_can('manage_options')) {
    wp_die('Access denied.');
}
```

## Future Enhancements

### Potential Features
- 📊 **Download Analytics Dashboard**: Charts and graphs
- 📈 **Download Reports**: Export CSV of statistics
- 🔔 **Popularity Alerts**: Notify on viral files
- 🎯 **Sorting Options**: Sort by downloads in admin
- 📅 **Download History**: Track download timestamps
- 👥 **User Tracking**: Who downloaded what (privacy considerations)

### Shortcode Integration
Show download count in shortcodes:
```php
[telegram_file id="123" show_downloads="yes"]
```

Would display:
```
📥 Download File (125 downloads)
```

## Troubleshooting

### Counter Not Incrementing
**Check 1**: Verify column exists
```sql
SHOW COLUMNS FROM wp_telegram_uploaded_files LIKE 'download_count';
```

**Check 2**: Run migration script
```bash
./activate.sh
```

**Check 3**: Check for errors
Enable WordPress debug mode and check logs

### Counter Shows Wrong Number
**Solution**: Clear cache
```php
wp_cache_flush();
```

### Migration Failed
**Error**: "Column already exists"
- ✅ This is normal - migration already ran successfully

**Error**: "Table doesn't exist"
- ❌ Plugin not properly installed
- Solution: Deactivate and reactivate plugin

## Version History

### Version 2.1 (Current)
- ✅ Added download counter feature
- ✅ Automatic migration on activation
- ✅ Visual badges in admin interface
- ✅ Cache invalidation on update

### Version 2.0
- Download proxy with token hiding
- Shortcode system
- Performance optimizations

## Support

If you encounter issues:
1. Run `./activate.sh` to re-migrate
2. Check WordPress error logs
3. Verify database permissions
4. Test with a fresh file upload

## Credits

**Feature**: Download Counter  
**Version**: 2.1  
**Date**: November 8, 2025  
**Author**: Telegram Upload Plugin Team
