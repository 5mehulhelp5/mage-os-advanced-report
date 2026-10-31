/**
 * Framework-agnostic value formatting by column format (money, percent, integer, number, date).
 */
define([], function () {
    'use strict';

    var EMPTY = '\u2014',
        OPTIONS = {
            money: {},
            percent: {style: 'percent', minimumFractionDigits: 1, maximumFractionDigits: 1},
            integer: {maximumFractionDigits: 0},
            number: {maximumFractionDigits: 2},
            decimal: {maximumFractionDigits: 2}
        },

        /**
         * Date bucket keys as returned by the API: hour (Y-m-d H:i), day/week (Y-m-d), month (Y-m).
         * Years and labelled values (e.g. "Monday", "14:00") match none and stay as they are.
         */
        DATE_PATTERNS = [
            {
                regex: /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})$/,
                options: {year: 'numeric', month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit'}
            },
            {regex: /^(\d{4})-(\d{2})-(\d{2})$/, options: {year: 'numeric', month: 'numeric', day: 'numeric'}},
            {regex: /^(\d{4})-(\d{2})$/, options: {year: 'numeric', month: 'numeric'}}
        ];

    /**
     * @param {String} [locale]
     * @return {String|undefined}
     */
    function normalizeLocale(locale) {
        return locale ? String(locale).replace('_', '-') : undefined;
    }

    /**
     * @param {Object} options
     * @param {String} [locale]
     * @return {Object}
     */
    function numberFormat(options, locale) {
        try {
            return new Intl.NumberFormat(normalizeLocale(locale), options);
        } catch (e) {
            return new Intl.NumberFormat(undefined, options);
        }
    }

    /**
     * Formats a date bucket key (store-local wall-clock) with the locale default date format.
     * The parts are formatted as UTC so the browser timezone and its DST never shift the label.
     *
     * @param {String} value
     * @param {String} [locale]
     * @return {String}
     */
    function formatDate(value, locale) {
        var text = String(value),
            pattern = DATE_PATTERNS.find(function (candidate) {
                return candidate.regex.test(text);
            }),
            parts,
            options,
            date;

        if (!pattern) {
            return text;
        }

        parts = pattern.regex.exec(text).slice(1).map(Number);
        date = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2] || 1, parts[3] || 0, parts[4] || 0));
        options = Object.assign({timeZone: 'UTC'}, pattern.options);
        try {
            return date.toLocaleString(normalizeLocale(locale), options);
        } catch (e) {
            return date.toLocaleString(undefined, options);
        }
    }

    return {
        EMPTY: EMPTY,
        formatDate: formatDate,

        /**
         * @param {*} value
         * @param {String|null} format money|percent|integer|number|date
         * @param {Object} [context] {currencyCode, locale}
         * @return {String}
         */
        format: function (value, format, context) {
            var ctx = context || {},
                options;

            if (value === null || value === undefined || value === '') {
                return EMPTY;
            }

            if (typeof value !== 'number' && OPTIONS[format]) {
                value = Number(value);
                if (isNaN(value)) {
                    return EMPTY;
                }
            }

            if (format === 'date') {
                return formatDate(value, ctx.locale);
            }

            options = OPTIONS[format];
            if (format === 'money') {
                options = ctx.currencyCode ? {style: 'currency', currency: ctx.currencyCode} : {};
            }

            if (!options) {
                return String(value);
            }

            return numberFormat(options, ctx.locale).format(value);
        },

        /**
         * Signed percentage for a ratio delta (0.234 => "+23.4%").
         *
         * @param {Number|null} ratio
         * @param {String} [locale]
         * @return {String}
         */
        formatDeltaPct: function (ratio, locale) {
            if (ratio === null || ratio === undefined) {
                return EMPTY;
            }

            return numberFormat({
                style: 'percent',
                minimumFractionDigits: 1,
                maximumFractionDigits: 1,
                signDisplay: 'exceptZero'
            }, locale).format(ratio);
        },

        /**
         * @param {Number|null} delta
         * @param {Boolean} lowerIsBetter
         * @return {String} positive|negative|neutral
         */
        deltaClass: function (delta, lowerIsBetter) {
            if (!delta) {
                return 'neutral';
            }

            return delta > 0 !== Boolean(lowerIsBetter) ? 'positive' : 'negative';
        }
    };
});
