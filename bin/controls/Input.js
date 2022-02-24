/**
 * @module package/quiqqer/backendsearch/bin/controls/Input
 * @author www.pcsg.de (Henning Leutz)
 */
define('package/quiqqer/backendsearch/bin/controls/Input', [

    'qui/QUI',
    'qui/controls/Control',
    'qui/controls/buttons/Button',
    'Mustache',
    'package/quiqqer/backendsearch/bin/controls/Search',

    'Locale',

    'text!package/quiqqer/backendsearch/bin/controls/Input.html',
    'css!package/quiqqer/backendsearch/bin/controls/Input.css'

], function (QUI, QUIControl, QUIButton, Mustache, Search, QUILocale, template) {
    "use strict";

    if (!("backendSearch" in window.QUIQQER)) {
        window.QUIQQER.backendSearch = {};
    }

    if (!("Search" in window.QUIQQER.backendSearch)) {
        window.QUIQQER.backendSearch.Search = new Search();
    }

    var lg = 'quiqqer/backendsearch';

    return new Class({

        Extends: QUIControl,
        Type   : 'package/quiqqer/backendsearch/bin/controls/Input',

        Binds: [
            'create',
            'openSearch',
            '$onInject',
            '$collectKeyUp'
        ],

        options: {
            styles: false
        },

        initialize: function (options) {
            this.parent(options);

            this.$Input = null;
            this.$SearchBtn = null;

            this.addEvents({
                onInject: this.$onInject
            });
        },

        /**
         * event : on create
         */
        create: function () {
            var Elm = this.parent();

            Elm.addClass('qui-backendsearch-input');
            Elm.set('html', Mustache.render(template, {
                placeholder: QUILocale.get(lg, 'controls.input.placeholder')
            }));

            this.$Input = Elm.getElement('input');

            if (this.getAttribute('styles')) {
                Elm.setStyles(this.getAttribute('styles'));
            }

            window.QUIQQER.backendSearch.Search.addEvent('close', function () {
                this.$Input.value = window.QUIQQER.backendSearch.Search.getValue();
            }.bind(this));

            this.$SearchBtn = new QUIButton({
                icon  : 'fa fa-search',
                events: {
                    onClick: this.openSearch
                }
            }).inject(Elm);

            return Elm;
        },

        /**
         * event : on inject
         */
        $onInject: function () {
            this.$Input.addEvent('keyup', function (event) {
                if (event.key === 'enter') {
                    this.openSearch();
                }
            }.bind(this));
        },

        /**
         * Opent the desktop search
         *
         * @return {Promise}
         */
        openSearch: function () {
            this.$SearchBtn.setAttribute('icon', 'fa fa-spinner fa-spin');

            return window.QUIQQER.backendSearch.Search.open().then(function () {
                window.QUIQQER.backendSearch.Search.setValue(this.$Input.value);
                window.QUIQQER.backendSearch.Search.search();

                this.$SearchBtn.setAttribute('icon', 'fa fa-search');
            }.bind(this));
        }
    });
});
