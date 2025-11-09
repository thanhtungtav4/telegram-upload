# 🚀 Telegram Upload Plugin - REST API Documentation

## Overview

The Telegram Upload Plugin provides a comprehensive REST API for programmatic file uploads and management. Upload files directly to Telegram via HTTP requests.

**Base URL**: `https://nttung.dev/wp-json/telegram-upload/v1`

## Authentication

### API Key Generation

First, generate an API key (admin only):

```bash
POST /wp-json/telegram-upload/v1/generate-key
```

**cURL Example**:
```bash
curl -X POST https://nttung.dev/wp-json/telegram-upload/v1/generate-key \
  --cookie "wordpress_logged_in_xxx=..." 
```

**Response**:
```json
{
  "success": true,
  "message": "API key generated successfully",
  "api_key": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2",
  "instructions": [
    "Use this key in X-API-Key header or api_key parameter",
    "Keep this key secure - it grants upload access",
    "Example: curl -H \"X-API-Key: a1b2c3...\" ..."
  ]
}
```

### Using the API Key

**Method 1: Header (Recommended)**
```bash
-H "X-API-Key: your-api-key-here"
```

**Method 2: Query Parameter**
```bash
?api_key=your-api-key-here
```

**Method 3: Logged-in User**
If you're logged in to WordPress with `upload_files` capability, no API key needed.

---

## Endpoints

### 1. Upload File

Upload a file to Telegram.

#### Upload from Multipart Form Data

```
POST /wp-json/telegram-upload/v1/upload
Content-Type: multipart/form-data
```

**Parameters**:
- `file` (required): The file to upload

**cURL Example**:
```bash
curl -X POST https://nttung.dev/wp-json/telegram-upload/v1/upload \
  -H "X-API-Key: your-api-key-here" \
  -F "file=@/path/to/document.pdf"
```

**JavaScript Example**:
```javascript
const formData = new FormData();
formData.append('file', fileInput.files[0]);

fetch('https://nttung.dev/wp-json/telegram-upload/v1/upload', {
  method: 'POST',
  headers: {
    'X-API-Key': 'your-api-key-here'
  },
  body: formData
})
.then(res => res.json())
.then(data => console.log(data));
```

**Python Example**:
```python
import requests

url = "https://nttung.dev/wp-json/telegram-upload/v1/upload"
headers = {"X-API-Key": "your-api-key-here"}
files = {"file": open("document.pdf", "rb")}

response = requests.post(url, headers=headers, files=files)
print(response.json())
```

**Response** (201 Created):
```json
{
  "success": true,
  "message": "File uploaded successfully",
  "file": {
    "id": 123,
    "name": "document.pdf",
    "size": 2621440,
    "size_formatted": "2.5 MB",
    "uploaded_at": "2025-11-08 10:30:00",
    "telegram_file_id": "BQACAgIAAxkBAAIBB2...",
    "download_url": "https://nttung.dev/wp-admin/admin-ajax.php?action=telegram_download_file&file_id=123&nonce=...",
    "download_count": 0,
    "shortcode": "[telegram_file id=\"123\"]"
  }
}
```

#### Upload from URL

```
POST /wp-json/telegram-upload/v1/upload
```

**Parameters**:
- `file_url` (required): URL of the file to download and upload

**cURL Example**:
```bash
curl -X POST "https://nttung.dev/wp-json/telegram-upload/v1/upload" \
  -H "X-API-Key: your-api-key-here" \
  -d "file_url=https://example.com/file.pdf"
```

**JavaScript Example**:
```javascript
fetch('https://nttung.dev/wp-json/telegram-upload/v1/upload', {
  method: 'POST',
  headers: {
    'X-API-Key': 'your-api-key-here',
    'Content-Type': 'application/x-www-form-urlencoded'
  },
  body: 'file_url=https://example.com/file.pdf'
})
.then(res => res.json())
.then(data => console.log(data));
```

**Response**: Same as multipart upload

---

### 2. Get All Files

Retrieve a list of uploaded files with pagination and search.

```
GET /wp-json/telegram-upload/v1/files
```

**Parameters**:
- `limit` (optional, default: 50): Number of files to return
- `offset` (optional, default: 0): Number of files to skip
- `search` (optional): Search in filenames

**cURL Example**:
```bash
curl "https://nttung.dev/wp-json/telegram-upload/v1/files?limit=10&offset=0&search=report" \
  -H "X-API-Key: your-api-key-here"
```

**JavaScript Example**:
```javascript
fetch('https://nttung.dev/wp-json/telegram-upload/v1/files?limit=20&search=pdf', {
  headers: {
    'X-API-Key': 'your-api-key-here'
  }
})
.then(res => res.json())
.then(data => console.log(data));
```

**Response** (200 OK):
```json
{
  "success": true,
  "total": 150,
  "limit": 50,
  "offset": 0,
  "files": [
    {
      "id": 123,
      "name": "report.pdf",
      "size": 2621440,
      "size_formatted": "2.5 MB",
      "uploaded_at": "2025-11-08 10:30:00",
      "telegram_file_id": "BQACAgIAAxkBAAIBB2...",
      "download_url": "https://nttung.dev/wp-admin/admin-ajax.php?action=telegram_download_file&file_id=123&nonce=...",
      "download_count": 125,
      "shortcode": "[telegram_file id=\"123\"]"
    },
    // ... more files
  ]
}
```

---

### 3. Get Single File

Get details of a specific file by ID.

```
GET /wp-json/telegram-upload/v1/files/{id}
```

**cURL Example**:
```bash
curl "https://nttung.dev/wp-json/telegram-upload/v1/files/123" \
  -H "X-API-Key: your-api-key-here"
```

**Response** (200 OK):
```json
{
  "success": true,
  "file": {
    "id": 123,
    "name": "document.pdf",
    "size": 2621440,
    "size_formatted": "2.5 MB",
    "uploaded_at": "2025-11-08 10:30:00",
    "telegram_file_id": "BQACAgIAAxkBAAIBB2...",
    "download_url": "https://nttung.dev/wp-admin/admin-ajax.php?action=telegram_download_file&file_id=123&nonce=...",
    "download_count": 125,
    "shortcode": "[telegram_file id=\"123\"]"
  }
}
```

**Error Response** (404 Not Found):
```json
{
  "code": "file_not_found",
  "message": "File not found",
  "data": {
    "status": 404
  }
}
```

---

### 4. Delete File

Delete a file from the database (admin only).

```
DELETE /wp-json/telegram-upload/v1/files/{id}
```

**Note**: This only deletes the database record. The file remains in Telegram.

**cURL Example**:
```bash
curl -X DELETE "https://nttung.dev/wp-json/telegram-upload/v1/files/123" \
  --cookie "wordpress_logged_in_xxx=..."
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "File deleted successfully",
  "file": {
    "id": 123,
    "name": "document.pdf"
  }
}
```

---

### 5. Get Statistics

Get upload and download statistics.

```
GET /wp-json/telegram-upload/v1/stats
```

**cURL Example**:
```bash
curl "https://nttung.dev/wp-json/telegram-upload/v1/stats" \
  -H "X-API-Key: your-api-key-here"
```

**Response** (200 OK):
```json
{
  "success": true,
  "stats": {
    "total_files": 150,
    "total_size": 524288000,
    "total_size_formatted": "500 MB",
    "total_downloads": 1250,
    "files_with_downloads": 85,
    "average_downloads": 8.33
  },
  "popular_files": [
    {
      "id": 45,
      "name": "popular-document.pdf",
      "download_count": 250
    },
    // ... top 10 files
  ],
  "recent_files": [
    {
      "id": 150,
      "name": "latest-upload.xlsx",
      "uploaded_at": "2025-11-08 14:00:00"
    },
    // ... last 10 files
  ]
}
```

---

## Error Responses

### 401 Unauthorized
```json
{
  "code": "no_api_key",
  "message": "API key required. Use X-API-Key header or api_key parameter.",
  "data": {
    "status": 401
  }
}
```

### 403 Forbidden
```json
{
  "code": "invalid_api_key",
  "message": "Invalid API key.",
  "data": {
    "status": 403
  }
}
```

### 400 Bad Request
```json
{
  "code": "no_file",
  "message": "No file provided. Use either \"file\" (multipart) or \"file_url\" parameter.",
  "data": {
    "status": 400
  }
}
```

### 500 Internal Server Error
```json
{
  "code": "upload_failed",
  "message": "Failed to upload file to Telegram: [error details]",
  "data": {
    "status": 500
  }
}
```

---

## Usage Examples

### Complete Upload Workflow (Bash)

```bash
#!/bin/bash

# 1. Generate API key (admin, one-time)
API_KEY=$(curl -s -X POST https://nttung.dev/wp-json/telegram-upload/v1/generate-key \
  --cookie "wordpress_logged_in_xxx=..." | jq -r '.api_key')

echo "API Key: $API_KEY"

# 2. Upload a file
UPLOAD_RESULT=$(curl -s -X POST https://nttung.dev/wp-json/telegram-upload/v1/upload \
  -H "X-API-Key: $API_KEY" \
  -F "file=@document.pdf")

echo "Upload Result:"
echo $UPLOAD_RESULT | jq .

# 3. Get file ID
FILE_ID=$(echo $UPLOAD_RESULT | jq -r '.file.id')

# 4. Get file details
curl -s "https://nttung.dev/wp-json/telegram-upload/v1/files/$FILE_ID" \
  -H "X-API-Key: $API_KEY" | jq .

# 5. Get statistics
curl -s "https://nttung.dev/wp-json/telegram-upload/v1/stats" \
  -H "X-API-Key: $API_KEY" | jq .
```

### Upload Multiple Files (Python)

```python
import requests
import os

API_KEY = "your-api-key-here"
BASE_URL = "https://nttung.dev/wp-json/telegram-upload/v1"

def upload_file(filepath):
    url = f"{BASE_URL}/upload"
    headers = {"X-API-Key": API_KEY}
    files = {"file": open(filepath, "rb")}
    
    response = requests.post(url, headers=headers, files=files)
    return response.json()

# Upload directory of files
folder = "/path/to/files"
for filename in os.listdir(folder):
    filepath = os.path.join(folder, filename)
    if os.path.isfile(filepath):
        print(f"Uploading {filename}...")
        result = upload_file(filepath)
        if result['success']:
            print(f"✓ Uploaded: {result['file']['download_url']}")
        else:
            print(f"✗ Failed: {result}")
```

### Upload from URL (Node.js)

```javascript
const axios = require('axios');

const API_KEY = 'your-api-key-here';
const BASE_URL = 'https://nttung.dev/wp-json/telegram-upload/v1';

async function uploadFromURL(url) {
  try {
    const response = await axios.post(
      `${BASE_URL}/upload`,
      `file_url=${encodeURIComponent(url)}`,
      {
        headers: {
          'X-API-Key': API_KEY,
          'Content-Type': 'application/x-www-form-urlencoded'
        }
      }
    );
    
    console.log('Upload successful:', response.data);
    return response.data;
  } catch (error) {
    console.error('Upload failed:', error.response?.data || error.message);
    throw error;
  }
}

// Usage
uploadFromURL('https://example.com/document.pdf')
  .then(result => {
    console.log('File ID:', result.file.id);
    console.log('Download URL:', result.file.download_url);
  });
```

### React Upload Component

```jsx
import React, { useState } from 'react';

function TelegramUploader() {
  const [file, setFile] = useState(null);
  const [uploading, setUploading] = useState(false);
  const [result, setResult] = useState(null);

  const handleUpload = async (e) => {
    e.preventDefault();
    if (!file) return;

    setUploading(true);
    const formData = new FormData();
    formData.append('file', file);

    try {
      const response = await fetch(
        'https://nttung.dev/wp-json/telegram-upload/v1/upload',
        {
          method: 'POST',
          headers: {
            'X-API-Key': 'your-api-key-here'
          },
          body: formData
        }
      );

      const data = await response.json();
      setResult(data);
    } catch (error) {
      console.error('Upload error:', error);
    } finally {
      setUploading(false);
    }
  };

  return (
    <div>
      <form onSubmit={handleUpload}>
        <input
          type="file"
          onChange={(e) => setFile(e.target.files[0])}
        />
        <button type="submit" disabled={!file || uploading}>
          {uploading ? 'Uploading...' : 'Upload to Telegram'}
        </button>
      </form>

      {result && result.success && (
        <div>
          <h3>Upload Successful!</h3>
          <p>File: {result.file.name}</p>
          <p>Size: {result.file.size_formatted}</p>
          <a href={result.file.download_url}>Download</a>
          <p>Shortcode: {result.file.shortcode}</p>
        </div>
      )}
    </div>
  );
}

export default TelegramUploader;
```

---

## Rate Limiting

Currently, no rate limiting is enforced. Use responsibly.

**Recommendations**:
- Max 100 requests per minute
- Max file size: 50MB (Telegram limit)
- Large files are automatically split

---

## Security Best Practices

1. **Protect Your API Key**
   - Never commit API keys to version control
   - Use environment variables
   - Rotate keys periodically

2. **HTTPS Only**
   - Always use HTTPS in production
   - API keys are transmitted in headers

3. **Validate Files**
   - Check file types before uploading
   - Scan for malware if needed
   - Respect file size limits

4. **Monitor Usage**
   - Check stats regularly
   - Look for unusual activity
   - Regenerate key if compromised

---

## Testing the API

### Using Postman

1. **Create New Request**
   - Method: POST
   - URL: `https://nttung.dev/wp-json/telegram-upload/v1/upload`

2. **Add Header**
   - Key: `X-API-Key`
   - Value: `your-api-key-here`

3. **Add Body**
   - Type: `form-data`
   - Key: `file`
   - Type: `File`
   - Value: Select file

4. **Send Request**

### Using cURL (Quick Test)

```bash
# Test upload
curl -X POST https://nttung.dev/wp-json/telegram-upload/v1/upload \
  -H "X-API-Key: your-key" \
  -F "file=@test.pdf" \
  -v

# Test get files
curl "https://nttung.dev/wp-json/telegram-upload/v1/files?limit=5" \
  -H "X-API-Key: your-key" \
  | jq .

# Test stats
curl "https://nttung.dev/wp-json/telegram-upload/v1/stats" \
  -H "X-API-Key: your-key" \
  | jq .stats
```

---

## Troubleshooting

### API Key Not Working
- Regenerate key via `/generate-key` endpoint
- Check if key is in header or parameter
- Verify you're using the latest key

### File Upload Fails
- Check file size (max 50MB)
- Verify Telegram token is configured
- Check PHP upload limits
- Review server error logs

### 404 Not Found
- Ensure permalinks are enabled
- Flush rewrite rules
- Check `.htaccess` configuration

### CORS Issues
Add to `wp-config.php`:
```php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');
header('Access-Control-Allow-Headers: X-API-Key, Content-Type');
```

---

## Changelog

### Version 2.2 (Current)
- ✅ Added REST API endpoints
- ✅ API key authentication
- ✅ Upload from URL support
- ✅ File management endpoints
- ✅ Statistics endpoint

### Version 2.1
- Download counter feature
- Auto-reload file list
- Search functionality

### Version 2.0
- Download proxy
- Shortcode system
- Performance optimizations

---

## Support

For API support:
- **Documentation**: `/docs/API_DOCUMENTATION.md`
- **Examples**: See above usage examples
- **Issues**: Check WordPress error logs
- **Testing**: Use Postman or cURL

---

**API Version**: 1.0  
**Last Updated**: November 8, 2025  
**Base URL**: `https://nttung.dev/wp-json/telegram-upload/v1`
