/**
 * Comparison selector child: toolbar dropdown.
 */
define([
    'ko',
    'mage/translate',
    'text!MageOS_AdvancedReport/template/report/toolbar/compare-picker.html'
], function (ko, $t, template) {
    'use strict';

    var NONE = 'none';

    /**
     * @param {Object} app
     * @return {Object}
     */
    return function (app) {
        var model = {
            html: template,
            label: $t('Compare to'),
            options: [
                {code: NONE, label: $t('No comparison')},
                {code: 'previous_period', label: $t('Previous period')},
                {code: 'previous_year', label: $t('Previous year')}
            ],
            open: ko.observable(false)
        };

        model.compareTo = ko.pureComputed({
            /**
             * @return {String}
             */
            read: function () {
                return app.state().compareTo || NONE;
            },

            /**
             * @param {String} value
             */
            write: function (value) {
                app.update({compareTo: value, offset: 0});
            }
        });
        model.isNone = ko.pureComputed(function () {
            return model.compareTo() === NONE;
        });
        model.buttonLabel = ko.pureComputed(function () {
            var current = model.compareTo(),
                match = model.options.filter(function (option) {
                    return option.code === current;
                })[0];

            if (!match || current === NONE) {
                return model.options[0].label;
            }

            return $t('Compare: %1').replace('%1', match.label);
        });

        /**
         * @param {Object} option
         */
        model.select = function (option) {
            model.compareTo(option.code);
            model.open(false);
        };

        model.isSelected = function (option) {
            return model.compareTo() === option.code;
        };

        return model;
    };
});
