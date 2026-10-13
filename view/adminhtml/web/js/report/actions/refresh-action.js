/**
 * Actions-region child: Back to the report list and Refresh (bypasses the cache).
 */
define([
    'mage/translate',
    'text!MageOS_AdvancedReport/template/report/actions/refresh-action.html'
], function ($t, template) {
    'use strict';

    /**
     * @param {Object} app
     * @return {Object}
     */
    return function (app) {
        var self = {
            html: template,
            region: 'actions',
            indexUrl: app.config.urls.index,
            loading: app.loading,
            refresh: app.refreshNow,
            labels: {back: $t('Back'), refresh: $t('Refresh')}
        };

        self.back = function () {
            window.location.href = self.indexUrl;
        };

        return self;
    };
});
