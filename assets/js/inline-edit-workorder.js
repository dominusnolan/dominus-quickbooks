/**
 * Inline Edit Workorder Fields
 * 
 * Handles inline editing of workorder meta fields on the front-end.
 * Requires DQQBInlineEdit object to be localized with ajax_url and nonce.
 * Supports input, textarea, select controls, and contenteditable rich editor.
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
     * Get the input element (input, textarea, select, or contenteditable) from the editor container
     */
    function getInputElement(editorEl) {
        // Try standard inputs first
        var input = editorEl.querySelector('input.dqqb-inline-input, textarea.dqqb-inline-input, select.dqqb-inline-input');
        if (input) return input;
        // Fallback to contenteditable rich editor
        return editorEl.querySelector('.dqqb-rich-editor[contenteditable="true"]');
    }

    /**
     * Check if the element is a contenteditable element
     */
    function isContentEditable(el) {
        return el && el.getAttribute('contenteditable') === 'true';
    }

    /**
     * Get the value from an input element (handles contenteditable)
     */
    function getInputValue(inputEl) {
        if (!inputEl) return '';
        if (isContentEditable(inputEl)) {
            return inputEl.innerHTML;
        }
        return inputEl.value;
    }

    /**
     * Set the value on an input element (handles contenteditable)
     */
    function setInputValue(inputEl, value) {
        if (!inputEl) return;
        if (isContentEditable(inputEl)) {
            inputEl.innerHTML = value;
            return;
        }
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

    /**
     * Find the closest card-like container (supports both .wo-meta-card and .wo-private-comments)
     */
    function getCardContainer(el) {
        return el.closest('.wo-meta-card') || el.closest('.wo-private-comments');
    }

    document.addEventListener('DOMContentLoaded', function() {
        var editButtons = document.querySelectorAll('.dqqb-inline-edit-btn');

        editButtons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var card = getCardContainer(btn);
                if (!card) return;

                var displayEl = card.querySelector('.dqqb-inline-display');
                var editorEl = card.querySelector('.dqqb-inline-editor');

                if (displayEl && editorEl) {
                    displayEl.style.display = 'none';
                    editorEl.style.display = 'block';
                    var input = getInputElement(editorEl);
                    if (input) {
                        input.focus();
                        // Select text content for input/textarea, not for select or contenteditable elements
                        if (input.tagName !== 'SELECT' && !isContentEditable(input) && typeof input.select === 'function') {
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
                var card = getCardContainer(btn);
                if (!card) return;

                var displayEl = card.querySelector('.dqqb-inline-display');
                var editorEl = card.querySelector('.dqqb-inline-editor');
                var input = getInputElement(editorEl);

                // Reset input to original value
                if (input && editorEl) {
                    var originalValue;
                    // For rich editor, use hidden textarea to store original value
                    var originalTextarea = editorEl.querySelector('.dqqb-original-value');
                    if (originalTextarea) {
                        originalValue = originalTextarea.value || '';
                    } else {
                        // For other fields, use data-original attribute
                        originalValue = editorEl.getAttribute('data-original') || '';
                    }
                    setInputValue(input, originalValue);
                }

                if (displayEl && editorEl) {
                    editorEl.style.display = 'none';
                    // Use 'block' for private comments display, 'flex' for meta cards
                    displayEl.style.display = card.classList.contains('wo-private-comments') ? 'block' : 'flex';
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
                var card = getCardContainer(btn);
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
                var isRichEditor = isContentEditable(input);

                // Trim and normalize field name to prevent whitespace/casing mismatches
                field = (field || '').trim().toLowerCase();

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

                // Debug logging for troubleshooting
                console.log('[DQQB Inline Edit] Sending request:', { field: field, postId: postId, value: newValue.substring(0, 100) });

                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    // Debug logging for AJAX response
                    console.log('[DQQB Inline Edit] Response:', data);

                    btn.disabled = false;
                    if (cancelBtn) cancelBtn.disabled = false;

                    if (data.success) {
                        // Use server-returned sanitized value
                        var savedValue = data.data && data.data.value !== undefined ? data.data.value : newValue;
                        
                        // For select fields, use label if provided by server, otherwise use option text
                        // For rich editor (private_comments), use label which contains sanitized HTML
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
                        var displayEl = card.querySelector('.dqqb-inline-display');
                        if (displayEl) {
                            if (isRichEditor) {
                                // For rich editor, set innerHTML with sanitized HTML from server
                                displayEl.innerHTML = displayText || '<span class="dqqb-inline-value">—</span>';
                            } else {
                                // For regular fields, update the text content
                                var valueEl = card.querySelector('.dqqb-inline-value');
                                if (valueEl) {
                                    valueEl.textContent = displayText || '—';
                                }
                            }
                        }

                        // Update the data-original attribute or hidden textarea for future cancels
                        if (editorEl) {
                            var originalTextarea = editorEl.querySelector('.dqqb-original-value');
                            if (originalTextarea) {
                                originalTextarea.value = savedValue;
                            } else {
                                editorEl.setAttribute('data-original', savedValue);
                            }
                        }

                        // Also update the input value to match server-returned value
                        setInputValue(input, savedValue);

                        // Show success status
                        if (statusEl) {
                            statusEl.textContent = 'Saved!';
                            statusEl.className = 'dqqb-inline-status dqqb-status-success';
                        }

                        // Hide editor, show display
                        if (displayEl && editorEl) {
                            editorEl.style.display = 'none';
                            // Use 'block' for private comments display, 'flex' for meta cards
                            displayEl.style.display = card.classList.contains('wo-private-comments') ? 'block' : 'flex';
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
                .catch(function() {
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
        // For textarea and contenteditable, only Escape cancels (Enter creates newlines)
        var inputs = document.querySelectorAll('.dqqb-inline-input');
        inputs.forEach(function(input) {
            input.addEventListener('keydown', function(e) {
                var card = getCardContainer(input);
                if (!card) return;

                // For contenteditable and textarea, don't intercept Enter
                var isRich = isContentEditable(input);
                if (e.key === 'Enter' && input.tagName !== 'TEXTAREA' && !isRich) {
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

        // Rich editor toolbar functionality
        var toolbarButtons = document.querySelectorAll('.dqqb-rich-toolbar button[data-command]');
        toolbarButtons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var command = btn.getAttribute('data-command');
                if (!command) return;

                // Find the associated rich editor
                var wrapper = btn.closest('.dqqb-rich-editor-wrapper');
                if (!wrapper) return;
                var editor = wrapper.querySelector('.dqqb-rich-editor');
                if (!editor) return;

                // Focus the editor before executing command
                editor.focus();

                if (command === 'createLink') {
                    // Prompt for URL
                    var url = prompt('Enter URL:');
                    if (url) {
                        document.execCommand('createLink', false, url);
                    }
                } else {
                    // Execute standard command (bold, italic, underline, removeFormat)
                    document.execCommand(command, false, null);
                }
            });
        });
    });
})();
