/**
 * Inline Edit Workorder Fields
 * 
 * Handles inline editing of workorder meta fields on the front-end.
 * Requires DQQBInlineEdit object to be localized with ajax_url and nonce.
 */
(function() {
    'use strict';

    // Ensure localized data is available
    if (typeof DQQBInlineEdit === 'undefined') {
        return;
    }

    var ajaxUrl = DQQBInlineEdit.ajax_url;
    var nonce = DQQBInlineEdit.nonce;

    document.addEventListener('DOMContentLoaded', function() {
        var editButtons = document.querySelectorAll('.dqqb-inline-edit-btn');

        editButtons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var card = btn.closest('.wo-meta-card');
                if (!card) return;

                var displayEl = card.querySelector('.dqqb-inline-display');
                var editorEl = card.querySelector('.dqqb-inline-editor');

                if (displayEl && editorEl) {
                    displayEl.style.display = 'none';
                    editorEl.style.display = 'block';
                    var input = editorEl.querySelector('.dqqb-inline-input');
                    if (input) {
                        input.focus();
                        input.select();
                    }
                }
            });
        });

        // Cancel buttons
        var cancelButtons = document.querySelectorAll('.dqqb-inline-cancel');
        cancelButtons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var card = btn.closest('.wo-meta-card');
                if (!card) return;

                var displayEl = card.querySelector('.dqqb-inline-display');
                var editorEl = card.querySelector('.dqqb-inline-editor');
                var input = editorEl.querySelector('.dqqb-inline-input');

                // Reset input to original value from data attribute
                if (input && editorEl) {
                    var originalValue = editorEl.getAttribute('data-original') || '';
                    input.value = originalValue;
                }

                if (displayEl && editorEl) {
                    editorEl.style.display = 'none';
                    displayEl.style.display = 'flex';
                }

                // Clear any status messages
                var statusEl = card.querySelector('.dqqb-inline-status');
                if (statusEl) {
                    statusEl.textContent = '';
                    statusEl.className = 'dqqb-inline-status';
                }
            });
        });

        // Save buttons
        var saveButtons = document.querySelectorAll('.dqqb-inline-save');
        saveButtons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var card = btn.closest('.wo-meta-card');
                if (!card) return;

                var input = card.querySelector('.dqqb-inline-input');
                var statusEl = card.querySelector('.dqqb-inline-status');
                var field = card.getAttribute('data-field');
                var postId = card.getAttribute('data-post-id');

                if (!input || !field || !postId) {
                    return;
                }

                var newValue = input.value;

                // Show saving status
                if (statusEl) {
                    statusEl.textContent = 'Saving...';
                    statusEl.className = 'dqqb-inline-status dqqb-status-saving';
                }

                // Disable buttons during save
                btn.disabled = true;
                var cancelBtn = card.querySelector('.dqqb-inline-cancel');
                if (cancelBtn) cancelBtn.disabled = true;

                // Build form data
                var formData = new FormData();
                formData.append('action', 'dqqb_inline_update');
                formData.append('nonce', nonce);
                formData.append('post_id', postId);
                formData.append('field', field);
                formData.append('value', newValue);

                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    btn.disabled = false;
                    if (cancelBtn) cancelBtn.disabled = false;

                    if (data.success) {
                        // Use server-returned sanitized value
                        var savedValue = data.data && data.data.value !== undefined ? data.data.value : newValue;

                        // Update the display value with server-returned value
                        var valueEl = card.querySelector('.dqqb-inline-value');
                        if (valueEl) {
                            valueEl.textContent = savedValue || '—';
                        }

                        // Update the data-original attribute for future cancels
                        var editorEl = card.querySelector('.dqqb-inline-editor');
                        if (editorEl) {
                            editorEl.setAttribute('data-original', savedValue);
                        }

                        // Also update the input value to match server-returned value
                        if (input) {
                            input.value = savedValue;
                        }

                        // Show success status (with optional warning)
                        if (statusEl) {
                            if (data.data && data.data.warning) {
                                statusEl.textContent = data.data.warning;
                                statusEl.className = 'dqqb-inline-status dqqb-status-error';
                            } else {
                                statusEl.textContent = 'Saved!';
                                statusEl.className = 'dqqb-inline-status dqqb-status-success';
                            }
                        }

                        // Hide editor, show display
                        var displayEl = card.querySelector('.dqqb-inline-display');
                        if (displayEl && editorEl) {
                            editorEl.style.display = 'none';
                            displayEl.style.display = 'flex';
                        }

                        // Clear status after delay
                        setTimeout(function() {
                            if (statusEl) {
                                statusEl.textContent = '';
                                statusEl.className = 'dqqb-inline-status';
                            }
                        }, 2000);
                    } else {
                        // Show error
                        if (statusEl) {
                            statusEl.textContent = data.data || 'Error saving.';
                            statusEl.className = 'dqqb-inline-status dqqb-status-error';
                        }
                    }
                })
                .catch(function(error) {
                    btn.disabled = false;
                    if (cancelBtn) cancelBtn.disabled = false;

                    if (statusEl) {
                        statusEl.textContent = 'Network error.';
                        statusEl.className = 'dqqb-inline-status dqqb-status-error';
                    }
                });
            });
        });

        // Allow Enter key to save, Escape to cancel
        var inputs = document.querySelectorAll('.dqqb-inline-input');
        inputs.forEach(function(input) {
            input.addEventListener('keydown', function(e) {
                var card = input.closest('.wo-meta-card');
                if (!card) return;

                if (e.key === 'Enter') {
                    e.preventDefault();
                    var saveBtn = card.querySelector('.dqqb-inline-save');
                    if (saveBtn) saveBtn.click();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    var cancelBtn = card.querySelector('.dqqb-inline-cancel');
                    if (cancelBtn) cancelBtn.click();
                }
            });
        });
    });
})();
