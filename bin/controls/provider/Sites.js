/**
 * Display a password result for QUIQQER Desktop Search
 */
define('package/quiqqer/backendsearch/bin/controls/provider/Sites', [
    'utils/Panels'
], function (PanelUtils) {
    "use strict";

    return new Class({
        Type: 'package/quiqqer/backendsearch/bin/controls/provider/Sites',
        initialize: function (options) {
            PanelUtils.openSitePanel(options.projectName, options.projectLang, options.siteId);
        }
    });
});
