// Note: Keep original behaviour minimal. Sub-settings visibility is handled by Moodle's hideIf rules.

// Advanced items ordering and Show more/less visibility without impacting existing logic.
// This restores the previous behaviour where advanced fields are placed at the bottom
// and the more/less toggle is only visible when originality is enabled.
(function() {
    'use strict';

    function onReadyAdv(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function applyAdvancedEnhancementsForSelect(useSel) {
        if (!useSel || typeof useSel.closest !== 'function') {
            return;
        }

        var fieldset = useSel.closest('fieldset');
        if (!fieldset) {
            return;
        }

        function moveAdvancedToBottom() {
            // Use the content container that actually collapses with the accordion.
            var content = fieldset.querySelector('.fcontainer') || fieldset;

            // FIX: Convert NodeList to Array to ensure .forEach works on all browsers
            var advancedItems = Array.prototype.slice.call(content.querySelectorAll('div.fitem.advanced'));

            if (!advancedItems.length) {
                return;
            }

            // Keep action buttons at the very end if present
            var actionButtons = content.querySelector('div.fitem.action-buttons, div.fitem_fsubmit, div.form-actions');
            var anchor = actionButtons ? actionButtons : null;

            advancedItems.forEach(function(node) {
                if (anchor) {
                    content.insertBefore(node, anchor);
                } else {
                    content.appendChild(node);
                }
            });

            // Move the more/less control to sit above the advanced block
            var moreless = content.querySelector('.moreless-actions') || fieldset.querySelector('.moreless-actions');
            if (moreless) {
                if (anchor) {
                    content.insertBefore(moreless, anchor);
                } else {
                    content.appendChild(moreless);
                }
            }
        }

        function updateMoreLessVisibility() {
            var moreless = fieldset.querySelector('.moreless-actions');
            if (!moreless) {
                return;
            }
            var val = String(useSel.value || '0');

            // FIX: Simplified logic. Only hide if plugin is disabled.
            // We removed the unreliable isAccordionOpen() check.
            if (val === '0') {
                moreless.style.display = 'none';
            } else {
                moreless.style.display = '';
            }
        }

        // Initial run
        moveAdvancedToBottom();
        updateMoreLessVisibility();

        // React to changes of the originality enable select
        useSel.addEventListener('change', updateMoreLessVisibility);

        // Re-check visibility shortly after load to handle theme animations/Moodle js
        setTimeout(updateMoreLessVisibility, 150);
    }

    onReadyAdv(function() {
        var selectors = Array.prototype.slice.call(document.querySelectorAll('select[id^="id_use_originality"]'));
        if (!selectors.length) {
            var byName = document.querySelectorAll('select[name^="use_originality"]');
            selectors = Array.prototype.slice.call(byName);
        }

        selectors.forEach(applyAdvancedEnhancementsForSelect);

        window.addEventListener('load', function(){
            selectors.forEach(function(sel){
                applyAdvancedEnhancementsForSelect(sel);
            });
        });
    });
})();

// Keep use_originality in sync with Assign group submissions (teamsubmission).
(function(){
    'use strict';

    function ready(fn){
        if (document.readyState !== 'loading') return fn();
        document.addEventListener('DOMContentLoaded', fn);
    }

    function qAll(sel){ return Array.prototype.slice.call(document.querySelectorAll(sel)); }

    function findUseSelects(){
        return qAll('select[id^="id_use_originality"], select[name^="use_originality"]');
    }

    function findTeamControls(){
        var ctrls = qAll('#id_teamsubmission, [name="teamsubmission"]');
        // FIX: Removed "Array.from(new Set())" to ensure better compatibility
        var unique = [];
        ctrls.forEach(function(c) {
            if (unique.indexOf(c) === -1) unique.push(c);
        });
        return unique;
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
        // Safe dispatchEvent
        try {
            if (typeof Event === 'function') {
                el.dispatchEvent(new Event('change', { bubbles: true }));
            } else {
                // IE11 fallback (just in case)
                var evt = document.createEvent('HTMLEvents');
                evt.initEvent('change', true, true);
                el.dispatchEvent(evt);
            }
        } catch (e) {}
    }

    function applyState(teamCtrl, useSel){
        if (!useSel) return;
        var teamOn = teamCtrl ? isEnabled(teamCtrl) : false;

        if (teamOn) {
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
            var prev = useSel.dataset.prevOriginality;
            if (prev !== undefined && prev !== '0') {
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

        if (!teamCtrls.length) {
            var hidden = document.querySelector('input[type="hidden"][name="teamsubmission"]');
            if (hidden) teamCtrls.push(hidden);
        }
        if (!teamCtrls.length) return;

        applyStateToAll(teamCtrls, useSelects);

        teamCtrls.forEach(function(teamCtrl){
            teamCtrl.addEventListener('change', function(){
                applyStateToAll(teamCtrls, useSelects);
            });
        });

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
        var finalValue = '1';
        var immediateValue = '0';

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
