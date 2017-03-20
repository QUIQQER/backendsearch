require([
    'package/quiqqer/backendsearch/bin/controls/Input'
], function (SearchInput) {
    "use strict";

    window.addEvent('quiqqerLoaded', function() {
        // Search input
        new SearchInput({
            styles: {
                'float': 'right',
                margin : '5px 24px 0 10px'
            }
        }).inject(
            document.getElement('.qui-menu-container')
        );
    });

    if (!("backendSearch" in window.QUIQQER)) {
        window.QUIQQER.backendSearch = {};
    }

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

            require(['package/quiqqer/backendsearch/bin/controls/Search'], function (Search) {
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