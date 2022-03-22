/**
 * @module package/quiqqer/backendsearch/bin/controls/Search
 * @author www.pcsg.de (Henning Leutz)
 */
define('package/quiqqer/backendsearch/bin/controls/Search', [

    'qui/controls/Control',
    'qui/controls/desktop/Panel',
    'qui/controls/windows/Popup',
    'qui/controls/buttons/Button',
    'qui/controls/loader/Loader',

    'package/quiqqer/backendsearch/bin/controls/FilterSelect',
    'utils/Panels',
    'Mustache',
    'Ajax',
    'Locale',

    'text!package/quiqqer/backendsearch/bin/controls/Search.html',
    'text!package/quiqqer/backendsearch/bin/controls/Search.ResultGroup.html',
    'css!package/quiqqer/backendsearch/bin/controls/Search.css'

], function (QUIControl, QUIPanel, QUIPopup, QUIButton, QUILoader,
             FilterSelect, PanelUtils, Mustache, QUIAjax, QUILocale, template,
             templateResultGroup
) {
    "use strict";

    const lg = 'quiqqer/backendsearch';

    return new Class({

        Type   : 'package/quiqqer/backendsearch/bin/controls/Search',
        Extends: QUIControl,

        Binds: [
            'close',
            'create',
            'open',
            'executeSearch',
            '$onInject',
            'changeEntryFocus',
            '$onWindowKeyUp',
            '$renderResult',
            'search'
        ],

        options: {
            delay: 200
        },

        initialize: function (options) {
            this.parent(options);

            this.$Elm       = null;
            this.$Input     = null;
            this.$Header    = null;
            this.$Close     = null;
            this.$Result    = null;
            this.$BtnSearch = null;

            this.$open           = false;
            this.$value          = false;
            this.$FilterSelect   = null;
            this.$extendedSearch = false;
            this.$Settings       = {};

            this.$execSearchOnNextFilterClose = false;

            this.$FilterSelectContainer = null;
            this.$InputContainer        = null;

//            this.firstSearchExecuted = false; //todo michael
        },

        /**
         * event : on create
         */
        create: function () {
            var Elm  = this.parent();
            var self = this;

            Elm.addClass('qui-backendsearch-search');
            Elm.set('html', Mustache.render(template));

            Elm.setStyles({
                position: 'absolute'
            });

            this.Loader = new QUILoader();

            this.$Header     = Elm.getElement('header');
            this.$Result     = Elm.getElement('.qui-backendsearch-search-container-result');
            this.$SearchIcon = Elm.getElement('.qui-backendsearch-search-container-input label .fa');

            this.$InputContainer        = Elm.getElement('.qui-backendsearch-search-container-input');
            this.$FilterSelectContainer = Elm.getElement('.qui-backendsearch-search-container-filterselect');

            // input events
            var inputEsc = false;

            this.$Input = Elm.getElement('input');

            this.$Input.addEvent('keydown', function (event) {
                if (event.key === 'esc') {
                    event.stop();
                    inputEsc = true;
                    return;
                }

                inputEsc = false;
            });

            this.$Input.addEvent('keyup', function (event) {
                if (inputEsc && this.$Input.value !== '') {
                    event.stop();
                    this.$Input.value = '';
                }

                // auto-search requires minimum characters
                if (this.$Input.value.length < this.$Settings.minCharacters &&
                    event.code !== 13) {
                    return;
                }

                this.search();
            }.bind(this));

            // search btn
            this.$BtnSearch = new QUIButton({
                'class'  : 'qui-backendsearch-search-container-btn',
                textimage: 'fa fa-search',
                text     : QUILocale.get(lg, 'controls.Search.btn.submit.text'),
                styles   : {
                    lineHeight: 50,
                    width     : 100
                },
                events   : {
                    onClick: function () {
                        self.$Input.focus();

                        if (self.$Input.value.trim() === '') {
                            self.$Input.value = '';
                            return;
                        }

                        self.search();
                    }
                }
            }).inject(this.$FilterSelectContainer, 'before');

            this.$Close = Elm.getElement('.qui-backendsearch-search-container-close');
            this.$Close.addEvent('click', this.close);

            new Element('img', {
                src: URL_BIN_DIR + 'quiqqer_logo.png'
            }).inject(this.$Header, 'top');

            return Elm;
        },

        /**
         * Open the search
         *
         * @return {Promise}
         */
        open: function () {
            var self = this;

            if (!this.$Elm) {
                this.create();
            }

            if (this.$open) {
                return Promise.resolve();
            }

            this.$open = true;

            this.$Elm.setStyles({
                top: '-100%'
            });

            this.Loader.inject(this.$Elm);
            this.$Elm.inject(document.body);

            // filter select
            this.$FilterSelect = new FilterSelect().inject(this.$FilterSelectContainer);

            this.Loader.show();

            return new Promise(function (resolve) {
                self.$getSettings('general').then(function (Settings) {
                    self.$Settings = Settings;

                    self.$FilterSelect.addEvents({
                        onChange: () => {
                            self.$execSearchOnNextFilterClose = true;
                        }
                    });

                    self.$FilterSelect.$Menu.addEvents({
                        onHide: () => {
                            if (!self.$execSearchOnNextFilterClose) {
                                return;
                            }

                            self.$execSearchOnNextFilterClose = false;
                            self.search();
                        }
                    });

                    self.$FilterSelect.setAttribute(
                        'menuWidth',
                        self.$InputContainer.getSize().x
                    );

                    moofx(self.$Elm).animate({
                        top: 0
                    }, {
                        duration: 250,
                        callback: function () {
                            if (self.$value) {
                                self.setValue(self.$value);
                            }

                            window.addEvent('keyup', self.$onWindowKeyUp);

                            self.$Input.focus();

                            if (self.$Input.value !== '') {
                                self.search();
                            }

                            self.fireEvent('open', [self]);
                            self.Loader.hide();

                            resolve();
                        }
                    });
                });
            });
        },

        /**
         * Set the value / search string for the search
         *
         * @param {String} value
         */
        setValue: function (value) {
            if (this.$Input) {
                this.$Input.value = value;
                return;
            }

            this.$value = value;
        },

        /**
         * Return the current search value
         *
         * @return {String}
         */
        getValue: function () {
            if (this.$Input) {
                return this.$Input.value;
            }

            return this.$value || '';
        },

        /**
         * Close the complete search
         *
         * @return {Promise}
         */
        close: function () {
            return new Promise(function (resolve) {

                moofx(this.$Elm).animate({
                    opacity: 0,
                    top    : -200
                }, {
                    duration: 250,
                    callback: function () {
                        this.$Elm.destroy();

                        this.$Elm   = null;
                        this.$open  = false;
                        this.$value = this.$Input.value;

                        window.removeEvent('keyup', this.$onWindowKeyUp);
                        this.fireEvent('close', [this]);

                        resolve();
                    }.bind(this)
                });

            }.bind(this));
        },

        /**
         * Open a cache entry and close the search
         *
         * @param {Number|String} id
         * @param {String} [provider]
         */
        openEntry: function (id, provider) {
            this.getEntry(id, provider).then(function (data) {
                if (!data || !("searchdata" in data)) {
                    return;
                }

                var searchData;

                try {
                    searchData = JSON.decode(data.searchdata);
                } catch (e) {
                    return;
                }

                if ("require" in searchData) {
                    require([searchData.require], function (Cls) {
                        if (typeOf(Cls) === 'class') {
                            var params   = searchData.params || {};
                            var Instance = new Cls(params);

                            if (instanceOf(Instance, QUIPanel)) {
                                PanelUtils.openPanelInTasks(Instance);
                            }

                            if (instanceOf(Instance, QUIPopup)) {
                                Instance.open();
                            }
                        }
                    });

                    this.close();
                }
            }.bind(this)).catch(function (Exception) {
                console.error(Exception);
            });
        },

        /**
         * Excecute the search with a delay
         */
        search: function () {

            if (!this.$open) {
                this.open();
            }

            var searchValue = this.$Input.value.trim();

            if (searchValue === '') {
                this.$Input.value = '';
                return;
            }

            this.Loader.inject(this.$Result);
            this.Loader.show(
                QUILocale.get(lg, 'controls.Search.loader.searching')
            );

            // Disable form elements
            this.$Input.disabled = true;
            this.$BtnSearch.disable();
            this.$FilterSelect.disable();

            var self = this;

            if (this.$Timer) {
                clearInterval(this.$Timer);
            }

            var twoStepSearch = parseInt(this.$Settings.twoStepSearch);

            this.$Timer = (() => {
                var Params = {
                    filterGroups: self.$FilterSelect.getValue()
                };

                if (!self.$extendedSearch && twoStepSearch) {
                    Params.limit = 5;
                }

                self.executeSearch(self.$Input.value, Params).then((result) => {
                    self.$renderResult(result);

                    if (!self.$extendedSearch && twoStepSearch && result.length >= 5) {
                        self.$extendedSearch = true;
                        self.search();  // execute search without limits
                    } else {
                        self.$extendedSearch = false;
                    }

                    // Enable form elements
                    this.$Input.disabled = false;
                    this.$BtnSearch.enable();
                    this.$FilterSelect.enable();
                });
            }).delay(this.getAttribute('delay'));
        },

        /**
         * Render the result array
         *
         * @param {Array} result
         */
        $renderResult: function (result) {
            var group, groupHTML, Entry, label;

            if (result.length === 0) {
                // no search results
                this.$Result.set('html', '');
                this.Loader.hide();
                return;
            }

            // todo michael
            /*if (!this.firstSearchExecuted) {
                this.$Header.setStyle('margin-top', '5vh');
                this.firstSearchExecuted = true;
            }*/

            let i, len;

            let html           = '',
                ResultsByGroup = {},
                current        = QUILocale.getCurrent();

            // parse json titles
            for (i = 0, len = result.length; i < len; i++) {
                if (result[i].title.indexOf('{') === -1) {
                    continue;
                }

                try {
                    let title = JSON.decode(result[i].title);

                    if (title && typeof title[current] !== 'undefined') {
                        result[i].title = title[current];
                    }
                } catch (e) {
                }
            }

            for (i = 0, len = result.length; i < len; i++) {
                Entry = result[i];

                if (typeof ResultsByGroup[Entry.group] === 'undefined') {
                    label = Entry.group;

                    if ("groupLabel" in Entry) {
                        label = Entry.groupLabel;
                    }

                    ResultsByGroup[Entry.group] = {
                        label   : label,
                        entries : [],
                        resultId: encodeURI(label)
                    };
                }

                ResultsByGroup[result[i].group].entries.push(result[i]);
            }

            var ResultHeader = new Element('div', {
                'class': 'result-header',
                html   : '<header class="result-header-title">' +
                    QUILocale.get('quiqqer/backendsearch', 'search.popup.title.group') +
                    '</header>'
            });

            for (group in ResultsByGroup) {
                var buttonLabel = ResultsByGroup[group].label;
                buttonLabel += ' <strong>(' + ResultsByGroup[group].entries.length;
                buttonLabel += '</strong>)';

                var resultButton = new Element('button', {
                    'class'      : 'result-header-entry qui-button',
                    html         : buttonLabel,
                    'data-qui-id': ResultsByGroup[group].resultId
                });

                resultButton.inject(ResultHeader);
            }

            html += ResultHeader.outerHTML;

            new Element('header', {
                'class': 'qui-backendsearch-search-resultGroup-title',
                html   : 'Ergebnisse'
            }).inject(ResultHeader);

            html += '<header class="qui-backendsearch-search-resultGroup-title">';
            html += QUILocale.get('quiqqer/backendsearch', 'search.popup.title.group') + '</header>';
            html += '<div class="qui-backendsearch-search-resultGroup-wrapper">';

            for (group in ResultsByGroup) {
                if (!ResultsByGroup.hasOwnProperty(group)) {
                    continue;
                }

                groupHTML = Mustache.render(templateResultGroup, {
                    title   : ResultsByGroup[group].label,
                    entries : ResultsByGroup[group].entries,
                    resultId: ResultsByGroup[group].resultId
                });

                html = html + groupHTML;
            }

            html += '</div>';

            this.$Result.set('html', html);

            this.$Result.getElements('li').addEvent('click', function (event) {
                var Target = event.target;

                if (Target.nodeName !== 'LI') {
                    Target = Target.getParent('li');
                }

                this.openEntry(Target.get('data-id'), Target.get('data-provider'));
            }.bind(this));

            // click event for result buttons
            var resultButtons      = this.$Result.getElements('.result-header-entry'),
                resultGroup        = this.$Result.getElements('.qui-backendsearch-search-resultGroup'),
                resultGroupWrapper = this.$Result.getElement('.qui-backendsearch-search-resultGroup-wrapper');

            resultButtons.addEvent('click', function (event) {
                var Target = event.target;

                if (Target.nodeName !== 'BUTTON') {
                    Target = Target.getParent('button');
                }

                this.changeEntryFocus(Target, resultButtons, resultGroup, resultGroupWrapper);
            }.bind(this));
        },

        /**
         * Change focus on result button and result list
         *
         * @param Button
         * @param resultButtons
         * @param resultGroup
         * @param resultGroupWrapper
         */
        changeEntryFocus: function (Button, resultButtons, resultGroup, resultGroupWrapper) {
            resultButtons.removeClass('highlight');
            resultGroup.removeClass('highlight');

            var selector    = 'section[name="' + Button.get('data-qui-id') + '"]',
                selectedElm = resultGroupWrapper.getElement(selector);

            Button.addClass('highlight');

            new Fx.Scroll(resultGroupWrapper).toElement(selectedElm);
            selectedElm.addClass('highlight');
        },

        /**
         * event : on window key up
         * looks for ESC
         *
         * @param event
         */
        $onWindowKeyUp: function (event) {
            if (event.key === 'esc') {
                this.close();
            }
        },

        /**
         * Execute a search
         *
         * @param {String} search
         * @param {Object} [params] - Search where params
         * @returns {Promise}
         */
        executeSearch: function (search, params) {
            if (search === '') {
                return Promise.resolve([]);
            }

            params = params || {};

            var self = this;

            this.$SearchIcon.removeClass('fa-arrow-right');
            this.$SearchIcon.addClass('fa-spinner fa-spin');

            return new Promise(function (resolve) {
                QUIAjax.get('package_quiqqer_backendsearch_ajax_search', function (result) {
                    self.$SearchIcon.addClass('fa-arrow-right');
                    self.$SearchIcon.removeClass('fa-spinner');
                    self.$SearchIcon.removeClass('fa-spin');
                    resolve(result);
                }, {
                    search   : search,
                    params   : JSON.encode(params),
                    'package': 'quiqqer/backendsearch'
                });
            });
        },

        /**
         * Return a search cache entry
         *
         * @param {Number|String} id - id of the entry
         * @param {String} [provider] - optional, provider to get the entry data, if the entry is from a module
         * @returns {Promise}
         */
        getEntry: function (id, provider) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_backendsearch_ajax_getEntry', resolve, {
                    id       : id,
                    provider : provider,
                    showError: false,
                    'package': 'quiqqer/backendsearch',
                    onError  : reject
                });
            });
        },

        /**
         * Get search settings
         *
         * @param {string} section
         * @param {string} [setting]
         * @return {Promise}
         */
        $getSettings: function (section, setting) {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_backendsearch_ajax_getSetting', resolve, {
                    'package': 'quiqqer/backendsearch',
                    onError  : reject,
                    section  : section,
                    'var'    : setting ? null : setting
                });
            });
        },

        /**
         * Get all available provider search ResultsByGroup
         *
         * @return {Promise}
         */
        $getFilterGroups: function () {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_backendsearch_ajax_getFilterGroups', resolve, {
                    'package': 'quiqqer/backendsearch',
                    onError  : reject
                });
            });
        }
    });
});
