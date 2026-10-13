/**
 * Exploration page root: request state, URL hash sync, debounced data fetch.
 *
 * Child components (tables, charts, pickers) read `state`, `result`, `loading`, `error`, and are
 * registered through `addChild({template: '...'})` or `addChild({html: '...'})`; they mutate state via `update(patch)`.
 */
define([
    'jquery',
    'ko',
    'mage/translate',
    'MageOS_AdvancedReport/js/report/lib/state-codec',
    'MageOS_AdvancedReport/js/report/lib/api',
    'MageOS_AdvancedReport/js/report/lib/dropdown',
    'MageOS_AdvancedReport/js/report/toolbar/period-picker',
    'MageOS_AdvancedReport/js/report/toolbar/compare-picker',
    'MageOS_AdvancedReport/js/report/toolbar/field-picker',
    'MageOS_AdvancedReport/js/report/toolbar/filter-chips',
    'MageOS_AdvancedReport/js/report/actions/actions-menu',
    'MageOS_AdvancedReport/js/report/results/chart',
    'MageOS_AdvancedReport/js/report/results/table',
    'MageOS_AdvancedReport/js/report/results/counted-statuses',
    'MageOS_AdvancedReport/js/report/toolbar/status',
    'MageOS_AdvancedReport/js/report/actions/refresh-action',
    'text!MageOS_AdvancedReport/template/report/app.html',
    'text!MageOS_AdvancedReport/template/report/child.html'
], function ($, ko, $t, codec, api, dropdown, createPeriod, createCompare, createFields, createFilters,
    createActionsMenu, createChart, createTable, createCountedStatuses, createStatus, createRefresh,
    appTemplate, childTemplate) {
    'use strict';

    var DEBOUNCE_MS = 300,
        PAGE_SIZE = 50,
        NOTICE_MS = 5000,
        DEFAULT_REGION = 'results',
        REGIONS = ['actions', 'toolbar', 'toolbarEnd', 'chips', 'info', 'results'],
        CHILD_PLACEHOLDER = '%CHILD%',
        ACTIONS_MOUNT = '#advancedreport-actions',
        template = appTemplate.split(CHILD_PLACEHOLDER).join(childTemplate);

    /**
     * Magento's KO bootstrap runs a global ko.applyBindings() on domReady; this binding stops it from
     * descending into the app root, which is bound separately with its own view model.
     */
    ko.bindingHandlers.advancedreportIsolate = {
        /**
         * @return {Object}
         */
        init: function () {
            return {controlsDescendantBindings: true};
        }
    };

    /**
     * @param {Object} config
     * @param {HTMLElement} element
     * @return {Object}
     */
    return function (config, element) {
        var restored = codec.decode(window.location.hash) ||
                (window.location.hash.indexOf('r=') === -1 ? api.popPendingState() : null),
            timer = null,
            noticeTimer = null,
            inflight = null,
            bypassNext = false,
            model = {
                config: config,
                state: ko.observable(restored || config.request),
                result: ko.observable(null),
                loading: ko.observable(false),
                error: ko.observable(null),
                locale: (config.locale || document.documentElement.lang || '').replace('_', '-') || undefined,
                children: ko.observableArray([]),
                regions: {},
                notice: ko.observable(null),
                pageSize: PAGE_SIZE,
                // Inline child markup has no template name; Magento's remote engine requires one.
                nativeTemplateEngine: ko.nativeTemplateEngine.instance,
                labels: {
                    reload: $t('Reload page'),
                    close: $t('Close')
                }
            };

        /**
         * @param {Object} request
         * @return {Object}
         */
        function withoutBypass(request) {
            var copy = $.extend({}, request);

            delete copy.bypassCache;

            return copy;
        }

        function syncHash() {
            var hash = '#' + codec.encode(withoutBypass(model.state()));

            if (window.location.hash !== hash) {
                window.history.replaceState(null, '', hash);
            }
        }

        function run() {
            var request = withoutBypass(model.state()),
                call;

            if (inflight) {
                inflight.xhr.abort();
            }

            // Presets and saved reports carry no limit; the table pages by PAGE_SIZE, so fetch one page.
            if (!request.limit) {
                request.limit = PAGE_SIZE;
            }

            if (bypassNext) {
                request.bypassCache = true;
                bypassNext = false;
            }

            model.loading(true);
            call = api.fetch(config.urls.data, request);
            inflight = call;
            call.promise.done(function (result) {
                model.error(null);
                model.result(result);
            }).fail(function (failure) {
                if (!failure.aborted) {
                    model.error({message: $t(failure.message), reload: failure.reload});
                }
            }).always(function () {
                if (inflight === call) {
                    inflight = null;
                    model.loading(false);
                }
            });
        }

        function schedule() {
            window.clearTimeout(timer);
            timer = window.setTimeout(run, DEBOUNCE_MS);
        }

        /**
         * @param {Object} patch
         */
        model.update = function (patch) {
            model.state($.extend({}, model.state(), patch));
        };

        /**
         * @param {Object|Array} child One child or a (nested) list; a child's own `region` wins.
         * @param {String} [defaultRegion]
         */
        model.addChild = function (child, defaultRegion) {
            [].concat(child).forEach(function (item) {
                if (Array.isArray(item)) {
                    model.addChild(item, defaultRegion);

                    return;
                }

                if (item.html) {
                    item.nodes = $.parseHTML(item.html.trim());
                }
                item.region = item.region || defaultRegion || DEFAULT_REGION;
                model.children.push(item);
            });
        };

        /**
         * @param {String} text
         * @param {String} [type] 'success' (auto-hides) or 'error' (persists)
         */
        model.notify = function (text, type) {
            var kind = type === 'error' ? 'error' : 'success';

            window.clearTimeout(noticeTimer);
            model.notice({text: text, type: kind});

            if (kind === 'success') {
                noticeTimer = window.setTimeout(model.dismissNotice, NOTICE_MS);
            }
        };

        model.dismissNotice = function () {
            window.clearTimeout(noticeTimer);
            model.notice(null);
        };

        model.refreshNow = function () {
            bypassNext = true;
            window.clearTimeout(timer);
            run();
        };

        model.reloadPage = function () {
            window.location.reload();
        };

        model.state.subscribe(function () {
            syncHash();
            schedule();
        });

        REGIONS.forEach(function (region) {
            model.regions[region] = ko.pureComputed(function () {
                return model.children().filter(function (child) {
                    return child.region === region;
                });
            });
        });

        /**
         * Renders the actions region into the page-actions mount (outside the app root), if present.
         */
        function bindActions() {
            var mount = $(ACTIONS_MOUNT)[0];

            if (!mount) {
                return;
            }

            mount.setAttribute('data-bind', 'advancedreportIsolate: true');
            mount.innerHTML = '<div class="advancedreport-actions-inner" data-bind="foreach: regions.actions">' +
                childTemplate + '</div>';
            // Never bind the mount itself: its isolate binding would suppress the descendants.
            ko.applyBindings(model, mount.firstElementChild);
        }

        syncHash();
        $(element).attr('data-bind', 'advancedreportIsolate: true').html(template);
        ko.applyBindings(model, element.firstElementChild);
        bindActions();
        model.addChild([createPeriod(model), createCompare(model), createFields(model), createFilters(model)],
            'toolbar');
        model.addChild(createStatus(model), 'toolbarEnd');
        model.addChild([createRefresh(model), createActionsMenu(model)], 'actions');
        model.addChild(createCountedStatuses(model), 'info');
        model.addChild([createChart(model), createTable(model)], 'results');
        run();

        return model;
    };
});
