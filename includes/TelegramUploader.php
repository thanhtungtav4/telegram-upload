<?php
if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'GeneralSettings.php';
require_once plugin_dir_path(__FILE__) . 'FileSplitter.php';
require_once plugin_dir_path(__FILE__) . 'TelegramUploaderDisplay.php';
require_once plugin_dir_path(__FILE__) . 'TelegramUploaderCore.php';

class TelegramUploader {
    private $core;

    public function __construct() {
        $this->core = new TelegramUploaderCore();

        add_action('wp_ajax_te_file_upload', [$this, 'handle_ajax_upload']);
        add_action('wp_ajax_nopriv_te_file_upload', [$this, 'handle_ajax_upload']);
        add_action('wp_ajax_te_reload_files', [$this, 'handle_ajax_reload_files']);
        add_action('wp_ajax_telegram_delete_file', [$this, 'handle_ajax_delete_file']);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    public function enqueue_scripts($hook) {
        // Load Tailwind CSS (bundled locally)
        wp_enqueue_style(
            'telefiup-tailwind', 
            plugins_url('../assets/vendor/tailwind.min.css', __FILE__),
            array(),
            '2.2.19'
        );
        
        // Only load on Telegram upload page
        if ($hook !== 'toplevel_page_telegram-file-upload') {
            return;
        }
        
        // Check if client-side upload is enabled
        $settings = get_option(GeneralSettings::OPTION_NAME);
        $upload_method = isset($settings['upload_method']) ? $settings['upload_method'] : 'server-side';
        
        if ($upload_method === 'client-side') {
            // Enqueue client-side upload script
            wp_enqueue_script(
                'telegram-client-upload',
                plugins_url('assets/client-upload.js', dirname(__FILE__)),
                ['jquery'],
                '2.6.0',
                true
            );
            
            // Pass settings to JavaScript
            $bot_token = isset($settings['token']) ? base64_decode($settings['token']) : '';
            $api_base = isset($settings['api_base']) ? $settings['api_base'] : 'https://api.telegram.org';
            
            wp_localize_script('telegram-client-upload', 'telegramClientUploadSettings', [
                'botToken' => $bot_token,
                'apiBase' => $api_base,
                'uploadMethod' => 'client-side',
                'restUrl' => rest_url('telegram/v1'),
                'nonce' => wp_create_nonce('wp_rest')
            ]);
            
            // Also set wpApiSettings for compatibility
            wp_localize_script('telegram-client-upload', 'wpApiSettings', [
                'root' => esc_url_raw(rest_url()),
                'nonce' => wp_create_nonce('wp_rest')
            ]);
        }
    }

    public function handle_ajax_upload() {
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        if (!isset($_FILES['file'])) {
            wp_send_json_error(['message' => 'No file uploaded.']);
        }

        $file = $_FILES['file'];
        $response = $this->core->upload($file);
        wp_send_json_success(['html' => $response]);
    }
    
    public function handle_ajax_reload_files() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }
        
        ob_start();
        TelegramUploaderDisplay::render_uploaded_files(
            $this->core->get_wpdb(),
            $this->core->get_table(),
            $this->core->get_api_base(),
            $this->core->get_token(),
            false
        );
        $html = ob_get_clean();
        
        wp_send_json_success(['html' => $html]);
    }
    
    public function handle_ajax_delete_file() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }
        
        $file_id = isset($_POST['file_id']) ? intval($_POST['file_id']) : 0;
        
        if (!$file_id) {
            wp_send_json_error(['message' => 'Invalid file ID.']);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'telegram_uploaded_files';
        
        // Get file info before deleting
        $file = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $file_id
        ));
        
        if (!$file) {
            wp_send_json_error(['message' => 'File not found.']);
        }
        
        // Delete from database
        $deleted = $wpdb->delete($table, ['id' => $file_id], ['%d']);
        
        if ($deleted === false) {
            wp_send_json_error(['message' => 'Failed to delete file from database.']);
        }
        
        // Also delete analytics data if exists
        $analytics_table = $wpdb->prefix . 'telegram_download_analytics';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from wpdb prefix
        if ($wpdb->get_var("SHOW TABLES LIKE '{$analytics_table}'") == $analytics_table) {
            $wpdb->delete($analytics_table, ['file_id' => $file_id], ['%d']);
        }
        
        // Clear cache
        wp_cache_delete('tg_file_' . $file_id);
        
        wp_send_json_success([
            'message' => 'File deleted successfully.',
            'file_name' => $file->file_name
        ]);
    }

    public function display_uploaded_files($is_frontend = false) {
        TelegramUploaderDisplay::render_uploaded_files(
            $this->core->get_wpdb(),
            $this->core->get_table(),
            $this->core->get_api_base(),
            $this->core->get_token(),
            $is_frontend
        );
    }
}

// Admin menu
add_action('admin_menu', function () {
    add_menu_page('Telegram Upload', 'Nttung File Upload for Telegram', 'manage_options', 'telegram-file-upload', function () {
        $api_key = get_option('telegram_upload_api_key', '');
        
        echo '<div class="wrap flex justify-center items-center min-h-screen bg-gray-50">'
            . '<div class="bg-white rounded-xl shadow-lg p-6 w-full max-w-lvw">'
            . '<div class="flex items-center justify-between mb-4">'
            . '<h2 class="text-lg font-semibold">Telegram File Upload</h2>'
            . '<button type="button" class="text-gray-400 hover:text-gray-700 text-2xl font-bold focus:outline-none" title="Close" onclick="document.querySelector(\'.wrap\').style.display=\'none\';">&times;</button>'
            . '</div>';
        
        // API Key Section
        echo '<div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">';
        echo '<div class="flex items-center justify-between mb-2">';
        echo '<h3 class="text-md font-semibold text-blue-900">🔑 API Access</h3>';
        echo '<button type="button" id="toggle-api-docs" class="text-blue-600 hover:text-blue-800 text-sm">Show Docs</button>';
        echo '</div>';
        
        if (empty($api_key)) {
            echo '<p class="text-sm text-gray-600 mb-3">Generate an API key to upload files programmatically via REST API.</p>';
            echo '<button type="button" id="generate-api-key" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-semibold">Generate API Key</button>';
        } else {
            echo '<p class="text-sm text-gray-600 mb-2">Your API Key:</p>';
            echo '<div class="flex gap-2">';
            echo '<input type="text" id="api-key-display" value="' . esc_attr($api_key) . '" readonly class="flex-1 px-3 py-2 border border-gray-300 rounded bg-white font-mono text-sm" onclick="this.select()">';
            echo '<button type="button" onclick="copyApiKey()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm font-semibold">Copy</button>';
            echo '<button type="button" id="regenerate-api-key" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded text-sm font-semibold">Regenerate</button>';
            echo '</div>';
        }
        
        echo '<div id="api-docs" class="hidden mt-4 p-4 bg-white border border-blue-200 rounded text-sm">';
        echo '<h4 class="font-semibold mb-2">Quick Start:</h4>';
        $http_host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        echo '<pre class="bg-gray-50 p-3 rounded text-xs overflow-x-auto">curl -X POST https://' . esc_html($http_host) . '/wp-json/telegram-upload/v1/upload \\
  -H "X-API-Key: ' . esc_attr($api_key) . '" \\
  -F "file=@document.pdf"</pre>';
        echo '<div class="mt-2">';
        echo '<a href="' . esc_url(plugins_url('docs/API_QUICK_START.md', dirname(__FILE__))) . '" target="_blank" class="text-blue-600 hover:underline">📖 View Full API Documentation</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="flex items-center justify-between mb-4">'
            . '<h2 class="text-lg font-semibold">Upload Your Documents</h2>'
            . '</div>'
            . '<form id="te-upload-form" class="space-y-4">'
            . '<div id="te-drop-area" class="flex flex-col items-center justify-center border-2 border-dashed border-gray-300 rounded-lg h-48 cursor-pointer transition hover:border-blue-400 bg-gray-50">'
            . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="48px" height="48px" viewBox="0 0 48 48" version="1.1">
                <g id="surface1">
                <path style="fill:none;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round;stroke:#8a8989;stroke-opacity:1;stroke-miterlimit:4;" d="M 21.960938 13.419922 C 21.824219 12.322266 21.326172 11.298828 20.546875 10.513672 C 19.765625 9.728516 18.748047 9.224609 17.650391 9.080078 C 17.177734 7.753906 16.251953 6.638672 15.039062 5.925781 C 13.826172 5.212891 12.402344 4.947266 11.013672 5.179688 C 9.625 5.410156 8.363281 6.121094 7.447266 7.189453 C 6.53125 8.257812 6.017578 9.613281 6 11.019531 C 4.939453 11.019531 3.921875 11.441406 3.171875 12.191406 C 2.421875 12.941406 2 13.958984 2 15.019531 C 2 16.080078 2.421875 17.097656 3.171875 17.847656 C 3.921875 18.597656 4.939453 19.019531 6 19.019531 L 12 19.019531 " transform="matrix(2,0,0,2,0,0)"/>
                <path style="fill:none;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round;stroke:#8a8989;stroke-opacity:1;stroke-miterlimit:4;" d="M 18.779297 23 L 18.779297 15 " transform="matrix(2,0,0,2,0,0)"/>
                <path style="fill:none;stroke-width:1.5;stroke-linecap:round;stroke-linejoin:round;stroke:#8a8989;stroke-opacity:1;stroke-miterlimit:4;" d="M 15.580078 18.199219 L 18.779297 15 L 21.980469 18.199219 " transform="matrix(2,0,0,2,0,0)"/>
                </g>
            </svg>'
            . '<span class="text-gray-500">Click to upload or drag and drop</span>'
            . '<input type="file" name="file[]" id="te-upload-input" multiple class="hidden" />'
            . '</div>'
            
            // Category and Tags fields
            . '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">'
            . '<div>'
            . '<label for="telegram_category" class="block text-sm font-medium text-gray-700 mb-1">Category (Auto-detected)</label>'
            . TelegramCategories::render_category_dropdown('', 'telegram_category', 'telegram_category')
            . '</div>'
            . '<div>'
            . '<label for="telegram_tags" class="block text-sm font-medium text-gray-700 mb-1">Tags (comma-separated)</label>'
            . '<input type="text" name="telegram_tags" id="telegram_tags" placeholder="urgent, public, important" class="regular-text w-full px-3 py-2 border border-gray-300 rounded-md" />'
            . '</div>'
            . '</div>'
            . '<div>'
            . '<label for="telegram_description" class="block text-sm font-medium text-gray-700 mb-1">Description (Optional)</label>'
            . '<textarea name="telegram_description" id="telegram_description" rows="2" placeholder="Brief description of the file..." class="w-full px-3 py-2 border border-gray-300 rounded-md"></textarea>'
            . '</div>'
            
            // Access Control Settings
            . '<div class="border-t pt-4 mt-4">'
            . '<h3 class="text-sm font-semibold text-gray-700 mb-3 flex items-center gap-2">'
            . '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/></svg>'
            . 'Access Control (Optional)'
            . '</h3>'
            . '<div class="grid grid-cols-1 md:grid-cols-3 gap-4">'
            
            // Expiration Date
            . '<div>'
            . '<label for="telegram_expiration" class="block text-sm font-medium text-gray-700 mb-1">Expiration Date</label>'
            . '<input type="datetime-local" name="telegram_expiration" id="telegram_expiration" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" />'
            . '<p class="text-xs text-gray-500 mt-1">File will expire after this date</p>'
            . '</div>'
            
            // Password Protection
            . '<div>'
            . '<label for="telegram_password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>'
            . '<input type="password" name="telegram_password" id="telegram_password" placeholder="Leave empty for no password" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" autocomplete="new-password" />'
            . '<p class="text-xs text-gray-500 mt-1">Require password to download</p>'
            . '</div>'
            
            // Max Downloads
            . '<div>'
            . '<label for="telegram_max_downloads" class="block text-sm font-medium text-gray-700 mb-1">Max Downloads</label>'
            . '<input type="number" name="telegram_max_downloads" id="telegram_max_downloads" min="0" placeholder="Unlimited" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm" />'
            . '<p class="text-xs text-gray-500 mt-1">Limit number of downloads</p>'
            . '</div>'
            
            . '</div>'
            . '</div>'
            
            . '<div class="flex justify-between text-xs text-gray-500 px-1">'
            . '<span>Supported Formats: SVG, PNG, JPG or GIF</span>'
            . '<span>Max size: 25MB</span>'
            . '</div>'
            . '<div id="te-progress" class="w-full h-2 bg-gray-200 rounded overflow-hidden">'
            . '<div class="h-2 bg-blue-500 rounded transition-all duration-300" style="width:0%"></div>'
            . '</div>'
            . '<button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded font-semibold shadow">Upload</button>'
            . '</form>'
            . '<div id="te-upload-output" class="mt-4 space-y-2"></div>';

        // Show all uploaded files in the table view only
        $uploader = new TelegramUploader();
        $uploader->display_uploaded_files();
        echo '</div></div>';
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
        // Attach delete button event listeners (using event delegation)
        document.addEventListener('click', function(e) {
            if (e.target.closest('.tg-delete-btn')) {
                const button = e.target.closest('.tg-delete-btn');
                const fileId = button.getAttribute('data-file-id');
                const fileName = button.getAttribute('data-file-name');
                deleteTelegramFile(fileId, fileName);
            }
        });
        
        // API Key functionality
        document.getElementById('toggle-api-docs')?.addEventListener('click', function() {
            const docs = document.getElementById('api-docs');
            docs.classList.toggle('hidden');
            this.textContent = docs.classList.contains('hidden') ? 'Show Docs' : 'Hide Docs';
        });
        
        function copyApiKey() {
            const apiKeyInput = document.getElementById('api-key-display');
            apiKeyInput.select();
            document.execCommand('copy');
            
            const button = event.target;
            const originalText = button.textContent;
            button.textContent = 'Copied!';
            button.classList.add('bg-blue-600');
            button.classList.remove('bg-green-600');
            
            setTimeout(() => {
                button.textContent = originalText;
                button.classList.remove('bg-blue-600');
                button.classList.add('bg-green-600');
            }, 2000);
        }
        
        document.getElementById('generate-api-key')?.addEventListener('click', function() {
            const button = this;
            button.disabled = true;
            button.textContent = 'Generating...';
            
            fetch('<?php echo rest_url('telegram-upload/v1/generate-key'); ?>', {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to generate API key: ' + (data.message || 'Unknown error'));
                    button.disabled = false;
                    button.textContent = 'Generate API Key';
                }
            })
            .catch(err => {
                alert('Error: ' + err.message);
                button.disabled = false;
                button.textContent = 'Generate API Key';
            });
        });
        
        document.getElementById('regenerate-api-key')?.addEventListener('click', function() {
            if (!confirm('Are you sure? This will invalidate the current API key and any applications using it will stop working.')) {
                return;
            }
            
            const button = this;
            button.disabled = true;
            button.textContent = 'Regenerating...';
            
            fetch('<?php echo rest_url('telegram-upload/v1/generate-key'); ?>', {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to regenerate API key: ' + (data.message || 'Unknown error'));
                    button.disabled = false;
                    button.textContent = 'Regenerate';
                }
            })
            .catch(err => {
                alert('Error: ' + err.message);
                button.disabled = false;
                button.textContent = 'Regenerate';
            });
        });
        
        // Drag and drop and click to upload
        const dropArea = document.getElementById('te-drop-area');
        const fileInput = document.getElementById('te-upload-input');
        const uploadForm = document.getElementById('te-upload-form');
        
        if (dropArea && fileInput) {
            dropArea.addEventListener('click', () => fileInput.click());
            dropArea.addEventListener('dragover', e => { e.preventDefault(); dropArea.classList.add('border-blue-400'); });
            dropArea.addEventListener('dragleave', e => { e.preventDefault(); dropArea.classList.remove('border-blue-400'); });
            dropArea.addEventListener('drop', e => {
                e.preventDefault();
                dropArea.classList.remove('border-blue-400');
                fileInput.files = e.dataTransfer.files;
            });
        }
        
        if (uploadForm) {
            uploadForm.addEventListener('submit', async function (e) {
                e.preventDefault();
                const files = fileInput.files;
                const output = document.getElementById('te-upload-output');
                const progressBar = document.querySelector('#te-progress div');
                const submitButton = document.querySelector('#te-upload-form button[type="submit"]');
                if (!files.length) return;
                
                // Change button text to 'Uploading...' and disable
                submitButton.textContent = 'Uploading...';
                submitButton.disabled = true;
                
                // Get metadata from form
                const metadata = {
                    category: document.getElementById('telegram_category')?.value || '',
                    tags: document.getElementById('telegram_tags')?.value || '',
                    description: document.getElementById('telegram_description')?.value || '',
                    expiration_date: document.getElementById('telegram_expiration')?.value || '',
                    password: document.getElementById('telegram_password')?.value || '',
                    max_downloads: document.getElementById('telegram_max_downloads')?.value || ''
                };
                
                // Check if client-side upload is available
                const useClientSide = typeof TelegramClientUpload !== 'undefined' && 
                                     typeof telegramClientUploadSettings !== 'undefined' &&
                                     telegramClientUploadSettings.uploadMethod === 'client-side';
                
                if (useClientSide) {
                    // Client-Side Upload
                    const uploader = new TelegramClientUpload({
                        apiBase: telegramClientUploadSettings.restUrl || '/wp-json/telegram/v1',
                        onProgress: function(progress) {
                            const percent = progress.percent || 0;
                            progressBar.style.width = `${percent}%`;
                            
                            let stageText = 'Uploading...';
                            if (progress.stage === 'requesting_token') stageText = 'Preparing upload...';
                            else if (progress.stage === 'uploading_to_telegram') stageText = 'Uploading to Telegram...';
                            else if (progress.stage === 'saving_metadata') stageText = 'Saving file info...';
                            else if (progress.stage === 'complete') stageText = 'Complete!';
                            
                            submitButton.textContent = stageText;
                        },
                        onSuccess: function(result) {
                            output.innerHTML += '<p class="text-green-600">File uploaded and sent to Telegram!</p>';
                        },
                        onError: function(error) {
                            output.innerHTML += '<p class="text-red-600">Telegram API error: ' + error.message + '</p>';
                        }
                    });
                    
                    try {
                        for (const file of files) {
                            await uploader.upload(file, metadata);
                        }
                    } catch (error) {
                        console.error('Upload failed:', error);
                    } finally {
                        submitButton.textContent = 'Upload';
                        submitButton.disabled = false;
                        fileInput.value = '';
                        setTimeout(() => reloadFileList(), 1000);
                    }
                } else {
                    // Server-Side Upload (fallback)
                    let uploaded = 0;
                    const total = files.length;
                    
                    for (const file of files) {
                        const formData = new FormData();
                        formData.append('file', file);
                        formData.append('action', 'te_file_upload');
                        formData.append('telegram_category', metadata.category);
                        formData.append('telegram_tags', metadata.tags);
                        formData.append('telegram_description', metadata.description);
                        
                        if (metadata.expiration_date) formData.append('telegram_expiration', metadata.expiration_date);
                        if (metadata.password) formData.append('telegram_password', metadata.password);
                        if (metadata.max_downloads) formData.append('telegram_max_downloads', metadata.max_downloads);
                        
                        try {
                            const res = await fetch(ajaxurl, { method: 'POST', body: formData });
                            const data = await res.json();
                            
                            if (data.success) {
                                output.innerHTML += data.data.html;
                            } else {
                                output.innerHTML += '<p class="text-red-600">Upload failed</p>';
                            }
                        } catch (error) {
                            output.innerHTML += '<p class="text-red-600">Upload error: ' + error.message + '</p>';
                        } finally {
                            uploaded++;
                            progressBar.style.width = `${(uploaded / total) * 100}%`;
                        }
                    }
                    
                    submitButton.textContent = 'Upload';
                    submitButton.disabled = false;
                    fileInput.value = '';
                    setTimeout(() => reloadFileList(), 1000);
                }
            });
        }
        
        // Function to reload file list
        function reloadFileList() {
            const container = document.getElementById('telegram-files-container');
            if (!container) return;
            
            // Show loading state
            const originalHTML = container.innerHTML;
            container.innerHTML = '<div class="text-center py-8"><div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div><p class="mt-2 text-gray-600">Refreshing file list...</p></div>';
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=te_reload_files'
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    container.outerHTML = data.data.html;
                } else {
                    container.innerHTML = originalHTML;
                    console.error('Failed to reload file list');
                }
            })
            .catch(err => {
                container.innerHTML = originalHTML;
                console.error('Error reloading file list:', err);
            });
        }
        
        // Function to delete file
        function deleteTelegramFile(fileId, fileName) {
            if (!confirm('Are you sure you want to delete "' + fileName + '"?\n\nThis action cannot be undone.')) {
                return;
            }
            
            // Show loading state
            const container = document.getElementById('telegram-files-container');
            if (container) {
                container.style.opacity = '0.5';
                container.style.pointerEvents = 'none';
            }
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=telegram_delete_file&file_id=' + fileId
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('✅ File "' + fileName + '" deleted successfully!');
                    // Reload file list
                    reloadFileList();
                } else {
                    alert('❌ Error: ' + (data.data.message || 'Failed to delete file'));
                    if (container) {
                        container.style.opacity = '1';
                        container.style.pointerEvents = 'auto';
                    }
                }
            })
            .catch(err => {
                alert('❌ Error deleting file. Please try again.');
                console.error('Error deleting file:', err);
                if (container) {
                    container.style.opacity = '1';
                    container.style.pointerEvents = 'auto';
                }
            });
        }
        }); // End DOMContentLoaded
        </script>
        <?php
    }, 'dashicons-upload', 100);
});
