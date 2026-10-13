/**
 * Counted statuses child of the exploration app (info region): muted line stating which order statuses are
 * counted, with a link to the configuration when the admin may open it.
 */
define([
    'ko',
    'mage/translate',
    'text!MageOS_AdvancedReport/template/report/results/counted-statuses.html'
], function (ko, $t, template) {
    'use strict';

    /**
     * @param {Object} app
     * @return {Object}
     */
    return function (app) {
        var self = {
            html: template,
            region: 'info',
            labels: {configure: $t('Change in configuration')}
        };

        self.text = ko.pureComputed(function () {
            return (app.result() || {}).counted_statuses || '';
        });

        self.configUrl = (app.config.urls || {}).config || '';

        return self;
    };
});
