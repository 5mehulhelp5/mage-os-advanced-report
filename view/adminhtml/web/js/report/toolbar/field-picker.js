/**
 * Metric and dimension pickers (with granularity) plus the parent/simple SKU toggle.
 */
define([
    'ko',
    'mage/translate',
    'text!MageOS_AdvancedReport/template/report/toolbar/field-picker.html'
], function (ko, $t, template) {
    'use strict';

    var DEFAULT_GRANULARITY = 'day',
        SEARCH_THRESHOLD = 8,
        MAX_LABELS = 2;

    /**
     * @param {String} phrase Translated phrase with a %1 placeholder.
     * @param {String|Number} value
     * @return {String}
     */
    function fill(phrase, value) {
        return phrase.replace('%1', value);
    }

    /**
     * @param {Object} app
     * @return {Object}
     */
    return function (app) {
        var dataset = (app.config.datasets || []).filter(function (item) {
                return item.code === app.state().dataset;
            })[0] || {metrics: [], dimensions: []},
            alternate = app.config.alternateDimension || null,
            metricSearch = ko.observable(''),
            metrics,
            dimensions;

        /**
         * @return {Array}
         */
        function selectedDimensions() {
            return app.state().dimensions || [];
        }

        /**
         * @param {String} code
         * @return {Object|undefined}
         */
        function findDimension(code) {
            return selectedDimensions().filter(function (item) {
                return item.code === code;
            })[0];
        }

        /**
         * @param {Object} dimension Dimension metadata.
         * @return {String|null}
         */
        function defaultGranularity(dimension) {
            if (!dimension.granularities.length) {
                return null;
            }

            return dimension.granularities.indexOf(DEFAULT_GRANULARITY) !== -1 ?
                DEFAULT_GRANULARITY : dimension.granularities[0];
        }

        /**
         * Keeps sort valid after metrics change.
         *
         * @param {String[]} codes
         * @return {Object}
         */
        function metricsPatch(codes) {
            return {
                metrics: codes,
                sort: (app.state().sort || []).filter(function (sort) {
                    return codes.indexOf(sort.by) !== -1;
                }),
                offset: 0
            };
        }

        metrics = dataset.metrics.map(function (metric) {
            var selected = ko.pureComputed({
                /**
                     * @return {Boolean}
                     */
                read: function () {
                    return (app.state().metrics || []).indexOf(metric.code) !== -1;
                },

                /**
                     * @param {Boolean} value
                     */
                write: function (value) {
                    var current = app.state().metrics || [],
                        next = current.filter(function (code) {
                            return code !== metric.code;
                        });

                    if (value) {
                        next.push(metric.code);
                    } else if (!next.length) {
                        // At least one metric is required: revert the checkbox.
                        selected.notifySubscribers(true);

                        return;
                    }
                    app.update(metricsPatch(next));
                }
            });

            return {
                label: metric.label,
                description: metric.description || '',
                selected: selected
            };
        });
        dimensions = dataset.dimensions.map(function (dimension) {
            return {
                code: dimension.code,
                label: dimension.label,
                granularities: dimension.granularities,
                selected: ko.pureComputed({
                    /**
                         * @return {Boolean}
                         */
                    read: function () {
                        return !!findDimension(dimension.code);
                    },

                    /**
                         * @param {Boolean} value
                         */
                    write: function (value) {
                        var next = selectedDimensions().filter(function (item) {
                            return item.code !== dimension.code;
                        });

                        if (value) {
                            next.push({code: dimension.code, granularity: defaultGranularity(dimension)});
                        }
                        app.update({dimensions: next, offset: 0});
                    }
                }),
                granularity: ko.pureComputed({
                    /**
                         * @return {String|null}
                         */
                    read: function () {
                        var selected = findDimension(dimension.code);

                        return selected ? selected.granularity : null;
                    },

                    /**
                         * @param {String} value
                         */
                    write: function (value) {
                        app.update({
                            dimensions: selectedDimensions().map(function (item) {
                                return item.code === dimension.code ? {code: item.code, granularity: value} : item;
                            }),
                            offset: 0
                        });
                    }
                })
            };
        });

        return {
            html: template,
            labels: {
                metrics: $t('Metrics'),
                dimensions: $t('Group by'),
                granularity: $t('Granularity'),
                alternate: $t('Show simple SKUs'),
                searchMetrics: $t('Search metrics')
            },
            metrics: metrics,
            dimensions: dimensions,
            metricSearch: metricSearch,
            metricsOpen: ko.observable(false),
            dimensionsOpen: ko.observable(false),
            showSearch: metrics.length > SEARCH_THRESHOLD,
            visibleMetrics: ko.pureComputed(function () {
                var term = metricSearch().trim().toLowerCase();

                return metrics.filter(function (metric) {
                    return !term || metric.label.toLowerCase().indexOf(term) !== -1;
                });
            }),
            metricsButtonLabel: ko.pureComputed(function () {
                return fill($t('Metrics (%1)'), (app.state().metrics || []).length);
            }),
            metricsSummary: ko.pureComputed(function () {
                return fill($t('%1 selected'), (app.state().metrics || []).length);
            }),
            dimensionsButtonLabel: ko.pureComputed(function () {
                var labels = dimensions.filter(function (dimension) {
                        return dimension.selected();
                    }).map(function (dimension) {
                        return dimension.label;
                    }),
                    text = labels.slice(0, MAX_LABELS).join(', ');

                if (!labels.length) {
                    return $t('Group by: none');
                }

                if (labels.length > MAX_LABELS) {
                    text += ' ' + fill($t('+%1'), labels.length - MAX_LABELS);
                }

                return fill($t('Group by: %1'), text);
            }),
            hasAlternate: !!alternate,
            alternateOn: ko.pureComputed({
                /**
                 * @return {Boolean}
                 */
                read: function () {
                    return !!alternate && !!findDimension(alternate.alternate);
                },

                /**
                 * @param {Boolean} value
                 */
                write: function (value) {
                    var from = value ? alternate.primary : alternate.alternate,
                        to = value ? alternate.alternate : alternate.primary,
                        replaced = false,
                        next = selectedDimensions().map(function (item) {
                            if (item.code === from) {
                                replaced = true;

                                return {code: to, granularity: null};
                            }

                            return item;
                        });

                    if (!replaced && !findDimension(to)) {
                        next.push({code: to, granularity: null});
                    }
                    app.update({dimensions: next, offset: 0});
                }
            })
        };
    };
});
