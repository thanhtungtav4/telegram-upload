# 🚀 API Quick Start Guide

## 5-Minute Setup

### Step 1: Generate API Key (30 seconds)

**Option A: Using cURL (if logged in via cookies)**
```bash
curl -X POST https://nttung.dev/wp-json/telegram-upload/v1/generate-key \
  --cookie "wordpress_logged_in_xxx=your-cookie-here"
```

**Option B: Using WordPress Admin UI**
1. Go to WordPress Admin → Telegram Upload
2. Look for "Generate API Key" button
3. Copy the generated key

**Save your API key**:
```
a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2
```

---

### Step 2: Test Upload (1 minute)

**Upload a test file**:
```bash
curl -X POST https://nttung.dev/wp-json/telegram-upload/v1/upload \
  -H "X-API-Key: YOUR-API-KEY-HERE" \
  -F "file=@test.pdf"
```

**Expected response**:
```json
{
  "success": true,
  "message": "File uploaded successfully",
  "file": {
    "id": 123,
    "name": "test.pdf",
    "download_url": "https://...",
    "shortcode": "[telegram_file id=\"123\"]"
  }
}
```

---

### Step 3: List Files (30 seconds)

```bash
curl "https://nttung.dev/wp-json/telegram-upload/v1/files?limit=10" \
  -H "X-API-Key: YOUR-API-KEY-HERE"
```

---

## Common Use Cases

### Upload from Local File (Bash)

```bash
#!/bin/bash
API_KEY="your-api-key"
FILE="/path/to/document.pdf"

curl -X POST https://nttung.dev/wp-json/telegram-upload/v1/upload \
  -H "X-API-Key: $API_KEY" \
  -F "file=@$FILE"
```

### Upload from URL (Python)

```python
import requests

url = "https://nttung.dev/wp-json/telegram-upload/v1/upload"
headers = {"X-API-Key": "your-api-key"}
data = {"file_url": "https://example.com/document.pdf"}

response = requests.post(url, headers=headers, data=data)
print(response.json())
```

### Batch Upload (Python)

```python
import os
import requests

API_KEY = "your-api-key"
BASE_URL = "https://nttung.dev/wp-json/telegram-upload/v1"

def upload_file(filepath):
    files = {"file": open(filepath, "rb")}
    headers = {"X-API-Key": API_KEY}
    response = requests.post(f"{BASE_URL}/upload", headers=headers, files=files)
    return response.json()

# Upload all PDFs in directory
for filename in os.listdir("/path/to/pdfs"):
    if filename.endswith(".pdf"):
        result = upload_file(f"/path/to/pdfs/{filename}")
        print(f"Uploaded: {result['file']['name']}")
```

### JavaScript/Node.js

```javascript
const axios = require('axios');
const FormData = require('form-data');
const fs = require('fs');

const API_KEY = 'your-api-key';

async function uploadFile(filePath) {
  const form = new FormData();
  form.append('file', fs.createReadStream(filePath));

  const response = await axios.post(
    'https://nttung.dev/wp-json/telegram-upload/v1/upload',
    form,
    {
      headers: {
        ...form.getHeaders(),
        'X-API-Key': API_KEY
      }
    }
  );

  return response.data;
}

// Usage
uploadFile('./document.pdf')
  .then(result => console.log('Uploaded:', result.file.name))
  .catch(err => console.error('Error:', err));
```

---

## All Endpoints at a Glance

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/generate-key` | Generate API key (admin only) |
| POST | `/upload` | Upload file (multipart or URL) |
| GET | `/files` | List all files (with pagination) |
| GET | `/files/{id}` | Get specific file details |
| DELETE | `/files/{id}` | Delete file (admin only) |
| GET | `/stats` | Get upload/download statistics |

---

## Authentication Methods

### Method 1: Header (Recommended)
```bash
-H "X-API-Key: your-api-key"
```

### Method 2: Query Parameter
```bash
?api_key=your-api-key
```

### Method 3: WordPress Session
If logged in with `upload_files` capability, no API key needed.

---

## Response Format

### Success Response
```json
{
  "success": true,
  "message": "Operation successful",
  "file": { /* file data */ }
}
```

### Error Response
```json
{
  "code": "error_code",
  "message": "Error description",
  "data": {
    "status": 400
  }
}
```

---

## Testing Tools

### cURL (Command Line)
```bash
curl -X POST https://nttung.dev/wp-json/telegram-upload/v1/upload \
  -H "X-API-Key: your-key" \
  -F "file=@test.pdf" \
  | jq .
```

### Postman
1. Create new POST request
2. URL: `https://nttung.dev/wp-json/telegram-upload/v1/upload`
3. Headers: `X-API-Key: your-key`
4. Body → form-data → file
5. Send

### HTTPie (Simpler than cURL)
```bash
http POST https://nttung.dev/wp-json/telegram-upload/v1/upload \
  X-API-Key:your-key \
  file@test.pdf
```

---

## Quick Troubleshooting

| Error | Solution |
|-------|----------|
| `no_api_key` | Add `X-API-Key` header or `api_key` parameter |
| `invalid_api_key` | Regenerate API key via `/generate-key` |
| `file_too_large` | File exceeds 50MB limit |
| `upload_failed` | Check Telegram token configuration |
| `404` | Enable permalinks, flush rewrite rules |

---

## Next Steps

1. ✅ Generate API key
2. ✅ Test with cURL
3. 📖 Read full docs: `/docs/API_DOCUMENTATION.md`
4. 🔨 Build your integration
5. 📊 Monitor via `/stats` endpoint

---

## Security Reminder

⚠️ **Keep your API key secret!**
- Don't commit to Git
- Use environment variables
- Rotate periodically
- Monitor usage via stats

---

## Example Projects

### Automated Backup Script
```bash
#!/bin/bash
# Backup and upload to Telegram daily

API_KEY="your-api-key"
BACKUP_FILE="backup-$(date +%Y%m%d).tar.gz"

# Create backup
tar -czf $BACKUP_FILE /important/data

# Upload to Telegram
curl -X POST https://nttung.dev/wp-json/telegram-upload/v1/upload \
  -H "X-API-Key: $API_KEY" \
  -F "file=@$BACKUP_FILE"

# Cleanup
rm $BACKUP_FILE
```

### Form File Upload (HTML)
```html
<form id="uploadForm">
  <input type="file" id="fileInput" required>
  <button type="submit">Upload to Telegram</button>
</form>

<script>
document.getElementById('uploadForm').onsubmit = async (e) => {
  e.preventDefault();
  
  const formData = new FormData();
  formData.append('file', fileInput.files[0]);
  
  const response = await fetch('https://nttung.dev/wp-json/telegram-upload/v1/upload', {
    method: 'POST',
    headers: { 'X-API-Key': 'your-api-key' },
    body: formData
  });
  
  const result = await response.json();
  alert(result.success ? 'Uploaded!' : 'Failed!');
};
</script>
```

---

**Status**: Ready to use! 🚀  
**Version**: 1.0  
**Last Updated**: November 8, 2025
