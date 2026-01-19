// Note: Keep original behaviour minimal. Sub-settings visibility is handled by Moodle's hideIf rules.

// keep originality_draft_submit options in sync with submissiondrafts.
(function() {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') return fn();
        document.addEventListener('DOMContentLoaded', fn);
    }

    function q(sel, root) { return (root || document).querySelector(sel); }

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
        var finalValue = '1'; // FINAL
        var immediateValue = '0'; // IMMEDIATE

        // Cache label for FINAL once
        if (!select.dataset.finalLabel) {
            var existing = Array.prototype.find.call(select.options || [], function(o){ return String(o.value) === finalValue; });
            if (existing) {
                select.dataset.finalLabel = existing.text;
            }
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
            var wasSelected = String(select.value) === finalValue;
            select.remove(finalOpt.index);
            if (wasSelected) {
                // fallback to immediate
                var immediateOpt = Array.prototype.find.call(select.options || [], function(o){ return String(o.value) === immediateValue; });
                select.value = immediateOpt ? immediateValue : (select.options.length ? select.options[0].value : '');
                try { select.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
            }
        }
    }

    ready(function() {
        syncDraftSubmitOptions();
        var draftsCtrl = getDraftsControl(document);
        if (draftsCtrl) draftsCtrl.addEventListener('change', syncDraftSubmitOptions);
        window.addEventListener('load', syncDraftSubmitOptions);
    });
})();
