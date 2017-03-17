/**
 * @module quiqqer/backendsearch/controls/Input
 *
 * @require qui/QUI
 * @require qui/controls/Control
 * @require Mustache
 * @require quiqqer/backendsearch/controls/Search
 * @require text!quiqqer/backendsearch/controls/Input.html
 * @require css!quiqqer/backendsearch/controls/Input.css
 */
define('quiqqer/backendsearch/controls/Input', [

    'qui/QUI',
    'qui/controls/Control',
    'qui/controls/buttons/Button',
    'Mustache',
    'quiqqer/backendsearch/controls/Search',

    'Locale',

    'text!quiqqer/backendsearch/controls/Input.html',
    'css!quiqqer/backendsearch/controls/Input.css'

], function (QUI, QUIControl, QUIButton, Mustache, Search, QUILocale, template) {
    "use strict";

    if (!("Search" in window.QUIQQER.backendSearch)) {
        window.QUIQQER.backendSearch.Search = new Search();
    }

    var lg = 'quiqqer/backendsearch';

    return new Class({

        Extends: QUIControl,
        Type   : 'quiqqer/backendsearch/controls/Input',

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

            this.addEvents({
                onInject: this.$onInject
            });
        },

        /**
         * event : on create
         */
        create: function () {
            var Elm = this.parent();

            Elm.addClass('qui-workspace-search-input');
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

            new QUIButton({
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
         */
        openSearch: function () {
            window.QUIQQER.backendSearch.Search.open().then(function () {
                window.QUIQQER.backendSearch.Search.setValue(this.$Input.value);
                window.QUIQQER.backendSearch.Search.search();
            }.bind(this));
        }
    });
});
