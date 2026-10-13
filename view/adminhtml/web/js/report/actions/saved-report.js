/**
 * Saved report logic and modal: "Save as...", "Update" (owner only) and "Delete" with confirmation.
 */
define([
    'jquery',
    'ko',
    'mage/translate',
    'Magento_Ui/js/modal/confirm',
    'Magento_Ui/js/modal/modal',
    'MageOS_AdvancedReport/js/report/lib/api',
    'text!MageOS_AdvancedReport/template/report/actions/saved-report.html'
], function ($, ko, $t, confirm, modal, api, template) {
    'use strict';

    var ID_PLACEHOLDER = '__ID__';

    /**
     * @param {String} name
     */
    function updateTitle(name) {
        $('.page-title-wrapper .page-title').first().text(name);
        // The floatingHeader widget keeps its sticky-header copy of the title in a data attribute.
        $('.page-actions-inner[data-title]').attr('data-title', name);
        document.title = name;
    }

    /**
     * Hands the modal content element over to the model so the modal can be created lazily.
     */
    ko.bindingHandlers.advancedreportSaveModal = {
        /**
         * @param {HTMLElement} element
         * @param {Function} valueAccessor
         */
        init: function (element, valueAccessor) {
            valueAccessor()(element);
        }
    };

    /**
     * @param {Object} app
     * @return {Object}
     */
    return function (app) {
        var initial = app.config.saved,
            permissions = app.config.permissions || {},
            pendingName = api.popPendingName(),
            self = {
                html: template,
                canSave: !!permissions.canSave,
                canShare: !!permissions.canShare,
                saved: ko.observable(initial),
                name: ko.observable(pendingName || (initial ? initial.name : '')),
                shared: ko.observable(!!(initial && initial.is_shared)),
                busy: ko.observable(false),
                modalMessage: ko.observable(null),
                modalElement: null,
                modalReady: false,
                labels: {
                    save: $t('Save'),
                    name: $t('Report name'),
                    share: $t('Share with other admins'),
                    saveAs: $t('Save as...'),
                    update: $t('Update'),
                    remove: $t('Delete')
                }
            };

        self.isOwned = ko.pureComputed(function () {
            return !!self.saved() && !!self.saved().is_owner;
        });

        /**
         * @param {Number} id
         * @return {String}
         */
        function viewUrl(id) {
            return app.config.urls.view.replace(ID_PLACEHOLDER, id);
        }

        /**
         * @return {Object}
         */
        function definition() {
            var request = $.extend({}, app.state());

            delete request.bypassCache;
            request.offset = 0;

            return request;
        }

        /**
         * @param {String} text
         * @param {Boolean} inModal
         */
        function fail(text, inModal) {
            if (inModal) {
                self.modalMessage({error: true, text: text});
            } else {
                app.notify(text, 'error');
            }
        }

        /**
         * @param {Object} call
         * @param {Function} onDone
         * @param {Boolean} [inModal]
         */
        function run(call, onDone, inModal) {
            self.busy(true);
            self.modalMessage(null);
            call.promise.done(onDone).fail(function (failure) {
                fail($t(failure.message), !!inModal);
            }).always(function () {
                self.busy(false);
            });
        }

        /**
         * @param {Number|null} id
         */
        function save(id) {
            var inModal = !id,
                name = String(self.name()).trim(),
                fields;

            if (!name) {
                fail($t('Enter a name for the report.'), inModal);

                return;
            }
            fields = {
                name: name,
                'is_shared': self.canShare && self.shared() ? 1 : 0,
                'preset_code': app.state().preset || '',
                request: JSON.stringify(definition())
            };

            if (id) {
                fields.id = id;
            }

            run(api.send(app.config.urls.save, fields, definition()), function (result) {
                self.saved({id: result.id, name: result.name, 'is_shared': !!fields['is_shared'], 'is_owner': true});
                self.name(result.name);
                updateTitle(result.name);
                window.history.replaceState(null, '', viewUrl(result.id) + window.location.hash);

                if (inModal) {
                    $(self.modalElement).modal('closeModal');
                }
                app.notify($t('The report has been saved.'), 'success');
            }, inModal);
        }

        self.registerModal = function (element) {
            self.modalElement = element;
        };

        self.saveAs = function () {
            save(null);
        };

        self.openSaveAs = function () {
            self.modalMessage(null);

            if (!self.modalReady) {
                modal({
                    type: 'popup',
                    title: self.labels.saveAs,
                    modalClass: 'advancedreport-save-modal',
                    buttons: [{
                        text: self.labels.save,
                        class: 'action-primary',
                        click: self.saveAs
                    }]
                }, $(self.modalElement));
                self.modalReady = true;
            }

            $(self.modalElement).modal('openModal');
        };

        self.update = function () {
            save(self.saved().id);
        };

        self.remove = function () {
            var id = self.saved().id;

            confirm({
                title: $t('Delete report'),
                content: $t('Are you sure you want to delete this saved report?'),
                actions: {
                    /**
                     * Delete confirmed.
                     */
                    confirm: function () {
                        run(api.send(app.config.urls['delete'], {id: id}, definition()), function () {
                            window.location.href = app.config.urls.index;
                        });
                    }
                }
            });
        };

        return self;
    };
});
