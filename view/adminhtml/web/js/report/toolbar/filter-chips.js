/**
 * Filter chips: type-aware operators and value inputs, driven by the dataset filter metadata.
 */
define([
    'ko',
    'mage/translate',
    'MageOS_AdvancedReport/js/report/lib/dropdown',
    'text!MageOS_AdvancedReport/template/report/toolbar/filter-chips.html',
    'text!MageOS_AdvancedReport/template/report/toolbar/filter-chips-row.html'
], function (ko, $t, dropdown, template, rowTemplate) {
    'use strict';

    var LIST_OPERATORS = ['in', 'nin'],
        NUMERIC_TYPES = ['money', 'integer', 'decimal'],
        OPERATOR_LABELS = {
            eq: '=',
            neq: '!=',
            'in': $t('is one of'),
            nin: $t('is none of'),
            gte: '>=',
            lte: '<=',
            between: $t('between')
        };

    /**
     * @param {String} type Field type.
     * @param {String} raw
     * @return {String|Number}
     */
    function coerce(type, raw) {
        var text = String(raw).trim();

        if (NUMERIC_TYPES.indexOf(type) !== -1 || type === 'boolean' || type === 'id' && /^\d+$/.test(text)) {
            return Number(text);
        }

        return text;
    }

    /**
     * @param {*} value
     * @return {String}
     */
    function displayValue(value) {
        if (Array.isArray(value)) {
            return value.join(', ');
        }

        return String(value);
    }

    /**
     * @param {Object} app
     * @return {Object[]} The toolbar popover child and the chips-row child, sharing one state.
     */
    return function (app) {
        var dataset = (app.config.datasets || []).filter(function (item) {
                return item.code === app.state().dataset;
            })[0] || {filters: []},
            self = {
                html: template,
                region: 'toolbar',
                open: ko.observable(false),
                fields: dataset.filters,
                labels: {
                    title: $t('Filters'),
                    filter: $t('Filter'),
                    clearAll: $t('Clear all'),
                    field: $t('Field'),
                    operator: $t('Operator'),
                    value: $t('Value'),
                    listHint: $t('Comma-separated values'),
                    add: $t('Add filter'),
                    remove: $t('Remove'),
                    invalid: $t('Enter a valid value for the filter.'),
                    yes: $t('Yes'),
                    no: $t('No')
                },
                field: ko.observable(dataset.filters.length ? dataset.filters[0] : null),
                operator: ko.observable(),
                value: ko.observable(''),
                valueTo: ko.observable(''),
                invalid: ko.observable(false)
            };

        self.operators = ko.pureComputed(function () {
            var field = self.field();

            return field ? field.operators.map(function (code) {
                return {code: code, label: OPERATOR_LABELS[code] || code};
            }) : [];
        });

        self.isBetween = ko.pureComputed(function () {
            return self.operator() === 'between';
        });

        self.isList = ko.pureComputed(function () {
            return LIST_OPERATORS.indexOf(self.operator()) !== -1;
        });

        /**
         * @return {String} HTML input type for the value input(s).
         */
        self.inputType = ko.pureComputed(function () {
            var type = self.field() ? self.field().type : 'string';

            if (self.isList()) {
                return 'text';
            }

            if (type === 'date') {
                return 'date';
            }

            return NUMERIC_TYPES.indexOf(type) !== -1 ? 'number' : 'text';
        });

        self.isBoolean = ko.pureComputed(function () {
            return !!self.field() && self.field().type === 'boolean' && !self.isList();
        });

        // Keep the operator valid for the selected field.
        self.field.subscribe(function (field) {
            self.operator(field ? field.operators[0] : undefined);
        });
        self.operator(self.field() ? self.field().operators[0] : undefined);

        /**
         * @param {String} code
         * @return {Object|undefined}
         */
        function findField(code) {
            return dataset.filters.filter(function (item) {
                return item.code === code;
            })[0];
        }

        self.chips = ko.pureComputed(function () {
            return (app.state().filters || []).map(function (filter, index) {
                var field = findField(filter.field);

                return {
                    index: index,
                    text: (field ? field.label : filter.field) + ' ' +
                        (OPERATOR_LABELS[filter.operator] || filter.operator) + ' ' +
                        (filter.operator === 'between' ? filter.value.join(' - ') : displayValue(filter.value))
                };
            });
        });

        self.count = ko.pureComputed(function () {
            return self.chips().length;
        });

        self.buttonText = ko.pureComputed(function () {
            return self.count() ? self.labels.filter + ' \u00b7 ' + self.count() : self.labels.filter;
        });

        /**
         * @return {Array|String|Number|null} Parsed value or null when the input is not valid.
         */
        function parseValue() {
            var type = self.field().type,
                values;

            if (self.isBetween()) {
                values = [self.value(), self.valueTo()];
            } else if (self.isList()) {
                values = String(self.value()).split(',');
            } else {
                values = [self.value()];
            }
            values = values.map(function (raw) {
                return String(raw).trim();
            });

            if (self.isList()) {
                values = values.filter(function (raw) {
                    return raw !== '';
                });
            }

            if (!values.length || values.some(function (raw) {
                return raw === '';
            })) {
                return null;
            }

            values = values.map(function (raw) {
                return coerce(type, raw);
            });

            if (values.some(function (item) {
                return typeof item === 'number' && isNaN(item);
            })) {
                return null;
            }

            return self.isBetween() || self.isList() ? values : values[0];
        }

        self.add = function () {
            var value;

            if (!self.field()) {
                return;
            }
            value = parseValue();

            if (value === null) {
                self.invalid(true);

                return;
            }
            self.invalid(false);
            app.update({
                filters: (app.state().filters || []).concat([{
                    field: self.field().code,
                    operator: self.operator(),
                    value: value
                }]),
                offset: 0
            });
            self.value('');
            self.valueTo('');
            self.open(false);
        };

        /**
         * @param {Object} chip
         */
        self.remove = function (chip) {
            app.update({
                filters: (app.state().filters || []).filter(function (item, index) {
                    return index !== chip.index;
                }),
                offset: 0
            });
        };

        self.clearAll = function () {
            app.update({filters: [], offset: 0});
        };

        return [self, {
            html: rowTemplate,
            region: 'chips',
            chips: self.chips,
            labels: self.labels,
            remove: self.remove,
            clearAll: self.clearAll
        }];
    };
});
