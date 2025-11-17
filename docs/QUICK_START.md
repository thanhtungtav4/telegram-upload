# 🚀 Quick Start: Download Counter Activation

## Instant Activation (Choose One Method)

### Method 1: Browser Migration (Recommended - Visual)
**Time**: 30 seconds
```
1. Open browser
2. Visit: https://nttung.dev/wp-content/plugins/telegram-upload/run-migration.php
3. View results (shows schema, statistics)
4. Click "Go to Plugin Admin"
5. ✅ Done!
```

### Method 2: Plugin Reactivation (Simple)
**Time**: 15 seconds
```
1. WordPress Admin → Plugins
2. Find "Telegram Upload"
3. Click "Deactivate"
4. Click "Activate"
5. ✅ Done! (auto-migration runs)
```

### Method 3: Manual SQL (Advanced)
**Time**: 10 seconds
```sql
-- Run in phpMyAdmin or database tool
ALTER TABLE wp_telegram_uploaded_files 
ADD COLUMN download_count INT(11) DEFAULT 0 NOT NULL;
```

## ✅ Verification

After activation, check:

### 1. Check Database
```sql
SHOW COLUMNS FROM wp_telegram_uploaded_files LIKE 'download_count';
```
Expected: 1 row returned

### 2. Check Admin Interface
1. Go to **WordPress Admin** → **Telegram Upload**
2. Look for new "Downloads" column
3. Should show 📥 with badge

### 3. Test Counter
1. Click "Download" on any file
2. Refresh page
3. Counter should increment (0 → 1)

## 📊 What You'll See

### Admin Table - Before
```
File | Size | Uploaded | Shortcode | Action
```

### Admin Table - After
```
File | Size | Uploaded | Downloads | Shortcode | Action
                         ↑ NEW COLUMN
```

### Badge Colors
- **Gray badge** (📥 0): New file, not downloaded yet
- **Green badge** (📥 125): Popular file with downloads

## 🔧 Troubleshooting

### "Column already exists" Error
✅ **This is good!** Migration already ran successfully.

### Counter Not Showing
1. Clear browser cache (Cmd+Shift+R)
2. Clear WordPress cache
3. Re-run migration script

### Permission Denied
- Must be logged in as admin
- Check database user permissions

## 📝 Quick Test

```bash
# Test download counter (paste in browser console after download)
fetch('/wp-admin/admin-ajax.php?action=telegram_download_file&file_id=1&nonce=...')
  .then(() => console.log('Download counted!'));
```

## 🎯 Next Steps

After activation:
1. ✅ Upload a test file
2. ✅ Download it
3. ✅ Refresh admin page
4. ✅ See counter increment
5. 🎉 Feature is working!

## 📚 Full Documentation

- **Complete Guide**: `docs/DOWNLOAD_COUNTER.md`
- **Summary**: `docs/DOWNLOAD_COUNTER_SUMMARY.md`
- **This File**: `docs/QUICK_START.md`

## ⏱️ Total Time Required

- **Migration**: 10-30 seconds
- **Verification**: 1 minute
- **Testing**: 2 minutes
- **Total**: ~3 minutes

## 🆘 Need Help?

Run the visual migration script for detailed feedback:
```
https://nttung.dev/wp-content/plugins/telegram-upload/run-migration.php
```

Shows:
- Current schema
- Migration status
- File statistics
- Error messages (if any)

---

**Status**: Ready to activate!  
**Difficulty**: Easy  
**Risk**: Zero (backward compatible)
