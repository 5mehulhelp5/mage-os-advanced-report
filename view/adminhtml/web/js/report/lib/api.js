/**
 * Report data endpoint client: form-key POST, cancellation, session/form-key expiry handling.
 */
define([
    'jquery',
    'MageOS_AdvancedReport/js/report/lib/state-codec'
], function ($, codec) {
    'use strict';

    var PENDING_KEY = 'mageos_advancedreport_pending_state',
        PENDING_NAME_KEY = 'mageos_advancedreport_pending_name',
        SERVER_MESSAGE_STATUSES = [400, 403, 504],
        GENERIC_ERROR = 'The report could not be loaded. Please try again.';

    /**
     * @param {Object} request
     * @param {String|undefined} name Unsaved "Save as" name.
     */
    function rememberPendingState(request, name) {
        try {
            window.sessionStorage.setItem(PENDING_KEY, codec.encode(request));

            if (name) {
                window.sessionStorage.setItem(PENDING_NAME_KEY, name);
            }
        } catch (e) {
            // Storage unavailable: state is simply not restored after login.
        }
    }

    return {
        /**
         * Returns and clears the state stored before a session-expiry redirect.
         *
         * @return {Object|null}
         */
        popPendingState: function () {
            var hash = null;

            try {
                hash = window.sessionStorage.getItem(PENDING_KEY);
                window.sessionStorage.removeItem(PENDING_KEY);
            } catch (e) {
                return null;
            }

            return codec.decode(hash);
        },

        /**
         * Returns and clears the "Save as" name stored before a session-expiry redirect.
         *
         * @return {String}
         */
        popPendingName: function () {
            var name = '';

            try {
                name = window.sessionStorage.getItem(PENDING_NAME_KEY) || '';
                window.sessionStorage.removeItem(PENDING_NAME_KEY);
            } catch (e) {
                return '';
            }

            return name;
        },

        /**
         * @param {String} url
         * @param {Object} request
         * @return {{xhr: Object, promise: Object}} Promise resolves with the result, rejects with
         *  {message: String, reload: Boolean, aborted: Boolean}.
         */
        fetch: function (url, request) {
            return this.send(url, {request: JSON.stringify(request)}, request);
        },

        /**
         * Form-key POST of arbitrary fields.
         *
         * @param {String} url
         * @param {Object} fields Form fields (`request` already JSON encoded).
         * @param {Object} request State to restore after a session-expiry redirect.
         * @return {{xhr: Object, promise: Object}}
         */
        send: function (url, fields, request) {
            var deferred = $.Deferred(),
                xhr = $.ajax({
                    url: url,
                    type: 'POST',
                    dataType: 'json',
                    data: $.extend({'form_key': window.FORM_KEY}, fields)
                });

            xhr.done(function (body) {
                if (body && body.ajaxExpired) {
                    rememberPendingState(request, fields.name);
                    window.location.replace(body.ajaxRedirect);

                    return;
                }

                if (body && body.error) {
                    deferred.reject({
                        message: body.message || GENERIC_ERROR,
                        reload: /form key/i.test(body.message || ''),
                        aborted: false
                    });

                    return;
                }
                deferred.resolve(body);
            }).fail(function (jqXhr, textStatus) {
                var body = jqXhr.responseJSON,
                    useServer = SERVER_MESSAGE_STATUSES.indexOf(jqXhr.status) !== -1 && body && body.message;

                deferred.reject({
                    message: useServer ? body.message : GENERIC_ERROR,
                    reload: false,
                    aborted: textStatus === 'abort'
                });
            });

            return {
                xhr: xhr,
                promise: deferred.promise()
            };
        }
    };
});
