require([
    'package/quiqqer/backendsearch/bin/controls/Input'
], function (SearchInput) {
    "use strict";

    window.addEvent('quiqqerLoaded', function () {
        // Search input
        new SearchInput({
            styles: {
                'float': 'right',
                margin : '2px 10px 4px 10px'
            }
        }).inject(
            document.getElement('.qui-menu-container')
        );
    });

    if (!("backendSearch" in window.QUIQQER)) {
        window.QUIQQER.backendSearch = {};
    }

    const DesktopSearch = window.QUIQQER.backendSearch.Search;

    DesktopSearch.addEvent('open', function () {
        window.QUIQQER.backendSearch.searchWindowOpen = true;
    });
    DesktopSearch.addEvent('hide', function () {
        window.QUIQQER.backendSearch.searchWindowOpen = false;
    });
    DesktopSearch.addEvent('close', function () {
        window.QUIQQER.backendSearch.searchWindowOpen = false;
    });

    // search keyboard shortcut
    window.addEvent('keydown', function (event) {
        if (!event.alt) {
            return;
        }

        if (event.key === 'f') {
            event.stop();

            if (window.QUIQQER.backendSearch.searchWindowOpen) {
                return;
            }

            DesktopSearch.open();
        }
    });
});
