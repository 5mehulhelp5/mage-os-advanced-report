/**
 * Actions-region child: one primary "Actions" dropdown holding Save as..., Update, Delete and Download CSV.
 */
define([
    'jquery',
    'ko',
    'mage/translate',
    'MageOS_AdvancedReport/js/report/actions/saved-report',
    'MageOS_AdvancedReport/js/report/actions/export-form',
    'text!MageOS_AdvancedReport/template/report/actions/actions-menu.html',
    'MageOS_AdvancedReport/js/report/lib/dropdown'
], function ($, ko, $t, createSaved, createExport, template) {
    'use strict';

    /**
     * @param {Object} app
     * @return {Object}
     */
    return function (app) {
        var saved = createSaved(app),
            exporter = createExport(app),
            self = {
                html: template,
                region: 'actions',
                saved: saved,
                exporter: exporter,
                savedNodes: $.parseHTML(saved.html.trim()),
                exportNodes: $.parseHTML(exporter.html.trim()),
                menuOpen: ko.observable(false),
                visible: saved.canSave || exporter.visible,
                labels: {
                    actions: $t('Actions'),
                    saveAs: saved.labels.saveAs,
                    update: saved.labels.update,
                    remove: saved.labels.remove,
                    download: $t('Download CSV')
                }
            };

        /**
         * @param {Function} handler
         * @return {Function}
         */
        function closingMenu(handler) {
            return function () {
                self.menuOpen(false);
                handler();
            };
        }

        self.openSaveAs = closingMenu(saved.openSaveAs);
        self.update = closingMenu(saved.update);
        self.remove = closingMenu(saved.remove);
        self.openDownload = closingMenu(exporter.openDownload);

        return self;
    };
});
