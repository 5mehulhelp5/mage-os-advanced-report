/**
 * Results table child of the exploration app: server-side sort and pagination through the app
 * state, totals row, comparison deltas, tooltips, warnings, source badge and cache indicator.
 */
define([
    'ko',
    'mage/translate',
    'MageOS_AdvancedReport/js/report/lib/format',
    'text!MageOS_AdvancedReport/template/report/results/table.html'
], function (ko, $t, formatter, template) {
    'use strict';

    /**
     * @param {Object} app
     * @return {Object}
     */
    return function (app) {
        var self = {
            html: template,
            labels: {prev: $t('Previous'), next: $t('Next')}
        };

        /**
         * @return {Object}
         */
        function context() {
            var result = app.result();

            return {currencyCode: result ? result.currency_code : null, locale: app.locale};
        }

        /**
         * @param {Object} column
         * @param {Object|undefined} comparison {delta, delta_pct}
         * @param {String} [rangeFrom]
         * @return {Object|null}
         */
        function buildDelta(column, comparison, rangeFrom) {
            if (!comparison || comparison.delta_pct === null || comparison.delta_pct === undefined) {
                return null;
            }

            return {
                text: formatter.formatDeltaPct(comparison.delta_pct, app.locale),
                cssClass: formatter.deltaClass(comparison.delta, column.lowerIsBetter),
                title: $t('Previous period') + ': ' +
                    formatter.format(comparison.value, column.format, context()) +
                    (rangeFrom ? ' (' + formatter.formatDate(rangeFrom, app.locale) + ')' : '')
            };
        }

        /**
         * @param {Object} row
         * @param {String|null} from
         * @return {Function}
         */
        function cellBuilder(row, from) {
            return function (column) {
                var isMetric = column.type === 'metric',
                    value = isMetric ? row.metrics[column.code] : row.dims[column.code],
                    format = isMetric || column.format === 'date' ? column.format : null;

                return {
                    numeric: isMetric,
                    text: formatter.format(value, format, context()),
                    delta: isMetric ? buildDelta(column, (row.comparison || {})[column.code], from) : null
                };
            };
        }

        self.columns = ko.pureComputed(function () {
            var result = app.result();

            return result ? result.columns : [];
        });

        self.warnings = ko.pureComputed(function () {
            var result = app.result();

            return result ? result.warnings || [] : [];
        });

        self.rows = ko.pureComputed(function () {
            var result = app.result(),
                from = result && result.comparison_range ? result.comparison_range.from : null;

            if (!result) {
                return [];
            }

            return result.rows.map(function (row) {
                return {cells: result.columns.map(cellBuilder(row, from))};
            });
        });

        self.totalsCells = ko.pureComputed(function () {
            var result = app.result(),
                totals = result ? result.totals : null,
                from = result && result.comparison_range ? result.comparison_range.from : null,
                first = true;

            if (!totals || !totals.metrics || !result.rows.length) {
                return [];
            }

            return result.columns.map(function (column) {
                var cell;

                if (column.type === 'metric') {
                    return {
                        numeric: true,
                        text: formatter.format(totals.metrics[column.code], column.format, context()),
                        delta: buildDelta(column, (totals.comparison || {})[column.code], from)
                    };
                }

                cell = {numeric: false, text: first ? $t('Total') : '', delta: null};
                first = false;

                return cell;
            });
        });

        /**
         * @param {Object} column
         * @return {String|null}
         */
        self.sortDirection = function (column) {
            var sort = (app.state().sort || [])[0];

            return sort && sort.by === column.code ? sort.direction : null;
        };

        /**
         * @param {Object} column
         * @return {String}
         */
        self.ariaSort = function (column) {
            var direction = self.sortDirection(column);

            if (!direction) {
                return 'none';
            }

            return direction === 'asc' ? 'ascending' : 'descending';
        };

        /**
         * @param {Object} column
         */
        self.sortBy = function (column) {
            app.update({
                sort: [{by: column.code, direction: self.sortDirection(column) === 'desc' ? 'asc' : 'desc'}],
                offset: 0
            });
        };

        /**
         * @return {Number}
         */
        function limit() {
            return app.state().limit || app.pageSize;
        }

        /**
         * @return {Number}
         */
        function offset() {
            return app.state().offset || 0;
        }

        self.hasPrev = ko.pureComputed(function () {
            return offset() > 0;
        });

        self.hasNext = ko.pureComputed(function () {
            var result = app.result();

            return !!result && offset() + limit() < result.total_row_count;
        });

        self.hasPages = ko.pureComputed(function () {
            return self.hasPrev() || self.hasNext();
        });

        self.pageInfo = ko.pureComputed(function () {
            var result = app.result(),
                total = result ? result.total_row_count : 0,
                from = total ? offset() + 1 : 0,
                to = Math.min(offset() + limit(), total);

            return $t('%1-%2 of %3').replace('%1', from).replace('%2', to).replace('%3', total);
        });

        self.prevPage = function () {
            app.update({limit: limit(), offset: Math.max(0, offset() - limit())});
        };

        self.nextPage = function () {
            app.update({limit: limit(), offset: offset() + limit()});
        };

        return self;
    };
});
