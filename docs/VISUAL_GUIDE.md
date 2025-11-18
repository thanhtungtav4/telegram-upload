# 📸 Visual Guide - REST API Setup

## Step-by-Step Setup (5 Minutes)

### Step 1: Access Admin Panel
```
WordPress Admin Dashboard
    ↓
Sidebar → "TE File Upload"
    ↓
Admin Page Opens
```

**You'll see**:
```
┌─────────────────────────────────────────────────┐
│ Telegram File Upload                           │
├─────────────────────────────────────────────────┤
│ 🔑 API Access                    [Show Docs]   │
│                                                 │
│ Generate an API key to upload files            │
│ programmatically via REST API.                 │
│                                                 │
│ [Generate API Key]  ← Click this button        │
└─────────────────────────────────────────────────┘
```

---

### Step 2: Generate API Key

**Click "Generate API Key"**

Loading state:
```
[Generating...]  (disabled, grayed out)
```

Page reloads automatically...

**After generation**:
```
┌─────────────────────────────────────────────────┐
│ 🔑 API Access                    [Show Docs]   │
│                                                 │
│ Your API Key:                                   │
│ ┌─────────────────────────────────────────────┐ │
│ │ a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0... │ │
│ └─────────────────────────────────────────────┘ │
│ [Copy] [Regenerate]                             │
└─────────────────────────────────────────────────┘
```

---

### Step 3: Copy API Key

**Click "Copy" button**

Visual feedback:
```
Before:  [Copy]         (green button)
After:   [Copied!]      (blue button, 2 seconds)
Then:    [Copy]         (back to green)
```

**Your clipboard now has**:
```
a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2
```

---

### Step 4: View API Docs

**Click "Show Docs"**

Expands to show:
```
┌─────────────────────────────────────────────────┐
│ 🔑 API Access                    [Hide Docs]   │
│                                                 │
│ Your API Key:                                   │
│ [a1b2c3...] [Copy] [Regenerate]                │
│                                                 │
│ ┌─────────────────────────────────────────────┐ │
│ │ Quick Start:                                │ │
│ │                                             │ │
│ │ curl -X POST https://nttung.dev/wp-json/   │ │
│ │   telegram-upload/v1/upload \              │ │
│ │   -H "X-API-Key: a1b2c3..." \              │ │
│ │   -F "file=@document.pdf"                  │ │
│ │                                             │ │
│ │ 📖 View Full API Documentation              │ │
│ └─────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────┘
```

---

### Step 5: Test the API

**Open Terminal**:
```bash
curl -X POST https://nttung.dev/wp-json/telegram-upload/v1/upload \
  -H "X-API-Key: a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6..." \
  -F "file=@test.pdf"
```

**Expected Response**:
```json
{
  "success": true,
  "message": "File uploaded successfully",
  "file": {
    "id": 123,
    "name": "test.pdf",
    "size": 2621440,
    "size_formatted": "2.5 MB",
    "uploaded_at": "2025-11-08 10:30:00",
    "download_url": "https://nttung.dev/...",
    "download_count": 0,
    "shortcode": "[telegram_file id=\"123\"]"
  }
}
```

---

## Visual Response Flow

### Success Response (Green)
```
┌─────────────────────────────────────────────────┐
│ ✅ SUCCESS                                      │
├─────────────────────────────────────────────────┤
│ {                                               │
│   "success": true,                              │
│   "message": "File uploaded successfully",      │
│   "file": {                                     │
│     "id": 123,                                  │
│     "name": "document.pdf",                     │
│     "download_url": "https://...",              │
│     "shortcode": "[telegram_file id=\"123\"]"   │
│   }                                             │
│ }                                               │
└─────────────────────────────────────────────────┘
```

### Error Response (Red)
```
┌─────────────────────────────────────────────────┐
│ ❌ ERROR                                        │
├─────────────────────────────────────────────────┤
│ {                                               │
│   "code": "invalid_api_key",                    │
│   "message": "Invalid API key.",                │
│   "data": {                                     │
│     "status": 403                               │
│   }                                             │
│ }                                               │
└─────────────────────────────────────────────────┘
```

---

## API Endpoints Visual Map

```
https://nttung.dev/wp-json/telegram-upload/v1/
    │
    ├─ /upload          POST   📤 Upload file
    │   ├─ multipart/form-data (file)
    │   └─ application/x-www-form-urlencoded (file_url)
    │
    ├─ /files           GET    📁 List all files
    │   ├─ ?limit=50
    │   ├─ ?offset=0
    │   └─ ?search=keyword
    │
    ├─ /files/{id}      GET    📄 Get single file
    │   └─ Returns file details
    │
    ├─ /files/{id}      DELETE 🗑️ Delete file (admin)
    │   └─ Removes from database
    │
    ├─ /stats           GET    📊 Statistics
    │   ├─ Total files
    │   ├─ Total size
    │   ├─ Downloads
    │   └─ Popular files
    │
    └─ /generate-key    POST   🔑 Generate API key (admin)
        └─ Returns new 64-char key
```

---

## Authentication Visual

### ✅ Valid Request
```
Client Request
    ↓
Header: X-API-Key: a1b2c3d4...
    ↓
Server: Check API key in database
    ↓
Match found ✓
    ↓
Permission granted
    ↓
Process request
    ↓
Return response (200/201)
```

### ❌ Invalid Request
```
Client Request
    ↓
Header: X-API-Key: wrong-key
    ↓
Server: Check API key in database
    ↓
No match found ✗
    ↓
Permission denied
    ↓
Return error (403)
```

### ❌ Missing API Key
```
Client Request
    ↓
No X-API-Key header
    ↓
Server: API key required
    ↓
Return error (401)
```

---

## Upload Flow Visualization

### Multipart Upload
```
┌──────────┐     ┌──────────┐     ┌──────────┐     ┌──────────┐
│  Client  │────>│ WordPress│────>│  Plugin  │────>│ Telegram │
│          │ POST│   REST   │     │   Core   │ API │   Bot    │
│ (Browser)│     │   API    │     │          │     │          │
└──────────┘     └──────────┘     └──────────┘     └──────────┘
     │                │                 │                 │
     │ file binary    │ validate        │ upload          │
     │ + API key      │ auth            │ file            │
     │                │                 │                 │
     │<───────────────┴─────────────────┴─────────────────┘
     │              JSON response
     │              (file details)
```

### URL Upload
```
┌──────────┐     ┌──────────┐     ┌──────────┐     ┌──────────┐
│  Client  │────>│ WordPress│────>│  Plugin  │────>│ Remote   │
│          │ POST│   REST   │     │   Core   │ GET │  Server  │
│  (App)   │     │   API    │     │          │     │          │
└──────────┘     └──────────┘     └──────────┘     └──────────┘
     │                │                 │                 │
     │ file_url       │ validate        │ download        │
     │ + API key      │ auth            │ file            │
     │                │                 │                 │
     │                │                 ↓                 │
     │                │          Save to temp             │
     │                │                 │                 │
     │                │                 ↓                 │
     │                │          Upload to Telegram      │
     │                │                 │                 │
     │<───────────────┴─────────────────┴─────────────────┘
     │              JSON response
```

---

## Admin Interface Visual

### Before API Key Generation
```
┌─────────────────────────────────────────────────┐
│ Telegram File Upload                      [×]  │
├─────────────────────────────────────────────────┤
│                                                 │
│ ┌─────────────────────────────────────────────┐ │
│ │ 🔑 API Access              [Show Docs]     │ │
│ │                                             │ │
│ │ Generate an API key to upload files        │ │
│ │ programmatically via REST API.             │ │
│ │                                             │ │
│ │ [Generate API Key]                         │ │
│ └─────────────────────────────────────────────┘ │
│                                                 │
│ Upload Your Documents                           │
│ ┌─────────────────────────────────────────────┐ │
│ │     📤                                      │ │
│ │  Click to upload or drag and drop          │ │
│ └─────────────────────────────────────────────┘ │
│                                                 │
│ [Upload]                                        │
│                                                 │
│ 📁 Uploaded Files                  🔍 [Search] │
│ ┌─────────────────────────────────────────────┐ │
│ │ File | Size | Date | Downloads | Action    │ │
│ │ ...                                         │ │
│ └─────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────┘
```

### After API Key Generation
```
┌─────────────────────────────────────────────────┐
│ Telegram File Upload                      [×]  │
├─────────────────────────────────────────────────┤
│                                                 │
│ ┌─────────────────────────────────────────────┐ │
│ │ 🔑 API Access              [Show Docs]     │ │
│ │                                             │ │
│ │ Your API Key:                               │ │
│ │ ┌─────────────────────────────────────────┐ │ │
│ │ │ a1b2c3d4e5f6g7h8...                    │ │ │
│ │ └─────────────────────────────────────────┘ │ │
│ │ [Copy] [Regenerate]                        │ │
│ │                                             │ │
│ │ ┌── Quick Start ──────────────────────────┐ │ │
│ │ │ curl -X POST https://nttung.dev/...    │ │ │
│ │ │   -H "X-API-Key: a1b2c3..." \          │ │ │
│ │ │   -F "file=@document.pdf"              │ │ │
│ │ │                                         │ │ │
│ │ │ 📖 View Full API Documentation          │ │ │
│ │ └─────────────────────────────────────────┘ │ │
│ └─────────────────────────────────────────────┘ │
│                                                 │
│ ... (rest of upload interface)                 │
└─────────────────────────────────────────────────┘
```

---

## Testing with Different Tools

### cURL (Terminal)
```bash
$ curl -X POST https://nttung.dev/wp-json/telegram-upload/v1/upload \
>   -H "X-API-Key: a1b2c3..." \
>   -F "file=@test.pdf"

{"success":true,"message":"File uploaded successfully","file":{...}}
```

### Postman (GUI)
```
POST https://nttung.dev/wp-json/telegram-upload/v1/upload

Headers:
  X-API-Key: a1b2c3d4e5f6...

Body (form-data):
  file: [Select file]

[Send Button]

Response (201 Created):
{
  "success": true,
  "file": {...}
}
```

### JavaScript (Browser Console)
```javascript
const formData = new FormData();
formData.append('file', fileInput.files[0]);

fetch('/wp-json/telegram-upload/v1/upload', {
  method: 'POST',
  headers: { 'X-API-Key': 'a1b2c3...' },
  body: formData
})
.then(res => res.json())
.then(data => console.log(data));

// Output: {success: true, file: {...}}
```

---

## Error Scenarios

### Scenario 1: No API Key
```
Request:
  curl https://nttung.dev/wp-json/telegram-upload/v1/upload

Response (401):
  {
    "code": "no_api_key",
    "message": "API key required..."
  }
```

### Scenario 2: Invalid API Key
```
Request:
  curl -H "X-API-Key: wrong-key" ...

Response (403):
  {
    "code": "invalid_api_key",
    "message": "Invalid API key."
  }
```

### Scenario 3: No File
```
Request:
  curl -X POST ... (no file attached)

Response (400):
  {
    "code": "no_file",
    "message": "No file provided..."
  }
```

---

## Success Indicators

### Visual Cues in Admin
```
✅ Green "Copied!" button    → API key copied
✅ Blue banner              → API key section
✅ Code snippet visible     → Ready to use
✅ File uploaded            → Shows in list
✅ Download counter         → Increments
```

### API Response Indicators
```
✅ HTTP 201                 → File uploaded
✅ "success": true          → Operation OK
✅ "file" object present    → File details
✅ "download_url" exists    → Ready to download
```

---

**Status**: Visual guide complete! 📸  
**Next**: Try it yourself in the admin panel!
