<?php
if (!defined('ABSPATH')) exit;

class TelegramUploaderDisplay {
    public static function render_uploaded_files($wpdb, $table, $api_base, $token, $is_frontend = false) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name from wpdb prefix, no user input
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY file_time DESC");

        if (empty($rows)) {
            echo '<div id="telegram-files-container">';
            echo '<p>No files uploaded yet.</p>';
            echo '</div>';
            return;
        }

        echo '<div id="telegram-files-container">';
        echo '<div class="flex items-center justify-between mb-4">';
        echo '<h3 class="text-xl font-bold">📁 Uploaded Files</h3>';
        
        // Search box only
        echo '<div class="relative">';
        echo '<input type="text" id="tg-search-input" placeholder="Search files..." class="px-4 py-2 pr-10 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" style="width: 300px;">';
        echo '<svg class="absolute right-3 top-2.5 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>';
        echo '</div>';
        
        echo '</div>';
        
        // Add inline CSS and JavaScript for copy functionality
        echo '<style>
            .tg-shortcode-cell { position: relative; }
            .tg-shortcode-input { 
                font-family: monospace; 
                font-size: 12px; 
                padding: 4px 8px; 
                background: #f9fafb; 
                border: 1px solid #e5e7eb; 
                border-radius: 4px; 
                width: 200px;
                margin-right: 8px;
            }
            .tg-copy-btn {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 4px 12px;
                background: #10b981;
                color: white;
                border: none;
                border-radius: 4px;
                font-size: 12px;
                cursor: pointer;
                transition: all 0.2s;
            }
            .tg-copy-btn:hover {
                background: #059669;
            }
            .tg-copy-btn:active {
                transform: scale(0.95);
            }
            .tg-copy-btn svg {
                width: 14px;
                height: 14px;
            }
            .tg-copy-btn.copied {
                background: #3b82f6;
            }
        </style>';

        echo '<table class="min-w-full bg-white border border-gray-200 rounded-lg overflow-hidden shadow-sm">';
        echo '<thead class="bg-gray-50 border-b border-gray-200">';
        echo '<tr>';
        echo '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">File</th>';
        echo '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>';
        echo '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Size</th>';
        echo '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Upload Time</th>';
        echo '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Downloads</th>';
        echo '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Access</th>';
        echo '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Shortcode</th>';
        echo '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody class="divide-y divide-gray-200">';

        foreach ($rows as $entry) {
            $file_extension = pathinfo($entry->file_name, PATHINFO_EXTENSION);
            $file_name = htmlspecialchars($entry->file_name);
            $file_size = self::format_filesize($entry->file_size);
            $file_time = date('Y-m-d H:i:s', strtotime($entry->file_time));
            $download_count = $entry->download_count;

            // Generate shortcode
            $shortcode = "[telegram_file id=\"{$entry->id}\"]";

            // Download URL
            $download_url = add_query_arg([
                'action' => 'telegram_download_file',
                'file_id' => $entry->id,
                'nonce' => wp_create_nonce('telegram_download')
            ], admin_url('admin-ajax.php'));

            // Generate emoji for file type
            $emoji = self::get_file_emoji($file_extension);

            echo '<tr class="hover:bg-gray-50 transition-colors">';
            
            // File name with emoji
            echo '<td class="px-6 py-4">';
            echo '<div class="flex items-center gap-2">';
            echo '<span class="text-2xl">' . $emoji . '</span>';
            echo '<span class="text-sm font-medium text-gray-900">' . $file_name . '</span>';
            echo '</div>';
            echo '</td>';

            // Category badge
            echo '<td class="px-4 py-4">';
            if (!empty($entry->category)) {
                echo TelegramCategories::render_category_badge($entry->category);
            } else {
                echo '<span class="text-xs text-gray-400">No category</span>';
            }
            echo '</td>';

            // File size
            echo '<td class="px-6 py-4 text-sm text-gray-700">' . $file_size . '</td>';

            // Upload time
            echo '<td class="px-6 py-4 text-sm text-gray-700">' . $file_time . '</td>';

            // Download count
            echo '<td class="px-6 py-4">';
            echo '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">';
            echo '⬇️ ' . $download_count;
            echo '</span>';
            echo '</td>';

            // Access Control badges
            echo '<td class="px-4 py-4">';
            echo '<div class="flex flex-wrap gap-1">';
            
            // Check if file is expired
            $is_expired = false;
            if (!empty($entry->expiration_date)) {
                $expiration_time = strtotime($entry->expiration_date);
                $now = current_time('timestamp');
                if ($now > $expiration_time) {
                    $is_expired = true;
                    echo '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800" title="Expired on ' . esc_attr(date('Y-m-d H:i', $expiration_time)) . '">';
                    echo '⏰ Expired';
                    echo '</span>';
                } else {
                    $days_left = ceil(($expiration_time - $now) / 86400);
                    echo '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800" title="Expires: ' . esc_attr(date('Y-m-d H:i', $expiration_time)) . '">';
                    echo '⏳ ' . $days_left . 'd';
                    echo '</span>';
                }
            }
            
            // Password protected badge
            if (!empty($entry->password_hash)) {
                echo '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800" title="Password protected">';
                echo '🔒 Protected';
                echo '</span>';
            }
            
            // Download limit badge
            if (!empty($entry->max_downloads)) {
                $remaining = max(0, $entry->max_downloads - ($entry->access_count ?? 0));
                $limit_reached = $remaining <= 0;
                $badge_color = $limit_reached ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800';
                echo '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ' . esc_attr($badge_color) . '" title="Downloads: ' . esc_attr($entry->access_count ?? 0) . '/' . esc_attr($entry->max_downloads) . '">';
                echo $limit_reached ? '🚫 Limit' : '📊 ' . esc_html($remaining) . '/' . esc_html($entry->max_downloads);
                echo '</span>';
            }
            
            // Inactive badge
            if (isset($entry->is_active) && !$entry->is_active) {
                echo '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800" title="File is inactive">';
                echo '❌ Inactive';
                echo '</span>';
            }
            
            // Show "No restrictions" if no access controls
            if (empty($entry->expiration_date) && empty($entry->password_hash) && empty($entry->max_downloads) && (empty($entry->is_active) || $entry->is_active)) {
                echo '<span class="text-xs text-gray-400">No restrictions</span>';
            }
            
            echo '</div>';
            echo '</td>';

            // Shortcode with copy button
            echo '<td class="px-6 py-4 tg-shortcode-cell">';
            echo '<div class="flex items-center gap-2">';
            echo '<input type="text" value="' . esc_attr($shortcode) . '" readonly class="tg-shortcode-input" id="shortcode-' . $entry->id . '">';
            echo '<button type="button" class="tg-copy-btn" onclick="copyShortcode(' . $entry->id . ')">';
            echo '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">';
            echo '<path d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>';
            echo '</svg>';
            echo '<span>Copy</span>';
            echo '</button>';
            echo '</div>';
            echo '</td>';

            // Actions
            echo '<td class="px-6 py-4">';
            echo '<div class="flex gap-2">';
            
            // Download button
            echo '<a href="' . esc_url($download_url) . '" class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium text-white bg-green-600 rounded-lg hover:bg-green-700 transition-colors">';
            echo '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">';
            echo '<path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>';
            echo '</svg>';
            echo 'Download';
            echo '</a>';

            // Delete button (with data attributes for event delegation)
            echo '<button type="button" class="tg-delete-btn inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors" data-file-id="' . $entry->id . '" data-file-name="' . esc_attr($file_name) . '">';
            echo '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">';
            echo '<path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>';
            echo '</svg>';
            echo 'Delete';
            echo '</button>';

            echo '</div>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>'; // End container

        // JavaScript for copy functionality and search
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
        
        window.copyShortcode = function(fileId) {
            const input = document.getElementById("shortcode-" + fileId);
            const button = event.currentTarget;
            
            input.select();
            document.execCommand("copy");
            
            button.classList.add("copied");
            button.innerHTML = \'<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/></svg><span>Copied!</span>\';
            
            setTimeout(() => {
                button.classList.remove("copied");
                button.innerHTML = \'<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/></svg><span>Copy</span>\';
            }, 2000);
        };

        // Live search functionality
        const searchInput = document.getElementById("tg-search-input");
        if (searchInput) {
            searchInput.addEventListener("input", function(e) {
                filterFiles();
            });
        }

        // Category filter functionality
        const categoryFilter = document.getElementById("tg-category-filter");
        if (categoryFilter) {
            categoryFilter.addEventListener("change", function(e) {
                filterFiles();
            });
        }

        function filterFiles() {
            const searchInput = document.getElementById("tg-search-input");
            const categoryFilter = document.getElementById("tg-category-filter");
            
            const searchTerm = searchInput ? searchInput.value.toLowerCase() : "";
            const selectedCategory = categoryFilter ? categoryFilter.value.toLowerCase() : "";
            const rows = document.querySelectorAll("#telegram-files-container tbody tr");
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const categoryCell = row.cells[1]; // Category is 2nd column (index 1)
                const categoryText = categoryCell ? categoryCell.textContent.toLowerCase() : \'\';
                
                const matchesSearch = text.includes(searchTerm);
                const matchesCategory = !selectedCategory || categoryText.includes(selectedCategory);
                
                row.style.display = (matchesSearch && matchesCategory) ? "" : "none";
            });
        }
        
        }); // End DOMContentLoaded
        </script>';
    }

    private static function format_filesize($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
        }
    }

    private static function get_file_emoji($extension) {
        $extension = strtolower($extension);
        
        $emoji_map = [
            // Documents
            'pdf' => '📕',
            'doc' => '📘',
            'docx' => '📘',
            'txt' => '📝',
            'rtf' => '📝',
            'odt' => '📄',
            
            // Spreadsheets
            'xls' => '📊',
            'xlsx' => '📊',
            'csv' => '📊',
            'ods' => '📊',
            
            // Presentations
            'ppt' => '📽️',
            'pptx' => '📽️',
            'odp' => '📽️',
            
            // Images
            'jpg' => '🖼️',
            'jpeg' => '🖼️',
            'png' => '🖼️',
            'gif' => '🖼️',
            'bmp' => '🖼️',
            'svg' => '🖼️',
            'webp' => '🖼️',
            
            // Videos
            'mp4' => '🎬',
            'avi' => '🎬',
            'mov' => '🎬',
            'wmv' => '🎬',
            'flv' => '🎬',
            'mkv' => '🎬',
            'webm' => '🎬',
            
            // Audio
            'mp3' => '🎵',
            'wav' => '🎵',
            'ogg' => '🎵',
            'flac' => '🎵',
            'aac' => '🎵',
            'm4a' => '🎵',
            
            // Archives
            'zip' => '📦',
            'rar' => '📦',
            '7z' => '📦',
            'tar' => '📦',
            'gz' => '📦',
            
            // Code
            'php' => '💻',
            'js' => '💻',
            'html' => '💻',
            'css' => '💻',
            'py' => '💻',
            'java' => '💻',
            'cpp' => '💻',
            'c' => '💻',
        ];

        return isset($emoji_map[$extension]) ? $emoji_map[$extension] : '📁';
    }
}
