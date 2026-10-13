/**
 * Reusable dropdown/popover KO binding `advancedreportDropdown`.
 *
 * Usage: data-bind="advancedreportDropdown: {open: observable, align: 'right'}" on a root holding
 * `.advancedreport-dropdown-toggle` followed by `.advancedreport-dropdown-panel`.
 */
define([
    'jquery',
    'ko'
], function ($, ko) {
    'use strict';

    var ESC = 27,
        MODAL_SELECTOR = 'body._has-modal, .modals-wrapper .modal-slide._show, .modal-popup._show',
        registry = [],
        listening = false;

    /**
     * @return {Boolean}
     */
    function isModalOpen() {
        return $(MODAL_SELECTOR).length > 0;
    }

    /**
     * @param {Event} event
     */
    function onDocumentClick(event) {
        // Nodes detached by KO re-rendering inside a panel must not count as outside clicks.
        if (!document.documentElement.contains(event.target)) {
            return;
        }

        registry.slice().forEach(function (entry) {
            if (entry.isOpen() && !entry.root.contains(event.target)) {
                entry.close(false);
            }
        });
    }

    /**
     * @param {KeyboardEvent} event
     */
    function onDocumentKeydown(event) {
        if (event.key !== 'Escape' && event.keyCode !== ESC || isModalOpen()) {
            return;
        }

        registry.slice().forEach(function (entry) {
            if (entry.isOpen()) {
                entry.close(true);
            }
        });
    }

    function listen() {
        if (listening) {
            return;
        }

        listening = true;
        document.addEventListener('click', onDocumentClick);
        document.addEventListener('keydown', onDocumentKeydown);
    }

    ko.bindingHandlers.advancedreportDropdown = {
        /**
         * @param {HTMLElement} root
         * @param {Function} valueAccessor
         */
        init: function (root, valueAccessor) {
            var $root = $(root),
                toggle = root.querySelector('.advancedreport-dropdown-toggle'),
                panel = root.querySelector('.advancedreport-dropdown-panel'),
                value = valueAccessor(),
                params = value && typeof value === 'object' ? value : {},
                local = ko.observable(false),
                open = ko.isObservable(params.open) ? params.open : local,
                subscription,
                entry;

            /**
             * @param {Boolean} focusToggle
             */
            function close(focusToggle) {
                open(false);

                if (focusToggle && toggle) {
                    toggle.focus();
                }
            }

            entry = {
                root: root,
                close: close,
                isOpen: function () {
                    return !!open();
                }
            };

            $root.toggleClass('_align-right', params.align === 'right');

            /**
             * @param {Boolean} isOpen
             */
            function render(isOpen) {
                $root.toggleClass('_active', isOpen);

                if (toggle) {
                    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                }

                if (panel) {
                    panel.hidden = !isOpen;
                }
            }

            registry.push(entry);
            listen();
            render(!!open());

            if (toggle) {
                $(toggle).on('click.advancedreportDropdown', function () {
                    open(!open());
                });
            }

            subscription = open.subscribe(function (isOpen) {
                if (isOpen) {
                    registry.forEach(function (other) {
                        if (other !== entry && other.isOpen()) {
                            other.close(false);
                        }
                    });
                }
                render(!!isOpen);
            });

            ko.utils.domNodeDisposal.addDisposeCallback(root, function () {
                subscription.dispose();
                registry.splice(registry.indexOf(entry), 1);

                if (toggle) {
                    $(toggle).off('.advancedreportDropdown');
                }
            });
        }
    };
});
