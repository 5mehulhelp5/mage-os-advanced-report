/**
 * Framework-agnostic report state codec (no ko/jQuery): base64url JSON for the `#r=` URL hash.
 */
(function (root, factory) {
    'use strict';

    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else {
        root.MageOSReportStateCodec = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var PREFIX = 'r=',
        ARRAY_KEYS = ['metrics', 'dimensions', 'filters', 'sort'];

    /**
     * @param {String} text
     * @return {String}
     */
    function toBase64Url(text) {
        var bytes = encodeURIComponent(text).replace(/%([0-9A-F]{2})/g, function (match, hex) {
            return String.fromCharCode(parseInt(hex, 16));
        });

        return btoa(bytes).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    /**
     * @param {String} encoded
     * @return {String}
     */
    function fromBase64Url(encoded) {
        var base64 = encoded.replace(/-/g, '+').replace(/_/g, '/'),
            binary;

        while (base64.length % 4) {
            base64 += '=';
        }
        binary = atob(base64);

        return decodeURIComponent(binary.split('').map(function (char) {
            return '%' + ('00' + char.charCodeAt(0).toString(16)).slice(-2);
        }).join(''));
    }

    /**
     * @param {*} request
     * @return {Boolean}
     */
    function isValid(request) {
        if (!request || typeof request !== 'object' || Array.isArray(request)) {
            return false;
        }

        if (typeof request.dataset !== 'string' || request.dataset === '') {
            return false;
        }

        return ARRAY_KEYS.every(function (key) {
            return request[key] === undefined || Array.isArray(request[key]);
        });
    }

    return {
        /**
         * @param {Object} request
         * @return {String} Hash payload without the leading `#`, e.g. `r=...`.
         */
        encode: function (request) {
            return PREFIX + toBase64Url(JSON.stringify(request));
        },

        /**
         * @param {String} hash Location hash, with or without the leading `#`.
         * @return {Object|null} Request, or null when absent or invalid.
         */
        decode: function (hash) {
            var value = String(hash || '').replace(/^#/, ''),
                request;

            if (value.indexOf(PREFIX) !== 0) {
                return null;
            }

            try {
                request = JSON.parse(fromBase64Url(value.slice(PREFIX.length)));

                return isValid(request) ? request : null;
            } catch (e) {
                return null;
            }
        }
    };
});
