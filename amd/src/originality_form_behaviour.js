/**
 * JavaScript for form behaviour in the Plagiarism Inspera plugin.
 *
 * @module      plagiarism_inspera/originality_form_behaviour
 * @copyright   2026 Inspera
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Helper: Find elements safely.
const q = (sel, root = document) => root.querySelector(sel);

// Draft logic.
const getDraftsControl = (root = document) => {
    return q('#id_submissiondrafts', root) ||
        q('select[name="submissiondrafts"]', root) ||
        q('input[name="submissiondrafts"]', root);
};

const getDraftSubmitSelect = (root = document) => {
    return q('#id_originality_draft_submit', root) ||
        q('select[name="originality_draft_submit"]', root);
};

const isDraftsEnabled = (ctrl) => {
    if (!ctrl) {
        return null;
    }
    const tag = (ctrl.tagName || '').toLowerCase();
    if (tag === 'select') {
        return String(ctrl.value) === '1';
    }
    if (tag === 'input') {
        return (ctrl.type === 'checkbox' || ctrl.type === 'radio') ? !!ctrl.checked : String(ctrl.value) === '1';
    }
    return null;
};

const syncDraftSubmitOptions = () => {
    const draftsCtrl = getDraftsControl();
    const select = getDraftSubmitSelect();

    if (!draftsCtrl || !select) {
        return;
    }

    const wantFinal = !!isDraftsEnabled(draftsCtrl);
    const finalValue = '1';

    if (!select.dataset.finalLabel) {
        const existing = Array.from(select.options || []).find(o => String(o.value) === finalValue);
        if (existing) {
            select.dataset.finalLabel = existing.text;
        }
    }

    const finalOpt = Array.from(select.options || []).find(o => String(o.value) === finalValue);

    if (wantFinal) {
        if (!finalOpt && select.dataset.finalLabel) {
            const opt = document.createElement('option');
            opt.value = finalValue;
            opt.text = select.dataset.finalLabel;
            select.add(opt);
        }
    } else if (finalOpt) {
        select.remove(finalOpt.index);
    }
};

// New Inspera logic.

// 1. Edit mode for settings page.
const handleEditMode = (configDiv) => {
    const msg = configDiv.getAttribute('data-message');
    if (!msg) {
        return;
    }

    const alertDiv = document.createElement('div');
    alertDiv.id = 'inspera-group-warning';
    alertDiv.className = 'alert alert-danger';
    alertDiv.style.marginTop = '10px';
    alertDiv.style.display = 'none';
    alertDiv.innerHTML = msg;

    const groupSetting = document.querySelector('#id_teamsubmission');
    if (groupSetting) {
        const container = groupSetting.closest('.form-group') || groupSetting.closest('.fitem');
        if (container && container.parentNode) {
            container.parentNode.insertBefore(alertDiv, container.nextSibling);
        } else {
            const general = document.querySelector('#id_general');
            if (general) {
                general.appendChild(alertDiv);
            }
        }
    }

    const checkConflict = () => {
        const group = document.querySelector('#id_teamsubmission');
        const online = document.querySelector('#id_assignsubmission_onlinetext_enabled');

        if (!group || !online) {
            return;
        }

        if (group.checked && online.checked) {
            alertDiv.style.display = 'block';
        } else {
            alertDiv.style.display = 'none';
        }
    };

    const inputs = document.querySelectorAll('#id_teamsubmission, #id_assignsubmission_onlinetext_enabled');
    inputs.forEach(el => el.addEventListener('change', checkConflict));

    // Initial check.
    checkConflict();
};

// 2. View mode for grading summary page.
const handleViewMode = (configDiv) => {
    const msg = configDiv.getAttribute('data-message');

    // Inject warning if conflict exists.
    if (msg && msg.length > 0) {
        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-danger alert-block fade in';
        alertDiv.style.marginBottom = '1rem';
        alertDiv.innerHTML = '<button type="button" class="close" data-dismiss="alert">×</button>' + msg;

        // Target the specific container for grading summary table.
        const summaryTableBox = document.querySelector('.gradingsummarytable');

        if (summaryTableBox) {
            // Prepend: Puts it at the very top of the box, before the native warning.
            summaryTableBox.prepend(alertDiv);
        } else {
            // Fallback: If theme is weird, try the general summary box.
            const summaryBox = document.querySelector('.gradingsummary');
            if (summaryBox) {
                summaryBox.prepend(alertDiv);
            } else {
                // Last resort: Main region.
                const main = document.querySelector('[role="main"]') || document.querySelector('#region-main');
                if (main) {
                    main.prepend(alertDiv);
                }
            }
        }
    }
};

/**
 * Initialize the module.
 * Moodle automatically calls this after the DOM is ready when requested via js_call_amd.
 */
export const init = () => {
    // Drafts.
    syncDraftSubmitOptions();
    const draftsCtrl = getDraftsControl();
    if (draftsCtrl) {
        draftsCtrl.addEventListener('change', syncDraftSubmitOptions);
    }

    // Fallback for dynamically rendered elements.
    window.addEventListener('load', syncDraftSubmitOptions);

    // Inspera.
    const config = document.getElementById('inspera-warning-config');
    if (!config) {
        return;
    }

    const mode = config.getAttribute('data-mode');
    if (mode === 'edit') {
        handleEditMode(config);
    } else if (mode === 'view') {
        handleViewMode(config);
    }
};
