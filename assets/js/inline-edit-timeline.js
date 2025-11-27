/**
 * Inline Edit Timeline Fields
 * 
 * Handles inline editing of workorder timeline note and select fields.
 * Requires DQQBTimelineInlineEdit object to be localized with ajax_url and nonce.
 * This is separate from inline-edit-workorder.js to prevent security token and field collision errors.
 */
(function() {
    'use strict';

    // Ensure localized data is available
    if (typeof DQQBTimelineInlineEdit === 'undefined') {
        return;
    }

    var ajaxUrl = DQQBTimelineInlineEdit.ajax_url;
    var nonce = DQQBTimelineInlineEdit.nonce;

    /**
     * Get the input element (textarea or select) from the editor container
     */
    function getInputElement(editorEl) {
        return editorEl.querySelector('textarea.dqqb-inline-input, select.dqqb-inline-input');
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

    /**
     * Find the timeline card container (uses .dq-vtl-note-card or .dq-vtl-reason-card)
     */
    function getCardContainer(el) {
        return el.closest('.dq-vtl-note-card') || el.closest('.dq-vtl-reason-card');
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Only target timeline cards (note and reason cards)
        var timelineCards = document.querySelectorAll('.dq-vtl-note-card, .dq-vtl-reason-card');

        timelineCards.forEach(function(card) {
            var editBtn = card.querySelector('.dqqb-inline-edit-btn');
            if (!editBtn) return;

            editBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var displayEl = card.querySelector('.dqqb-inline-display');
                var editorEl = card.querySelector('.dqqb-inline-editor');

                if (displayEl && editorEl) {
                    displayEl.style.display = 'none';
                    editorEl.style.display = 'block';
                    var input = getInputElement(editorEl);
                    if (input) {
                        input.focus();
                        // Select text content for textarea, not for select elements
                        if (input.tagName !== 'SELECT' && typeof input.select === 'function') {
                            input.select();
                        }
                    }
                }
            });

            // Cancel button
            var cancelBtn = card.querySelector('.dqqb-inline-cancel');
            if (cancelBtn) {
                cancelBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var displayEl = card.querySelector('.dqqb-inline-display');
                    var editorEl = card.querySelector('.dqqb-inline-editor');
                    var input = getInputElement(editorEl);

                    // Reset input to original value
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
            }

            // Save button
            var saveBtn = card.querySelector('.dqqb-inline-save');
            if (saveBtn) {
                saveBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var editorEl = card.querySelector('.dqqb-inline-editor');
                    var input = getInputElement(editorEl);
                    var statusEl = card.querySelector('.dqqb-inline-status');
                    var field = card.getAttribute('data-field');
                    var postId = card.getAttribute('data-post-id');

                    if (!input || !field || !postId) {
                        return;
                    }

                    var newValue = getInputValue(input);

                    // Trim and normalize field name to prevent whitespace/casing mismatches
                    field = (field || '').trim().toLowerCase();

                    // Show saving status
                    if (statusEl) {
                        statusEl.textContent = 'Saving...';
                        statusEl.className = 'dqqb-inline-status dqqb-status-saving';
                    }

                    // Disable buttons during save
                    saveBtn.disabled = true;
                    if (cancelBtn) cancelBtn.disabled = true;

                    // Build form data
                    var formData = new FormData();
                    formData.append('action', 'dqqb_timeline_inline_update');
                    formData.append('nonce', nonce);
                    formData.append('post_id', postId);
                    formData.append('field', field);
                    formData.append('value', newValue);

                    // Debug logging for troubleshooting
                    console.log('[DQQB Timeline Inline Edit] Sending request:', { field: field, postId: postId, value: newValue.substring(0, 100) });

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
                        console.log('[DQQB Timeline Inline Edit] Response:', data);

                        saveBtn.disabled = false;
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
                            var displayEl = card.querySelector('.dqqb-inline-display');
                            if (displayEl) {
                                var valueEl = card.querySelector('.dqqb-inline-value');
                                if (valueEl) {
                                    if (displayText && displayText.trim() !== '') {
                                        valueEl.innerHTML = displayText;
                                    } else {
                                        // Show placeholder for empty values
                                        var isReasonField = card.classList.contains('dq-vtl-reason-card');
                                        valueEl.innerHTML = isReasonField 
                                            ? '<em class="dq-vtl-note-placeholder">Select reason...</em>'
                                            : '<em class="dq-vtl-note-placeholder">Add note...</em>';
                                    }
                                }
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
                    .catch(function() {
                        saveBtn.disabled = false;
                        if (cancelBtn) cancelBtn.disabled = false;

                        if (statusEl) {
                            statusEl.textContent = 'Network error.';
                            statusEl.className = 'dqqb-inline-status dqqb-status-error';
                        }
                    });
                });
            }

            // Allow Enter key to save (for select), Escape to cancel
            // For textarea, only Escape cancels (Enter creates newlines)
            var input = card.querySelector('.dqqb-inline-input');
            if (input) {
                input.addEventListener('keydown', function(e) {
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
            }
        });
    });
})();
