/**
 * Open a settings panel
 */
define('package/quiqqer/backendsearch/bin/controls/builder/Settings', [

    'utils/Panels',
    'controls/desktop/panels/XML'

], function (PanelUtils, XMLPanel) {
    "use strict";

    return new Class({
        Type: 'package/quiqqer/backendsearch/bin/controls/builder/Settings',
        initialize: function (options) {
            PanelUtils.openPanelInTasks(new XMLPanel(options.xmlFile, {
                category: options.category
            }));
        }
    });
});
