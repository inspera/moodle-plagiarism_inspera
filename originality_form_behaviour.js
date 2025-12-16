(function() {
    'use strict';

    function onReady(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function applyEnhancementsForSelect(useSel) {
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

    onReady(function() {
        // Support multiple controls: defaults form uses suffixed IDs
        var selectors = Array.prototype.slice.call(document.querySelectorAll('select[id^="id_use_originality"]'));
        if (!selectors.length) {
            // Fallback to name-based lookup if IDs differ
            var byName = document.querySelectorAll('select[name^="use_originality"]');
            selectors = Array.prototype.slice.call(byName);
        }

        selectors.forEach(applyEnhancementsForSelect);

        // Re-evaluate once the window fully loads (all CSS/JS applied)
        window.addEventListener('load', function(){
            selectors.forEach(function(sel){
                // trigger a recalculation per fieldset
                applyEnhancementsForSelect(sel);
            });
        });
    });
})();
