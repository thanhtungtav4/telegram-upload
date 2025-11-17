/**
 * Telegram Client-Side Upload Manager
 * 
 * Handles direct uploads from browser to Telegram API using temporary tokens.
 * This bypasses VPS bandwidth usage and prevents timeout issues with large files.
 * 
 * Features:
 * - Direct upload to Telegram (no VPS bandwidth)
 * - Real-time progress tracking
 * - Automatic retry on failure
 * - File size validation (50MB limit)
 * - Access control support (password, expiration, max downloads)
 * 
 * @since 2.6.0
 */

class TelegramClientUpload {
    constructor(options = {}) {
        this.apiBase = options.apiBase || '/wp-json/telegram/v1';
        this.maxFileSize = options.maxFileSize || 50 * 1024 * 1024; // 50MB
        this.onProgress = options.onProgress || (() => {});
        this.onSuccess = options.onSuccess || (() => {});
        this.onError = options.onError || (() => {});
        this.retryAttempts = options.retryAttempts || 3;
        this.retryDelay = options.retryDelay || 2000; // 2 seconds
    }

    /**
     * Upload a file
     * 
     * @param {File} file - File object from input
     * @param {Object} metadata - Upload metadata (category, password, etc.)
     * @returns {Promise<Object>} Upload result
     */
    async upload(file, metadata = {}) {
        try {
            // Validate file size
            if (file.size > this.maxFileSize) {
                throw new Error(`File size (${this.formatFileSize(file.size)}) exceeds 50MB limit`);
            }

            this.onProgress({ stage: 'requesting_token', percent: 0 });

            // Step 1: Request upload token from VPS
            const tokenData = await this.requestUploadToken(metadata);

            this.onProgress({ stage: 'uploading_to_telegram', percent: 10 });

            // Step 2: Upload file directly to Telegram
            const telegramResult = await this.uploadToTelegram(file, tokenData);

            this.onProgress({ stage: 'saving_metadata', percent: 90 });

            // Step 3: Save metadata to VPS database
            const saveResult = await this.saveMetadata(
                tokenData.token,
                telegramResult,
                file
            );

            this.onProgress({ stage: 'complete', percent: 100 });

            this.onSuccess(saveResult);
            return saveResult;

        } catch (error) {
            this.onError(error);
            throw error;
        }
    }

    /**
     * Request upload token from server
     * 
     * @param {Object} metadata - Upload metadata
     * @returns {Promise<Object>} Token data
     */
    async requestUploadToken(metadata = {}) {
        const response = await fetch(`${this.apiBase}/request-upload`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.wpApiSettings?.nonce || ''
            },
            credentials: 'include',
            body: JSON.stringify(metadata)
        });

        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.message || 'Failed to request upload token');
        }

        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'Failed to get upload token');
        }

        return result.data;
    }

    /**
     * Upload file directly to Telegram
     * 
     * @param {File} file - File to upload
     * @param {Object} tokenData - Token data from server
     * @returns {Promise<Object>} Telegram API response
     */
    async uploadToTelegram(file, tokenData) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('document', file);
            formData.append('chat_id', tokenData.chat_id);

            const xhr = new XMLHttpRequest();

            // Track upload progress
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percent = 10 + Math.round((e.loaded / e.total) * 70); // 10-80%
                    this.onProgress({ 
                        stage: 'uploading_to_telegram', 
                        percent,
                        loaded: e.loaded,
                        total: e.total
                    });
                }
            });

            xhr.addEventListener('load', () => {
                if (xhr.status === 200) {
                    const response = JSON.parse(xhr.responseText);
                    
                    if (response.ok && response.result?.document) {
                        resolve(response.result.document);
                    } else {
                        reject(new Error(response.description || 'Telegram upload failed'));
                    }
                } else {
                    reject(new Error(`Telegram API error: ${xhr.status}`));
                }
            });

            xhr.addEventListener('error', () => {
                reject(new Error('Network error while uploading to Telegram'));
            });

            xhr.addEventListener('abort', () => {
                reject(new Error('Upload cancelled'));
            });

            // Open connection to Telegram API
            xhr.open('POST', tokenData.upload_url);
            xhr.send(formData);
        });
    }

    /**
     * Save file metadata to VPS database
     * 
     * @param {string} token - Upload token
     * @param {Object} telegramDoc - Telegram document object
     * @param {File} file - Original file object
     * @returns {Promise<Object>} Save result
     */
    async saveMetadata(token, telegramDoc, file) {
        const response = await fetch(`${this.apiBase}/save-upload`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                token: token,
                file_id: telegramDoc.file_id,
                file_name: file.name,
                file_size: telegramDoc.file_size || file.size
            })
        });

        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.message || 'Failed to save file metadata');
        }

        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'Failed to save file');
        }

        return result.data;
    }

    /**
     * Upload with automatic retry
     * 
     * @param {File} file - File to upload
     * @param {Object} metadata - Upload metadata
     * @param {number} attempt - Current attempt number
     * @returns {Promise<Object>} Upload result
     */
    async uploadWithRetry(file, metadata = {}, attempt = 1) {
        try {
            return await this.upload(file, metadata);
        } catch (error) {
            if (attempt < this.retryAttempts) {
                console.warn(`Upload failed (attempt ${attempt}/${this.retryAttempts}), retrying...`, error);
                
                // Wait before retry
                await new Promise(resolve => setTimeout(resolve, this.retryDelay * attempt));
                
                return this.uploadWithRetry(file, metadata, attempt + 1);
            }
            
            throw error;
        }
    }

    /**
     * Format file size for display
     * 
     * @param {number} bytes - File size in bytes
     * @returns {string} Formatted size
     */
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }
}

/**
 * jQuery plugin wrapper for easy integration
 */
(function($) {
    'use strict';

    /**
     * Initialize client-side upload on file input
     * 
     * Usage:
     * $('#file-input').telegramClientUpload({
     *     onProgress: function(progress) { console.log(progress); },
     *     onSuccess: function(result) { console.log(result); },
     *     onError: function(error) { console.error(error); }
     * });
     */
    $.fn.telegramClientUpload = function(options) {
        return this.each(function() {
            const $input = $(this);
            const $form = $input.closest('form');
            
            // Create uploader instance
            const uploader = new TelegramClientUpload(options);
            
            // Handle form submit
            $form.on('submit', async function(e) {
                e.preventDefault();
                
                const files = $input[0].files;
                if (files.length === 0) {
                    alert('Please select a file to upload');
                    return;
                }
                
                const file = files[0];
                
                // Get metadata from form fields
                const metadata = {
                    category: $form.find('[name="telegram_category"]').val(),
                    tags: $form.find('[name="telegram_tags"]').val(),
                    description: $form.find('[name="telegram_description"]').val(),
                    password: $form.find('[name="telegram_password"]').val(),
                    expiration_date: $form.find('[name="telegram_expiration"]').val(),
                    max_downloads: $form.find('[name="telegram_max_downloads"]').val()
                };
                
                try {
                    // Disable submit button
                    $form.find('[type="submit"]').prop('disabled', true);
                    
                    // Upload with retry
                    await uploader.uploadWithRetry(file, metadata);
                    
                } catch (error) {
                    console.error('Upload failed:', error);
                } finally {
                    // Re-enable submit button
                    $form.find('[type="submit"]').prop('disabled', false);
                }
            });
        });
    };
    
})(jQuery);

/**
 * Example usage in WordPress admin:
 * 
 * jQuery(document).ready(function($) {
 *     $('#telegram-file-input').telegramClientUpload({
 *         onProgress: function(progress) {
 *             $('#progress-bar').css('width', progress.percent + '%');
 *             $('#progress-text').text(progress.stage + ': ' + progress.percent + '%');
 *         },
 *         onSuccess: function(result) {
 *             alert('File uploaded successfully!');
 *             location.reload();
 *         },
 *         onError: function(error) {
 *             alert('Upload failed: ' + error.message);
 *         }
 *     });
 * });
 */
