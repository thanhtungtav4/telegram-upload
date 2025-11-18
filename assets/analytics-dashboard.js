/**
 * Telegram Analytics Dashboard Script
 */
(function() {
    'use strict';
    
    // Export analytics function
    window.exportAnalytics = function(type) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = ajaxurl;
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'telegram_export_analytics';
        
        const typeInput = document.createElement('input');
        typeInput.type = 'hidden';
        typeInput.name = 'type';
        typeInput.value = type;
        
        const nonceInput = document.createElement('input');
        nonceInput.type = 'hidden';
        nonceInput.name = 'nonce';
        nonceInput.value = telefiupAnalytics.exportNonce;
        
        form.appendChild(actionInput);
        form.appendChild(typeInput);
        form.appendChild(nonceInput);
        
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    };
    
    // Initialize chart when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        const chartCanvas = document.getElementById('downloadsChart');
        if (!chartCanvas || !window.Chart) return;
        
        const ctx = chartCanvas.getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: telefiupAnalytics.chartLabels,
                datasets: [{
                    label: 'Downloads',
                    data: telefiupAnalytics.chartData,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#3b82f6',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    });
})();
