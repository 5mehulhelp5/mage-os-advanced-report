/**
 * Status child of the exploration app (toolbar end): source badge, generated/cached time, loading spinner.
 */
define([
    'ko',
    'mage/translate',
    'text!MageOS_AdvancedReport/template/report/toolbar/status.html'
], function (ko, $t, template) {
    'use strict';

    /**
     * @param {String} iso
     * @param {String} [locale]
     * @return {String}
     */
    function formatTimestamp(iso, locale) {
        var date = new Date(iso);

        if (isNaN(date.getTime())) {
            return iso;
        }

        try {
            return date.toLocaleString(locale ? String(locale).replace('_', '-') : undefined);
        } catch (e) {
            return date.toLocaleString();
        }
    }

    /**
     * @param {Object} app
     * @return {Object}
     */
    return function (app) {
        var self = {
            html: template,
            region: 'toolbarEnd',
            loading: app.loading,
            labels: {loading: $t('Loading...')}
        };

        self.source = ko.pureComputed(function () {
            var result = app.result();

            return result ? {mysql: 'MySQL'}[result.source] || result.source : '';
        });

        self.cacheText = ko.pureComputed(function () {
            var result = app.result(),
                generated;

            if (!result) {
                return '';
            }

            generated = formatTimestamp(result.generated_at, app.locale);

            return result.cached ?
                $t('cached · generated at %1').replace('%1', generated) :
                $t('generated at %1').replace('%1', generated);
        });

        return self;
    };
});
