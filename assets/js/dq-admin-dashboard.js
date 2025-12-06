/**
 * DQ Admin Dashboard JavaScript
 * Handles AJAX pagination for work orders table
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Handle pagination clicks
        $(document).on('click', '.dq-dashboard-pagination .dq-page-link', function(e) {
            e.preventDefault();
            
            var $link = $(this);
            if ($link.hasClass('disabled')) {
                return;
            }
            
            var page = parseInt($link.data('page'));
            if (!page || page < 1) {
                return;
            }
            
            loadPage(page);
        });
        
        /**
         * Load a specific page via AJAX
         */
        function loadPage(page) {
            var $container = $('#dq-admin-dashboard-table-container');
            var $pagination = $('.dq-dashboard-pagination');
            
            // Add loading state
            $container.css('opacity', '0.5');
            $pagination.css('opacity', '0.5');
            
            $.ajax({
                url: dqAdminDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dq_admin_dashboard_paginate',
                    nonce: dqAdminDashboard.nonce,
                    page: page
                },
                success: function(response) {
                    if (response.success) {
                        $container.html(response.data.table);
                        $pagination.replaceWith(response.data.pagination);
                        
                        // Scroll to top of table
                        $('html, body').animate({
                            scrollTop: $container.offset().top - 100
                        }, 300);
                    } else {
                        alert('Error loading data. Please try again.');
                    }
                },
                error: function() {
                    alert('Error loading data. Please try again.');
                },
                complete: function() {
                    $container.css('opacity', '1');
                    $('.dq-dashboard-pagination').css('opacity', '1');
                }
            });
        }
    });
})(jQuery);
