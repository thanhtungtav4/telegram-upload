/**
 * Telegram Upload Shortcode JavaScript
 * Handles download button loading states
 */

(function() {
    'use strict';
    
    /**
     * Add loading state to download buttons
     */
    function initDownloadButtons() {
        const downloadButtons = document.querySelectorAll('.tg-download-btn');
        
        downloadButtons.forEach(button => {
            button.addEventListener('click', function() {
                handleDownloadClick(this);
            });
        });
    }
    
    /**
     * Handle download button click
     */
    function handleDownloadClick(button) {
        // Prevent multiple clicks
        if (button.classList.contains('loading')) {
            return;
        }
        
        // Add loading state
        button.classList.add('loading');
        
        // Get text element
        const textElement = button.querySelector('.tg-text');
        const originalText = textElement ? textElement.textContent : 'Download';
        
        // Update text
        if (textElement) {
            textElement.textContent = 'Downloading...';
        }
        
        // Reset after 3 seconds
        setTimeout(() => {
            button.classList.remove('loading');
            if (textElement) {
                textElement.textContent = originalText;
            }
        }, 3000);
    }
    
    /**
     * Initialize when DOM is ready
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDownloadButtons);
    } else {
        initDownloadButtons();
    }
    
    /**
     * Re-initialize for dynamically loaded content
     */
    window.TelegramUploadShortcodes = {
        init: initDownloadButtons,
        handleDownloadClick: handleDownloadClick
    };
    
})();
