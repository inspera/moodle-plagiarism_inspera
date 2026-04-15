/**
 * Polling module for Inspera plagiarism reports.
 *
 * @module     plagiarism_inspera/polling
 * @copyright  2026 Inspera AS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {

    const POLLING_INTERVAL = 20000; // 20 seconds.

    let isPolling = false;
    const isTerminalStatus = status => status !== 'pending' && status !== 'report_requested';

    const poll = async () => {
        // 1. Find all pollable badges on the page (both pending and newly requested).
        const pendingItems = document.querySelectorAll(
            '[data-inspera-status="pending"], [data-inspera-status="report_requested"]'
        );
        if (pendingItems.length === 0) {
            // We are out of items! Let the loop die, but reset the flag
            // so a future PHP init() call can wake it back up if needed.
            isPolling = false;
            return;
        }

        const requests = [];
        const elements = []; // Track DOM elements so we can map the responses back to them.

        // 2. Build the batch array.
        pendingItems.forEach(item => {
            const recordId = item.dataset.recordid;
            if (recordId) {
                requests.push({
                    methodname: 'plagiarism_inspera_get_submission_status',
                    args: {
                        recordid: parseInt(recordId, 10),
                        displaytype: item.dataset.displaytype || 'similarity'
                    }
                });
                elements.push(item);
            }
        });

        if (requests.length === 0) {
            isPolling = false;
            return;
        }

        try {
            // 3. Fire exactly ONE HTTP Request. We use allSettled to ensure that one
            // failing record (e.g. 403 error) doesn't block the updates for all others.
            const results = await Promise.allSettled(Ajax.call(requests));

            // 4. Map the responses back to the correct HTML elements.
            results.forEach((result, index) => {
                const element = elements[index];

                // If this specific request failed, log it and skip to the next.
                if (result.status !== 'fulfilled') {
                    window.console.warn(
                        'Inspera: Polling request failed for record ID ' +
                        (element.dataset.recordid || 'unknown'),
                        result.reason
                    );
                    return;
                }

                // Compare the new status against the current DOM state.
                const response = result.value;
                const currentStatus = element.dataset.insperaStatus;
                const statusChanged = response.status !== currentStatus;

                if (statusChanged) {
                    // Create a temporary template element to parse the HTML string.
                    const template = document.createElement('template');
                    template.innerHTML = (response.html || '').trim();
                    const replacement = template.content.firstElementChild;

                    if (replacement) {
                        element.replaceWith(replacement);
                    } else if (isTerminalStatus(response.status)) {
                        // Fallback: if terminal-state HTML was empty, just stop polling it.
                        element.removeAttribute('data-inspera-status');
                    }
                }
            });

        } catch (error) {
            // This catch now only triggers if the entire Ajax infrastructure fails.
            window.console.warn('Inspera: Batch polling request failed.', error);
        }

        // 5. Schedule the next check.
        setTimeout(poll, POLLING_INTERVAL);
    };

    return {
        init: function() {
            if (isPolling) {
                return;
            }

            isPolling = true; // Mark it as active.
            setTimeout(poll, 2000); // Initial delay to let the page settle.
        }
    };
});
