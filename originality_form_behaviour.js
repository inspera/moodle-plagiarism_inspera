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

    function reorderPluginSection(useEl) {
        // Safety checks
        if (!useEl || typeof useEl.closest !== 'function') return;

        // 1. Find the Container
        // Moodle 4.x usually puts content in .fcontainer inside a fieldset.
        var container = useEl.closest('.fcontainer');
        if (!container) {
            // Fallback for some themes/older Moodle or simplified teacher views
            var fieldset = useEl.closest('fieldset');
            if (fieldset) container = fieldset.querySelector('.fcontainer') || fieldset;
        }

        function moveAdvancedToBottom() {
            // Use the content container that actually collapses with the accordion.
            var content = fieldset.querySelector('.fcontainer') || fieldset;

            // FIX: Convert NodeList to Array to ensure .forEach works on all browsers
            var advancedItems = Array.prototype.slice.call(content.querySelectorAll('div.fitem.advanced'));

            if (!advancedItems.length) {
                return;
            }
        }

        // 3. FORCE MOVE TO BOTTOM
        // We append them to the container, which moves them to the end of the list.
        if (advancedContent) {
            container.appendChild(advancedContent);
        }
        if (moreLessWrapper) {
            container.appendChild(moreLessWrapper);

            // Visual Styles for the link
            moreLessWrapper.style.display = 'block';
            moreLessWrapper.style.marginTop = '15px';
            moreLessWrapper.style.paddingTop = '10px';
        }

        // 4. Admin "Empty" Fix: Ensure click works
        // Moving DOM elements can break Moodle's existing event listeners.
        // We clone and replace the link to attach our own robust toggler.
        if (moreLessWrapper && advancedContent) {
            var link = moreLessWrapper.querySelector('a');
            if (link) {
                // Clone node to strip existing event listeners
                var newLink = link.cloneNode(true);
                link.parentNode.replaceChild(newLink, link);

                newLink.addEventListener('click', function(e) {
                    e.preventDefault();

                    // Toggle Logic
                    var isExpanded = newLink.getAttribute('aria-expanded') === 'true';
                    // Invert state
                    isExpanded = !isExpanded;

                    newLink.setAttribute('aria-expanded', isExpanded);
                    if (isExpanded) {
                        // Change text to "Show less..." (fallback logic if data attr missing)
                        newLink.innerHTML = newLink.getAttribute('data-less-text') || 'Show less...';

                        // Force display using multiple methods to satisfy different themes/BS versions
                        advancedContent.classList.remove('hide');
                        advancedContent.classList.add('show');
                        advancedContent.style.display = 'block';
                    } else {
                        // Save "Show less" text for later if not saved
                        if (!newLink.getAttribute('data-less-text') && newLink.innerHTML.indexOf('Show') === -1) {
                            newLink.setAttribute('data-less-text', newLink.innerHTML);
                        }
                        // Revert text
                        newLink.innerHTML = 'Show more...';

                        // Hide content
                        advancedContent.classList.remove('show');
                        advancedContent.classList.add('hide');
                        advancedContent.style.display = 'none';
                    }
                });
            }
        }

        // 5. Handle Visibility based on the 'use_originality' value
        function updateVisibility() {
            if (!moreLessWrapper) return;
            var val = '0';

            // Check value of input or select
            if (useEl.tagName.toLowerCase() === 'select') {
                val = String(useEl.value || '0');
            } else if (useEl.tagName.toLowerCase() === 'input') {
                // If hidden/text input (locked state)
                val = String(useEl.value || '0');
            }

            // If plugin disabled (0), hide the "Show more" link
            moreLessWrapper.style.display = (val === '0') ? 'none' : 'block';
        }

        // Listen for changes if it's a select
        if (useEl.tagName.toLowerCase() === 'select') {
            useEl.addEventListener('change', updateVisibility);
        }

        // Initial run
        updateVisibility();
        setTimeout(updateVisibility, 300);
    }

    onReadyAdv(function() {
        // FIX: Look for both SELECT and INPUT (for frozen/locked states)
        // This ensures the script runs for Teachers who see a locked hidden input.
        var selectors = Array.prototype.slice.call(document.querySelectorAll('select[id^="id_use_originality"], input[id^="id_use_originality"]'));

        // Fallback to name if ID lookup fails
        if (!selectors.length) {
            var byName = document.querySelectorAll('select[name^="use_originality"], input[name="use_originality"]');
            selectors = Array.prototype.slice.call(byName);
        }

        // Filter out duplicates (input type=hidden might duplicate if not careful)
        var uniqueSelectors = [];
        selectors.forEach(function(el) {
            if (uniqueSelectors.indexOf(el) === -1) uniqueSelectors.push(el);
        });

        uniqueSelectors.forEach(reorderPluginSection);

        // Safety: Re-run on window load in case of theme delay
        window.addEventListener('load', function() {
            uniqueSelectors.forEach(reorderPluginSection);
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
