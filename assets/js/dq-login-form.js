/**
 * DQ Login Form JavaScript
 * Handles AJAX login submission for Dominus QuickBooks
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        var $form = $('#dq-login-form');
        var $submitBtn = $('#dq-login-submit');
        var $errorContainer = $('#dq-login-error');
        var $buttonText = $submitBtn.find('.button-text');
        var $buttonLoading = $submitBtn.find('.button-loading');

        // Handle form submission
        $form.on('submit', function(e) {
            e.preventDefault();

            // Get form values
            var username = $('#dq-login-username').val().trim();
            var password = $('#dq-login-password').val();
            var remember = $('#dq-login-remember').is(':checked') ? '1' : '0';

            // Basic validation
            if (!username || !password) {
                showError('Please enter both username and password.');
                return;
            }

            // Disable form and show loading state
            setLoading(true);
            hideError();

            // Send AJAX request
            $.ajax({
                url: dqLoginVars.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dq_login_submit',
                    nonce: dqLoginVars.nonce,
                    username: username,
                    password: password,
                    remember: remember
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message briefly, then redirect
                        $errorContainer
                            .removeClass('dq-login-error')
                            .addClass('dq-login-success')
                            .html(response.data.message)
                            .show();

                        // Redirect to account page
                        setTimeout(function() {
                            window.location.href = response.data.redirectUrl || dqLoginVars.redirectUrl;
                        }, 500);
                    } else {
                        // Show error message
                        showError(response.data.message || 'Login failed. Please try again.');
                        setLoading(false);
                    }
                },
                error: function(xhr, status, error) {
                    var message = 'An error occurred. Please try again.';
                    
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        message = xhr.responseJSON.data.message;
                    }
                    
                    showError(message);
                    setLoading(false);
                }
            });
        });

        /**
         * Show error message
         */
        function showError(message) {
            $errorContainer
                .removeClass('dq-login-success')
                .addClass('dq-login-error')
                .html(message)
                .slideDown(200);
        }

        /**
         * Hide error message
         */
        function hideError() {
            $errorContainer.slideUp(200);
        }

        /**
         * Set loading state
         */
        function setLoading(loading) {
            if (loading) {
                $submitBtn.prop('disabled', true);
                $buttonText.hide();
                $buttonLoading.show();
                $form.find('input').prop('readonly', true);
            } else {
                $submitBtn.prop('disabled', false);
                $buttonText.show();
                $buttonLoading.hide();
                $form.find('input').prop('readonly', false);
            }
        }

        // Clear error when user starts typing
        $form.find('input').on('input', function() {
            if ($errorContainer.is(':visible')) {
                hideError();
            }
        });
    });
})(jQuery);
