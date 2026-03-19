/**
 * JavaScript for form behaviour in the Plagiarism Inspera plugin.
 *
 * @package     plagiarism_inspera
 * @copyright   2026 Inspera
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Note: Keep original behaviour minimal. Sub-settings visibility is handled by Moodle's hideIf rules.

// keep originality_draft_submit options in sync with submissiondrafts.
(function() {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') return fn();
        document.addEventListener('DOMContentLoaded', fn);
    }

    // --- HELPER: Find elements safely ---
    function q(sel, root) { return (root || document).querySelector(sel); }

    // --- DRAFT LOGIC (Unchanged) ---
    function getDraftsControl(root) {
        return q('#id_submissiondrafts', root) || q('select[name="submissiondrafts"]', root) || q('input[name="submissiondrafts"]', root);
    }
    function getDraftSubmitSelect(root) {
        return q('#id_originality_draft_submit', root) || q('select[name="originality_draft_submit"]', root);
    }
    function isDraftsEnabled(ctrl) {
        if (!ctrl) return null;
        var tag = (ctrl.tagName || '').toLowerCase();
        if (tag === 'select') return String(ctrl.value) === '1';
        if (tag === 'input') return (ctrl.type === 'checkbox' || ctrl.type === 'radio') ? !!ctrl.checked : String(ctrl.value) === '1';
        return null;
    }
    function syncDraftSubmitOptions() {
        var draftsCtrl = getDraftsControl(document);
        var select = getDraftSubmitSelect(document);
        if (!draftsCtrl || !select) return;
        var wantFinal = !!isDraftsEnabled(draftsCtrl);
        var finalValue = '1';

        if (!select.dataset.finalLabel) {
            var existing = Array.prototype.find.call(select.options || [], function(o){ return String(o.value) === finalValue; });
            if (existing) select.dataset.finalLabel = existing.text;
        }
        var finalOpt = Array.prototype.find.call(select.options || [], function(o){ return String(o.value) === finalValue; });
        if (wantFinal) {
            if (!finalOpt && select.dataset.finalLabel) {
                var opt = document.createElement('option');
                opt.value = finalValue;
                opt.text = select.dataset.finalLabel;
                select.add(opt);
            }
        } else if (finalOpt) {
            select.remove(finalOpt.index);
        }
    }

    // --- NEW INSPERA LOGIC ---

    // 1. EDIT MODE (Settings Page)
    function handleEditMode(configDiv) {
        var msg = configDiv.getAttribute('data-message');
        if (!msg) return;

        var alertDiv = document.createElement('div');
        alertDiv.id = 'inspera-group-warning';
        alertDiv.className = 'alert alert-danger';
        alertDiv.style.marginTop = '10px';
        alertDiv.style.display = 'none';
        alertDiv.innerHTML = msg;

        var groupSetting = document.querySelector('#id_teamsubmission');
        if (groupSetting) {
            var container = groupSetting.closest('.form-group') || groupSetting.closest('.fitem');
            if (container && container.parentNode) {
                container.parentNode.insertBefore(alertDiv, container.nextSibling);
            } else {
                var general = document.querySelector('#id_general');
                if (general) general.appendChild(alertDiv);
            }
        }

        function checkConflict() {
            var group = document.querySelector('#id_teamsubmission');
            var online = document.querySelector('#id_assignsubmission_onlinetext_enabled');
            if (!group || !online) return;

            if (group.checked && online.checked) {
                alertDiv.style.display = 'block';
            } else {
                alertDiv.style.display = 'none';
            }
        }

        var inputs = document.querySelectorAll('#id_teamsubmission, #id_assignsubmission_onlinetext_enabled');
        Array.prototype.forEach.call(inputs, function(el) {
            el.addEventListener('change', checkConflict);
        });
        checkConflict();
    }

    // 2. VIEW MODE (Grading Summary Page)
    function handleViewMode(configDiv) {
        var msg = configDiv.getAttribute('data-message');

        // INJECT WARNING (If conflict exists)
        if (msg && msg.length > 0) {
            var alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-danger alert-block fade in';
            alertDiv.style.marginBottom = '1rem';
            alertDiv.innerHTML = '<button type="button" class="close" data-dismiss="alert">×</button>' + msg;

            // TARGET: The specific container ".gradingsummarytable"
            var summaryTableBox = document.querySelector('.gradingsummarytable');

            if (summaryTableBox) {
                // Prepend: Puts it at the very top of the box, before the native warning.
                summaryTableBox.prepend(alertDiv);
            } else {
                // Fallback: If theme is weird, try the general summary box
                var summaryBox = document.querySelector('.gradingsummary');
                if (summaryBox) {
                    summaryBox.prepend(alertDiv);
                } else {
                    // Last resort: Main region
                    var main = document.querySelector('[role="main"]') || document.querySelector('#region-main');
                    if (main) main.prepend(alertDiv);
                }
            }
        }
    }

    // --- INITIALIZER ---
    ready(function() {
        // Drafts
        syncDraftSubmitOptions();
        var draftsCtrl = getDraftsControl(document);
        if (draftsCtrl) draftsCtrl.addEventListener('change', syncDraftSubmitOptions);
        window.addEventListener('load', syncDraftSubmitOptions);

        // Inspera
        var config = document.getElementById('inspera-warning-config');
        if (!config) return;

        var mode = config.getAttribute('data-mode');
        if (mode === 'edit') {
            handleEditMode(config);
        } else if (mode === 'view') {
            handleViewMode(config);
        }
    });

})();