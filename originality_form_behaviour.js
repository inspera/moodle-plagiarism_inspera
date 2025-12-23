// Note: Keep original behaviour minimal. Sub-settings visibility is handled by Moodle's hideIf rules.

// Advanced items ordering and Show more/less visibility without impacting existing logic.
// This restores the previous behaviour where advanced fields are placed at the bottom
// and the more/less toggle is only visible when originality is enabled and the section is open.
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
        // The collapsible state may be applied to a parent with class 'collapsible'.
        var collapsibleContainer = useSel.closest('.collapsible') || fieldset;

        function moveAdvancedToBottom() {
            // Use the content container that actually collapses with the accordion.
            var content = fieldset.querySelector('.fcontainer') || fieldset;
            var advancedItems = content.querySelectorAll('div.fitem.advanced');
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

        function isElementVisible(el) {
            if (!el) return true;
            var style = window.getComputedStyle ? window.getComputedStyle(el) : el.currentStyle;
            if (!style) return true;
            if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') return false;
            // offsetParent check covers display:none ancestors
            if (el.offsetParent === null && style.position !== 'fixed') return false;
            return true;
        }

        function isAccordionOpen() {
            // Moodle collapsible fieldsets usually use an element with class 'collapsible'
            // and toggle the 'collapsed' class. Some themes toggle aria-expanded on a button.
            var container = collapsibleContainer;
            if (container && container.classList) {
                // If it explicitly has 'collapsed', treat as closed regardless of 'collapsible' presence.
                if (container.classList.contains('collapsed')) {
                    return false;
                }
                if (container.classList.contains('collapsible')) {
                    return true; // not collapsed above → open
                }
            }

            // Also check the fieldset itself for collapsed class (theme variations)
            if (fieldset && fieldset.classList && fieldset.classList.contains('collapsed')) {
                return false;
            }

            // Fallback 1: check aria-expanded on toggle button within the legend/header
            var toggleBtn = fieldset.querySelector('legend [aria-expanded], .fheader [aria-expanded]');
            if (toggleBtn && toggleBtn.getAttribute) {
                var expanded = toggleBtn.getAttribute('aria-expanded');
                if (expanded === 'true') { return true; }
                if (expanded === 'false') { return false; }
            }

            // Fallback 2: details/summary pattern
            var details = fieldset.closest('details');
            if (details) {
                return !!details.open;
            }

            // Fallback 3: determine by visibility of content container underneath legend
            var content = fieldset.querySelector('.fcontainer') || fieldset;
            return isElementVisible(content);
        }

        function updateMoreLessVisibility() {
            var moreless = fieldset.querySelector('.moreless-actions');
            if (!moreless) {
                return;
            }
            var val = String(useSel.value || '0');
            var accordionOpen = isAccordionOpen();
            // Show only when originality is enabled AND accordion is open.
            if (val === '0' || !accordionOpen) {
                moreless.style.display = 'none';
            } else {
                moreless.style.display = '';
            }
        }

        // Initial run
        moveAdvancedToBottom();
        updateMoreLessVisibility();
        // In case Moodle applies classes after DOMContentLoaded, re-check shortly after
        setTimeout(updateMoreLessVisibility, 0);
        setTimeout(updateMoreLessVisibility, 150);

        // React to changes of the originality enable select
        useSel.addEventListener('change', updateMoreLessVisibility);

        // React to accordion open/close.
        // 1) Clicks on header/legend may toggle the fieldset.
        var legend = fieldset.querySelector('legend, .fheader');
        if (legend) {
            legend.addEventListener('click', function() {
                // Allow Moodle's toggle handler to run, then update visibility.
                setTimeout(updateMoreLessVisibility, 0);
            });
        }

        // 2) Observe class changes on the collapsible container (collapsed <-> expanded).
        if (window.MutationObserver) {
            var obs = new MutationObserver(function(mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    if (mutations[i].attributeName === 'class') {
                        updateMoreLessVisibility();
                        break;
                    }
                }
            });
            if (collapsibleContainer) {
                obs.observe(collapsibleContainer, { attributes: true, attributeFilter: ['class'] });
            }
            if (fieldset && fieldset !== collapsibleContainer) {
                obs.observe(fieldset, { attributes: true, attributeFilter: ['class'] });
            }
        }

        // 3) As a safety net, listen for Bootstrap collapse events if present
        // (some Moodle themes use Bootstrap collapse on fieldset content)
        try {
            fieldset.addEventListener('shown.bs.collapse', updateMoreLessVisibility);
            fieldset.addEventListener('hidden.bs.collapse', updateMoreLessVisibility);
        } catch (e) { /* ignore if Bootstrap not present */ }
    }

    onReadyAdv(function() {
        // Support multiple controls: defaults form uses suffixed IDs
        var selectors = Array.prototype.slice.call(document.querySelectorAll('select[id^="id_use_originality"]'));
        if (!selectors.length) {
            // Fallback to name-based lookup if IDs differ
            var byName = document.querySelectorAll('select[name^="use_originality"]');
            selectors = Array.prototype.slice.call(byName);
        }

        selectors.forEach(applyAdvancedEnhancementsForSelect);

        // Re-evaluate once the window fully loads (all CSS/JS applied)
        window.addEventListener('load', function(){
            selectors.forEach(function(sel){
                // trigger a recalculation per fieldset
                applyAdvancedEnhancementsForSelect(sel);
            });
        });
    });
})();

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
