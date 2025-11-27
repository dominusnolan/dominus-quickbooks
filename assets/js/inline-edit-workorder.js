/**
 * Inline Edit Workorder Fields
 * 
 * Handles inline editing of workorder meta fields on the front-end.
 * Requires DQQBInlineEdit object to be localized with ajax_url and nonce.
 * Supports input, textarea, and select controls.
 */
(function() {
    'use strict';

    // Ensure localized data is available
    if (typeof DQQBInlineEdit === 'undefined') {
        return;
    }

    var ajaxUrl = DQQBInlineEdit.ajax_url;
    var nonce = DQQBInlineEdit.nonce;

    /**
     * Get the input element (input, textarea, or select) from the editor container
     */
    function getInputElement(editorEl) {
        return editorEl.querySelector('input.dqqb-inline-input, textarea.dqqb-inline-input, select.dqqb-inline-input');
    }

    /**
     * Get the value from an input element
     */
    function getInputValue(inputEl) {
        if (!inputEl) return '';
        return inputEl.value;
    }

    /**
     * Set the value on an input element
     */
    function setInputValue(inputEl, value) {
        if (!inputEl) return;
        inputEl.value = value;
    }

    /**
     * Get the display text for a select element (the selected option's text)
     */
    function getSelectDisplayText(selectEl) {
        if (!selectEl || selectEl.tagName !== 'SELECT') return null;
        var selectedOption = selectEl.options[selectEl.selectedIndex];
        return selectedOption ? selectedOption.text : null;
    }

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
                    var input = getInputElement(editorEl);
                    if (input) {
                        input.focus();
                        if (input.select) {
                            input.select();
                        }
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
                var input = getInputElement(editorEl);

                // Reset input to original value from data attribute
                if (input && editorEl) {
                    var originalValue = editorEl.getAttribute('data-original') || '';
                    setInputValue(input, originalValue);
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

                var editorEl = card.querySelector('.dqqb-inline-editor');
                var input = getInputElement(editorEl);
                var statusEl = card.querySelector('.dqqb-inline-status');
                var field = card.getAttribute('data-field');
                var postId = card.getAttribute('data-post-id');

                if (!input || !field || !postId) {
                    return;
                }

                var newValue = getInputValue(input);

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
                        
                        // For select fields, use label if provided by server, otherwise use option text
                        var displayText = savedValue;
                        if (data.data && data.data.label) {
                            displayText = data.data.label;
                        } else if (input.tagName === 'SELECT') {
                            var optionText = getSelectDisplayText(input);
                            if (optionText && optionText !== '— Select —') {
                                displayText = optionText;
                            }
                        }

                        // Update the display value
                        var valueEl = card.querySelector('.dqqb-inline-value');
                        if (valueEl) {
                            valueEl.textContent = displayText || '—';
                        }

                        // Update the data-original attribute for future cancels
                        if (editorEl) {
                            editorEl.setAttribute('data-original', savedValue);
                        }

                        // Also update the input value to match server-returned value
                        setInputValue(input, savedValue);

                        // Show success status
                        if (statusEl) {
                            statusEl.textContent = 'Saved!';
                            statusEl.className = 'dqqb-inline-status dqqb-status-success';
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

        // Allow Enter key to save (for input and select), Escape to cancel
        // For textarea, only Escape cancels (Enter creates newlines)
        var inputs = document.querySelectorAll('.dqqb-inline-input');
        inputs.forEach(function(input) {
            input.addEventListener('keydown', function(e) {
                var card = input.closest('.wo-meta-card');
                if (!card) return;

                if (e.key === 'Enter' && input.tagName !== 'TEXTAREA') {
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
