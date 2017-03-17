/**
 * Display a password result for QUIQQER Desktop Search
 *
 * @module package/quiqqer/backendsearch/bin/controls/provider/Sites
 * @author www.pcsg.de (Patrick Müller)
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
