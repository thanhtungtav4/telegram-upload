/**
 * General Settings Admin Script
 * Handles test telegram button
 */
(function() {
    'use strict';
    
    document.addEventListener('DOMContentLoaded', function() {
        const testBtn = document.getElementById('te-send-test');
        if (!testBtn) return;
        
        testBtn.addEventListener('click', function() {
            const result = document.getElementById('te-send-test-result');
            testBtn.disabled = true;
            result.textContent = 'Sending...';
            
            // Use localized ajaxurl from wp_localize_script
            const ajaxUrl = typeof telefiupSettings !== 'undefined' ? telefiupSettings.ajaxurl : ajaxurl;
            
            fetch(ajaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=te_send_test_telegram'
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    result.textContent = '✅ ' + data.data.message;
                } else {
                    result.textContent = '❌ ' + (data.data && data.data.message ? data.data.message : 'Test failed');
                }
            })
            .catch(() => {
                result.textContent = '❌ Test failed';
            })
            .finally(() => {
                testBtn.disabled = false;
            });
        });
    });
})();
