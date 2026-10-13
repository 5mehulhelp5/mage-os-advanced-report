/**
 * Period selector child: toolbar dropdown with relative presets plus custom date range.
 */
define([
    'ko',
    'mage/translate',
    'text!MageOS_AdvancedReport/template/report/toolbar/period-picker.html'
], function (ko, $t, template) {
    'use strict';

    var CUSTOM = 'custom';

    /**
     * @param {Number} value
     * @return {String}
     */
    function pad(value) {
        return ('0' + value).slice(-2);
    }

    /**
     * @return {String} Today as Y-m-d in the browser time zone.
     */
    function today() {
        var date = new Date();

        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    /**
     * Build a local-calendar date from Y-m-d parts (never parse the string as UTC).
     *
     * @param {String} value
     * @param {String|undefined} locale
     * @return {String}
     */
    function formatDay(value, locale) {
        var parts = String(value || '').split('-').map(Number);

        if (parts.length !== 3 || parts.some(isNaN)) {
            return String(value || '');
        }

        return new Date(parts[0], parts[1] - 1, parts[2]).toLocaleDateString(locale);
    }

    /**
     * @param {Object} app
     * @return {Object}
     */
    return function (app) {
        var range = function () {
                return app.state().dateRange || {};
            },
            options = [
                {code: 'today', label: $t('Today')},
                {code: 'last_7_days', label: $t('Last 7 days')},
                {code: 'last_30_days', label: $t('Last 30 days')},
                {code: 'month_to_date', label: $t('Month to date')},
                {code: 'last_month', label: $t('Last month')},
                {code: 'year_to_date', label: $t('Year to date')}
            ],
            model = {
                html: template,
                label: $t('Period'),
                fromLabel: $t('From'),
                toLabel: $t('To'),
                applyLabel: $t('Apply'),
                customLabel: $t('Custom range'),
                invalidRangeMessage: $t('The start date must not be after the end date.'),
                options: options,
                open: ko.observable(false),
                customOpen: ko.observable(false),
                draftFrom: ko.observable(''),
                draftTo: ko.observable(''),
                error: ko.observable('')
            };

        model.type = ko.pureComputed(function () {
            return range().type;
        });
        model.isCustom = ko.pureComputed(function () {
            return range().type === CUSTOM;
        });
        model.buttonLabel = ko.pureComputed(function () {
            var current = range(),
                match;

            if (current.type === CUSTOM) {
                return formatDay(current.from, app.locale) + ' – ' + formatDay(current.to, app.locale);
            }

            match = options.filter(function (option) {
                return option.code === current.type;
            })[0];

            return match ? match.label : model.label;
        });

        /**
         * @param {Object} option
         */
        model.select = function (option) {
            model.customOpen(false);
            model.error('');
            app.update({dateRange: {type: option.code}, offset: 0});
            model.open(false);
        };

        model.isSelected = function (option) {
            return range().type === option.code;
        };

        model.showCustom = function () {
            model.draftFrom(range().from || today());
            model.draftTo(range().to || today());
            model.error('');
            model.customOpen(true);
        };

        model.apply = function () {
            var from = model.draftFrom(),
                to = model.draftTo();

            if (!from || !to || from > to) {
                model.error(model.invalidRangeMessage);

                return;
            }

            model.error('');
            app.update({dateRange: {type: CUSTOM, from: from, to: to}, offset: 0});
            model.customOpen(false);
            model.open(false);
        };

        model.open.subscribe(function (isOpen) {
            if (!isOpen) {
                model.customOpen(false);
                model.error('');
            }
        });

        return model;
    };
});
