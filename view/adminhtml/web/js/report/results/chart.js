/**
 * Chart child of the exploration app: line chart for a date first dimension, else bar chart of the
 * first metric. Destroyed and recreated whenever the result changes.
 */
define([
    'ko',
    'chartjs/Chart.min',
    'MageOS_AdvancedReport/js/report/lib/format',
    'text!MageOS_AdvancedReport/template/report/results/chart.html'
], function (ko, Chart, formatter, template) {
    'use strict';

    var COLOR = '#1979c3',
        COMPARISON_COLOR = '#a6a6a6';

    /**
     * @param {Object} result
     * @return {{dimension: Object, metric: Object}|null}
     */
    function pickColumns(result) {
        var dimension = null,
            metric = null;

        (result.columns || []).forEach(function (column) {
            if (column.type === 'dimension' && !dimension) {
                dimension = column;
            } else if (column.type === 'metric' && !metric) {
                metric = column;
            }
        });

        return dimension && metric ? {dimension: dimension, metric: metric} : null;
    }

    /**
     * @param {Object} result
     * @param {Object} columns
     * @param {Boolean} isLine
     * @param {Object} context {currencyCode, locale}
     * @return {Object} Chart.js data
     */
    function buildData(result, columns, isLine, context) {
        var code = columns.metric.code,
            datasets = [{
                label: columns.metric.label,
                data: result.rows.map(function (row) {
                    return row.metrics[code];
                }),
                borderColor: COLOR,
                backgroundColor: isLine ? 'rgba(25, 121, 195, 0.1)' : COLOR,
                tension: 0.2
            }];

        if (isLine && result.rows.some(function (row) {
            return row.comparison && row.comparison[code];
        })) {
            datasets.push({
                label: columns.metric.label + ' (' +
                    formatter.formatDate(result.comparison_range.from, context.locale) + ')',
                data: result.rows.map(function (row) {
                    return row.comparison && row.comparison[code] ? row.comparison[code].value : null;
                }),
                borderColor: COMPARISON_COLOR,
                borderDash: [5, 5],
                tension: 0.2
            });
        }

        return {
            labels: result.rows.map(function (row) {
                var value = row.dims[columns.dimension.code];

                return isLine ? formatter.formatDate(value, context.locale) : value;
            }),
            datasets: datasets
        };
    }

    ko.bindingHandlers.advancedreportChart = {
        /**
         * @param {HTMLElement} element
         * @param {Function} valueAccessor
         */
        update: function (element, valueAccessor) {
            var config = valueAccessor(),
                result = config.result(),
                columns = result ? pickColumns(result) : null,
                isLine,
                context;

            if (element.chart) {
                element.chart.destroy();
                element.chart = null;
            }

            if (!columns || !result.rows.length) {
                return;
            }

            isLine = columns.dimension.format === 'date';
            context = {currencyCode: result.currency_code, locale: config.locale};
            element.chart = new Chart(element.getContext('2d'), {
                type: isLine ? 'line' : 'bar',
                data: buildData(result, columns, isLine, context),
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            ticks: {
                                /**
                                 * @param {Number} value
                                 * @return {String}
                                 */
                                callback: function (value) {
                                    return formatter.format(value, columns.metric.format, context);
                                }
                            }
                        }
                    }
                }
            });
        }
    };

    /**
     * @param {Object} app
     * @return {Object}
     */
    return function (app) {
        return {
            html: template,
            result: app.result,
            locale: app.locale,
            hasChart: ko.pureComputed(function () {
                var result = app.result();

                return !!result && !!pickColumns(result) && result.rows.length > 0;
            })
        };
    };
});
