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

], function (
    QUIControl, QUIPanel, QUIPopup, QUIButton, QUILoader,
    FilterSelect, PanelUtils, Mustache, QUIAjax, QUILocale, template,
    templateResultGroup
) {
    "use strict";

    const lg = 'quiqqer/backendsearch';

    return new Class({

        Type: 'package/quiqqer/backendsearch/bin/controls/Search',
        Extends: QUIControl,

        Binds: [
            'close',
            'create',
            'hide',
            'open',
            'executeSearch',
            '$onInject',
            '$loadMoreResults',
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

            this.$Elm = null;
            this.$Input = null;
            this.$Header = null;
            this.$Close = null;
            this.$Result = null;
            this.$BtnSearch = null;

            this.$open = false;
            this.$value = false;
            this.$FilterSelect = null;
            this.$extendedSearch = false;
            this.$Settings = {};
            this.$results = [];
            this.$lastSearchValue = null;
            this.$pendingSearchValue = null;
            this.$searchGeneration = 0;

            this.$execSearchOnNextFilterClose = false;

            this.$FilterSelectContainer = null;
            this.$InputContainer = null;

//            this.firstSearchExecuted = false; //todo michael
        },

        /**
         * event : on create
         */
        create: function () {
            var Elm = this.parent();
            var self = this;

            Elm.addClass('qui-backendsearch-search');
            Elm.addClass('is-idle');
            Elm.set('html', Mustache.render(template, {
                inputPlaceholder: QUILocale.get(lg, 'controls.input.placeholder'),
                closeText: QUILocale.get(lg, 'controls.Search.close')
            }));

            Elm.setStyles({
                position: 'absolute'
            });

            this.Loader = new QUILoader();

            this.$Header = Elm.getElement('header');
            this.$Result = Elm.getElement('.qui-backendsearch-search-container-result');
            this.$SearchIcon = Elm.getElement('.qui-backendsearch-search-container-input label .fa');

            this.$InputContainer = Elm.getElement('.qui-backendsearch-search-container-input');
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
                if (event.code !== 13) {
                    return;
                }

                if (inputEsc && this.$Input.value !== '') {
                    event.stop();
                    this.$Input.value = '';
                }

                // auto-search requires minimum characters
                if (this.$Input.value.length < this.$Settings.minCharacters) {
                    return;
                }

                this.search();
            }.bind(this));

            // search btn
            this.$BtnSearch = new QUIButton({
                'class': 'qui-backendsearch-search-container-btn',
                textimage: 'fa fa-search',
                text: QUILocale.get(lg, 'controls.Search.btn.submit.text'),
                styles: {
                    lineHeight: 50,
                    width: 100
                },
                events: {
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
                src: URL_BIN_DIR + 'quiqqer_logo.png',
                alt: 'QUIQQER'
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
                this.$searchIfNeeded();
                return Promise.resolve();
            }

            this.$open = true;

            this.$Elm.setStyles({
                opacity: 1,
                top: '-100%'
            });

            this.Loader.inject(this.$Elm);
            this.$Elm.inject(document.body);

            let initialization = Promise.resolve();

            if (!this.$FilterSelect) {
                this.Loader.show();
                this.$FilterSelect = new FilterSelect().inject(this.$FilterSelectContainer);

                initialization = self.$getSettings('general').then(function (Settings) {
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

                });
            } else {
                this.Loader.hide();
            }

            return initialization.then(function () {
                self.$FilterSelect.setAttribute(
                    'menuWidth',
                    self.$InputContainer.getSize().x
                );

                return new Promise(function (resolve) {
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
                            self.Loader.hide();
                            self.$searchIfNeeded();
                            self.fireEvent('open', [self]);

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
            this.$value = value;

            if (this.$Input) {
                this.$Input.value = value;
            }
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
         * Check if the current result state belongs to a search value.
         *
         * @param {String} value
         * @return {Boolean}
         */
        hasSearchState: function (value) {
            const normalizedValue = String(value || '').trim();

            if (normalizedValue === '') {
                return false;
            }

            return this.$lastSearchValue === normalizedValue ||
                this.$pendingSearchValue === normalizedValue;
        },

        /**
         * Execute a search only if the displayed state does not match the input.
         */
        $searchIfNeeded: function () {
            if (!this.$Input || !this.$Result) {
                return;
            }

            const searchValue = this.$Input.value.trim();

            if (searchValue === '') {
                this.$results = [];
                this.$lastSearchValue = null;
                this.$pendingSearchValue = null;
                this.$Result.set('html', '');
                this.$Elm.removeClass('has-search');
                this.$Elm.addClass('is-idle');
                return;
            }

            if (!this.hasSearchState(searchValue)) {
                this.search();
            }
        },

        /**
         * Close the complete search
         *
         * @return {Promise}
         */
        close: function () {
            return this.$dismiss(false);
        },

        /**
         * Hide the search and retain its complete DOM and result state.
         *
         * @return {Promise}
         */
        hide: function () {
            return this.$dismiss(true);
        },

        /**
         * @param {Boolean} preserveState
         * @return {Promise}
         */
        $dismiss: function (preserveState) {
            if (!this.$Elm) {
                return Promise.resolve();
            }

            this.$value = this.$Input ? this.$Input.value : this.$value;
            this.$execSearchOnNextFilterClose = false;
            window.removeEvent('keyup', this.$onWindowKeyUp);

            if (this.$FilterSelect && this.$FilterSelect.$Menu) {
                this.$FilterSelect.$Menu.hide();
            }

            const finish = function () {
                this.$open = false;

                if (preserveState) {
                    this.$Elm.dispose();
                    this.fireEvent('hide', [this]);
                    return;
                }

                this.$searchGeneration++;

                if (this.$Timer) {
                    clearTimeout(this.$Timer);
                    this.$Timer = null;
                }

                if (this.$FilterSelect) {
                    this.$FilterSelect.destroy();
                }

                this.$Elm.destroy();
                this.$Elm = null;
                this.$Input = null;
                this.$Header = null;
                this.$Close = null;
                this.$Result = null;
                this.$BtnSearch = null;
                this.$FilterSelect = null;
                this.$FilterSelectContainer = null;
                this.$InputContainer = null;
                this.$SearchIcon = null;
                this.$Settings = {};
                this.$results = [];
                this.$lastSearchValue = null;
                this.$pendingSearchValue = null;
                this.$extendedSearch = false;
                this.fireEvent('close', [this]);
            }.bind(this);

            if (!this.$open) {
                finish();
                return Promise.resolve();
            }

            return new Promise(function (resolve) {
                moofx(this.$Elm).animate({
                    opacity: 0,
                    top: -200
                }, {
                    duration: 250,
                    callback: function () {
                        finish();
                        resolve();
                    }
                });
            }.bind(this));
        },

        /**
         * Open a cache entry and hide the search
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
                            var params = searchData.params || {};
                            var Instance = new Cls(params);

                            if (instanceOf(Instance, QUIPanel)) {
                                PanelUtils.openPanelInTasks(Instance);
                            }

                            if (instanceOf(Instance, QUIPopup)) {
                                Instance.open();
                            }
                        }
                    });

                    this.hide();
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
                return this.open();
            }

            var searchValue = this.$Input.value.trim();

            if (searchValue === '') {
                this.$Input.value = '';
                return;
            }

            this.$pendingSearchValue = searchValue;

            this.$Elm.removeClass('is-idle');
            this.$Elm.addClass('has-search');

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
            const searchGeneration = this.$searchGeneration;

            this.$Timer = (() => {
                var Params = {
                    filterGroups: self.$FilterSelect.getValue()
                };

                if (!self.$extendedSearch && twoStepSearch) {
                    Params.limit = 5;
                }

                self.executeSearch(self.$Input.value, Params).then((result) => {
                    if (searchGeneration !== self.$searchGeneration) {
                        return;
                    }

                    self.$lastSearchValue = searchValue;
                    self.$pendingSearchValue = null;
                    self.$renderResult(result);

                    const hasMoreResults = result.some(function (Entry) {
                        return Entry.groupHasMore === true;
                    });

                    if (!self.$extendedSearch && twoStepSearch && hasMoreResults) {
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
         * @param {Number} [scrollTop]
         */
        $renderResult: function (result, scrollTop) {
            var groupHTML, Entry, label;

            this.$results = result;

            if (result.length === 0) {
                this.$Result.set('html', '');

                const EmptyResult = document.createElement('div');
                const EmptyIcon = document.createElement('span');
                const EmptyText = document.createElement('p');

                EmptyResult.className = 'qui-backendsearch-search-empty';
                EmptyResult.setAttribute('role', 'status');
                EmptyIcon.className = 'fa fa-search';
                EmptyIcon.setAttribute('aria-hidden', 'true');
                EmptyText.textContent = QUILocale.get(lg, 'controls.Search.results.empty');
                EmptyResult.appendChild(EmptyIcon);
                EmptyResult.appendChild(EmptyText);
                this.$Result.appendChild(EmptyResult);
                this.Loader.hide();
                return;
            }

            // todo michael
            /*if (!this.firstSearchExecuted) {
                this.$Header.setStyle('margin-top', '5vh');
                this.firstSearchExecuted = true;
            }*/

            let i, len;

            let ResultsByGroup = Object.create(null),
                groupOrder = [],
                current = QUILocale.getCurrent();

            // parse json titles
            for (i = 0, len = result.length; i < len; i++) {
                if (typeof result[i].title !== 'string' || result[i].title.indexOf('{') === -1) {
                    continue;
                }

                try {
                    let title = JSON.decode(result[i].title);

                    if (title && typeof title[current] !== 'undefined') {
                        result[i].title = title[current];
                        continue;
                    }

                    if (title && typeof title === 'object') {
                        Object.keys(title).some(function (language) {
                            if (typeof title[language] !== 'string' || title[language].trim() === '') {
                                return false;
                            }

                            result[i].title = title[language];
                            return true;
                        });
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
                        group: Entry.group,
                        label: label,
                        entries: [],
                        hasMore: false,
                        resultId: 'backendsearch-result-group-' + groupOrder.length
                    };
                    groupOrder.push(Entry.group);
                }

                ResultsByGroup[result[i].group].entries.push(result[i]);

                if (result[i].groupHasMore === true) {
                    ResultsByGroup[result[i].group].hasMore = true;
                }
            }

            const ResultLayout = document.createElement('div');
            const ResultHeader = document.createElement('aside');
            const ResultHeaderTitle = document.createElement('h2');
            const ResultNavigation = document.createElement('nav');
            const ResultContent = document.createElement('main');
            const ResultContentTitle = document.createElement('h2');
            const ResultGroupWrapper = document.createElement('div');

            ResultLayout.className = 'qui-backendsearch-search-result-layout';
            ResultHeader.className = 'result-header';
            ResultHeaderTitle.className = 'result-header-title';
            ResultHeaderTitle.textContent = QUILocale.get(lg, 'search.popup.title.group');
            ResultNavigation.className = 'result-header-navigation';
            ResultNavigation.setAttribute(
                'aria-label',
                QUILocale.get(lg, 'search.popup.title.group')
            );
            ResultContent.className = 'qui-backendsearch-search-result-content';
            ResultContentTitle.className = 'qui-backendsearch-search-resultGroup-title';
            ResultContentTitle.textContent = QUILocale.get(lg, 'search.popup.title.entries');
            ResultGroupWrapper.className = 'qui-backendsearch-search-resultGroup-wrapper';

            groupOrder.forEach(function (group) {
                const Group = ResultsByGroup[group];
                const ResultButton = document.createElement('button');
                const ResultButtonLabel = document.createElement('span');
                const ResultButtonCount = document.createElement('strong');

                ResultButton.type = 'button';
                ResultButton.className = 'result-header-entry qui-button';
                ResultButton.setAttribute('data-qui-id', Group.resultId);
                ResultButton.setAttribute('aria-controls', Group.resultId);
                ResultButtonLabel.className = 'result-header-entry-label';
                ResultButtonLabel.textContent = Group.label;
                ResultButtonCount.textContent = Group.entries.length + (Group.hasMore ? '+' : '');
                ResultButton.appendChild(ResultButtonLabel);
                ResultButton.appendChild(ResultButtonCount);
                ResultNavigation.appendChild(ResultButton);

                groupHTML = Mustache.render(templateResultGroup, {
                    title: Group.label,
                    entries: Group.entries,
                    resultId: Group.resultId,
                    group: Group.group,
                    hasMore: Group.hasMore,
                    showMoreText: QUILocale.get(lg, 'controls.Search.results.showMore')
                });
                ResultGroupWrapper.insertAdjacentHTML('beforeend', groupHTML);
            }.bind(this));

            ResultHeader.appendChild(ResultHeaderTitle);
            ResultHeader.appendChild(ResultNavigation);
            ResultContent.appendChild(ResultContentTitle);
            ResultContent.appendChild(ResultGroupWrapper);
            ResultLayout.appendChild(ResultHeader);
            ResultLayout.appendChild(ResultContent);
            this.$Result.set('html', '');
            this.$Result.appendChild(ResultLayout);

            const resultEntries = this.$Result.querySelectorAll('[data-name="result-entry"]');

            Array.prototype.forEach.call(resultEntries, function (ResultEntry) {
                ResultEntry.addEventListener('click', function () {
                    this.openEntry(ResultEntry.dataset.id, ResultEntry.dataset.provider);
                }.bind(this));
            }.bind(this));

            const showMoreButtons = this.$Result.querySelectorAll('[data-name="show-more"]');

            Array.prototype.forEach.call(showMoreButtons, function (Button) {
                Button.addEventListener('click', function () {
                    this.$loadMoreResults(Button.dataset.group, Button);
                }.bind(this));
            }.bind(this));

            // click event for result buttons
            var resultButtons = this.$Result.getElements('.result-header-entry'),
                resultGroup = this.$Result.getElements('.qui-backendsearch-search-resultGroup'),
                resultGroupWrapper = this.$Result.getElement('.qui-backendsearch-search-resultGroup-wrapper');

            if (typeof scrollTop === 'number') {
                resultGroupWrapper.scrollTop = scrollTop;
            }

            resultButtons.addEvent('click', function (event) {
                var Target = event.target;

                if (Target.nodeName !== 'BUTTON') {
                    Target = Target.getParent('button');
                }

                this.changeEntryFocus(Target, resultButtons, resultGroup, resultGroupWrapper);
            }.bind(this));
        },

        /**
         * Load the next result page for one group.
         *
         * @param {String} group
         * @param {HTMLButtonElement} Button
         */
        $loadMoreResults: function (group, Button) {
            const currentResults = this.$results;
            const currentGroupSize = currentResults.filter(function (Entry) {
                return Entry.group === group;
            }).length;
            const configuredPageSize = parseInt(this.$Settings.maxResultsPerGroup, 10);
            const pageSize = configuredPageSize > 0 ? configuredPageSize : 100;
            const searchValue = this.$Input.value;
            const resultGroupWrapper = this.$Result.querySelector(
                '.qui-backendsearch-search-resultGroup-wrapper'
            );
            const scrollTop = resultGroupWrapper ? resultGroupWrapper.scrollTop : 0;

            Button.disabled = true;

            this.executeSearch(searchValue, {
                filterGroups: this.$FilterSelect.getValue(),
                group: group,
                limit: currentGroupSize + pageSize
            }).then(function (groupResult) {
                if (this.$results !== currentResults || this.$Input.value !== searchValue) {
                    Button.disabled = false;
                    return;
                }

                const mergedResult = [];
                let groupInserted = false;

                currentResults.forEach(function (Entry) {
                    if (Entry.group !== group) {
                        mergedResult.push(Entry);
                        return;
                    }

                    if (!groupInserted) {
                        mergedResult.push.apply(mergedResult, groupResult);
                        groupInserted = true;
                    }
                });

                this.$renderResult(mergedResult, scrollTop);
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
            resultButtons.set('aria-current', 'false');
            resultGroup.removeClass('highlight');

            var selector = '#' + Button.get('data-qui-id'),
                selectedElm = resultGroupWrapper.getElement(selector);

            Button.addClass('highlight');
            Button.set('aria-current', 'true');

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
                    search: search,
                    params: JSON.encode(params),
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
                    id: id,
                    provider: provider,
                    showError: false,
                    'package': 'quiqqer/backendsearch',
                    onError: reject
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
                    onError: reject,
                    section: section,
                    'var': setting ? null : setting
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
                    onError: reject
                });
            });
        }
    });
});
