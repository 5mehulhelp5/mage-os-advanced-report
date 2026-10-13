/**
 * CSV export: a "Download CSV" modal whose button submits a real (non-AJAX) form POST
 * so the browser downloads the file.
 */
define([
    'jquery',
    'ko',
    'mage/translate',
    'Magento_Ui/js/modal/modal',
    'text!MageOS_AdvancedReport/template/report/actions/export-form.html'
], function ($, ko, $t, modal, template) {
    'use strict';

    /**
     * Hands the modal content element over to the model so the modal can be created lazily.
     */
    ko.bindingHandlers.advancedreportExportModal = {
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
        var self = {
            html: template,
            visible: !!(app.config.permissions || {}).canExport,
            action: app.config.urls.export,
            formKey: window.FORM_KEY,
            includeTotals: ko.observable(true),
            modalElement: null,
            formElement: null,
            modalReady: false,
            labels: {
                totals: $t('Include totals')
            },
            requestJson: ko.pureComputed(function () {
                var request = $.extend({}, app.state(), {limit: null, offset: 0});

                delete request.bypassCache;

                return JSON.stringify(request);
            })
        };

        self.registerModal = function (element) {
            self.modalElement = element;
            self.formElement = element.querySelector('form');
        };

        self.submit = function () {
            self.formElement.submit();
            $(self.modalElement).modal('closeModal');
        };

        self.openDownload = function () {
            if (!self.modalReady) {
                modal({
                    type: 'popup',
                    title: $t('Download CSV'),
                    modalClass: 'advancedreport-export-modal',
                    buttons: [{
                        text: $t('Download'),
                        class: 'action-primary',
                        click: self.submit
                    }]
                }, $(self.modalElement));
                self.modalReady = true;
            }

            $(self.modalElement).modal('openModal');
        };

        return self;
    };
});
