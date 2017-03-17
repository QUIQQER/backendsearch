require([
    'quiqqer/backendsearch/controls/Input',
    'Menu'
], function (SearchInput, Menu) {
    "use strict";

    // Search input
    new SearchInput({
        styles: {
            'float': 'right',
            margin : '5px 24px 0 10px'
        }
    }).inject(Menu);

    window.QUIQQER.backendSearch = {};

    // search keyboard shortcut
    window.addEvent('keydown', function (event) {
        if (!event.alt) {
            return;
        }

        if (event.key == 'f') {
            event.stop();

            if (window.QUIQQER.backendSearch.searchWindowOpen) {
                return;
            }

            require(['quiqqer/backendsearch/controls/Search'], function (Search) {
                new Search({
                    events: {
                        onClose: function (S) {
                            window.QUIQQER.backendSearch.searchWindowOpen = false;
                            S.destroy();
                        }
                    }
                }).open();

                window.QUIQQER.backendSearch.searchWindowOpen = true;
            });
        }
    });
});