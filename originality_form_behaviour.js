// Note: Keep original behaviour minimal. Sub-settings visibility is handled by Moodle's hideIf rules.

// Keep use_originality in sync with Assign group submissions (teamsubmission).
// Requirement: When teamsubmission is enabled, force use_originality = No (0) and freeze it.
// When disabled, re-enable and restore previous value if available.
(function(){
    'use strict';

    function ready(fn){
        if (document.readyState !== 'loading') return fn();
        document.addEventListener('DOMContentLoaded', fn);
    }

    function qAll(sel){ return Array.prototype.slice.call(document.querySelectorAll(sel)); }

    function findUseSelects(){
        var sels = qAll('select[id^="id_use_originality"], select[name^="use_originality"]');
        return sels;
    }

    function findTeamControls(){
        // Support both checkbox and select for teamsubmission.
        var ctrls = qAll('#id_teamsubmission, [name="teamsubmission"]');
        // Deduplicate if same element matched twice (O(n))
        return Array.from(new Set(ctrls));
    }

    function isEnabled(ctrl){
        if (!ctrl) return false;
        var tag = (ctrl.tagName || '').toLowerCase();
        if (tag === 'input') {
            if (ctrl.type === 'checkbox' || ctrl.type === 'radio') return !!ctrl.checked;
            return String(ctrl.value) === '1';
        }
        if (tag === 'select') {
            return String(ctrl.value) === '1';
        }
        return false;
    }

    function setDisabled(el, disabled){
        if (!el) return;
        if (disabled) el.setAttribute('disabled', 'disabled'); else el.removeAttribute('disabled');
    }

    function triggerChange(el){
        try { el.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
    }

    function applyState(teamCtrl, useSel){
        if (!useSel) return;
        var teamOn = teamCtrl ? isEnabled(teamCtrl) : false;

        if (teamOn) {
            // store previous non-zero choice once
            if (useSel.dataset.prevOriginality === undefined) {
                useSel.dataset.prevOriginality = String(useSel.value || '0');
            }
            if (String(useSel.value) !== '0') {
                useSel.value = '0';
                triggerChange(useSel);
            }
            setDisabled(useSel, true);
        } else {
            setDisabled(useSel, false);
            // restore only if we stored a previous value and it's not 0
            var prev = useSel.dataset.prevOriginality;
            if (prev !== undefined && prev !== '0') {
                // Only restore if the option exists
                var hasOpt = Array.prototype.some.call(useSel.options || [], function(o){ return String(o.value) === prev; });
                if (hasOpt) {
                    useSel.value = prev;
                    triggerChange(useSel);
                }
            }
        }
    }

    function applyStateToAll(teamCtrls, useSelects) {
        teamCtrls.forEach(function(teamCtrl){
            useSelects.forEach(function(useSel){
                applyState(teamCtrl, useSel);
            });
        });
    }

    function init(){
        var useSelects = findUseSelects();
        if (!useSelects.length) return;
        var teamCtrls = findTeamControls();

        // If no explicit teamsubmission control is found (e.g. editing existing activity with hidden form field),
        // still attempt to handle by reading potential hidden input value.
        if (!teamCtrls.length) {
            var hidden = document.querySelector('input[type="hidden"][name="teamsubmission"]');
            if (hidden) teamCtrls.push(hidden);
        }

        // If still none, do nothing.
        if (!teamCtrls.length) return;

        // Apply initial state across all controls.
        applyStateToAll(teamCtrls, useSelects);

        // Listen to changes on team controls
        teamCtrls.forEach(function(teamCtrl){
            teamCtrl.addEventListener('change', function(){
                applyStateToAll(teamCtrls, useSelects);
            });
        });

        // Also re-apply on window load to catch late UI changes.
        window.addEventListener('load', function(){
            applyStateToAll(teamCtrls, useSelects);
        });
    }

    ready(init);
})();

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
