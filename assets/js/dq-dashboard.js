/**
 * DQ Dashboard JavaScript
 * Handles AJAX pagination and menu interactions
 */
(function($) {
    'use strict';

    // Wait for DOM ready
    $(document).ready(function() {
        initDashboard();
    });

    function initDashboard() {
        var $wrapper = $('.dqqb-dashboard-wrapper');
        if (!$wrapper.length) return;

        // Dashboard table pagination
        $wrapper.on('click', '.dqqb-dashboard-pagination .dqqb-page-link:not(.disabled)', function(e) {
            e.preventDefault();
            var page = $(this).data('page');
            if (!page || page < 1) return;
            loadDashboardPage(page);
        });
    }

    /**
     * Load dashboard table page via AJAX
     */
    function loadDashboardPage(page) {
        var $wrapper = $('.dqqb-dashboard-wrapper');
        var $tableContainer = $('#dqqb-dashboard-table-container');
        var $pagination = $('.dqqb-dashboard-pagination');

        if (!$tableContainer.length || typeof dqDashboardVars === 'undefined') {
            return;
        }

        // Add loading state
        $wrapper.addClass('loading');

        $.ajax({
            url: dqDashboardVars.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dq_dashboard_paginate',
                nonce: dqDashboardVars.nonce,
                page: page
            },
            success: function(response) {
                if (response.success && response.data) {
                    // Update table
                    if (response.data.table) {
                        $tableContainer.html(response.data.table);
                    }

                    // Update pagination
                    if (response.data.pagination) {
                        $pagination.replaceWith(response.data.pagination);
                    } else {
                        $pagination.remove();
                    }

                    // Smooth scroll to table
                    $('html, body').animate({
                        scrollTop: $tableContainer.offset().top - 100
                    }, 300);
                }
            },
            error: function(xhr, status, error) {
                console.error('Dashboard AJAX error:', error);
            },
            complete: function() {
                $wrapper.removeClass('loading');
            }
        });
    }

})(jQuery);
