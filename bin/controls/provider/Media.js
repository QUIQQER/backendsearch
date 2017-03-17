/**
 * Display a password result for QUIQQER Desktop Search
 *
 * @module package/quiqqer/backendsearch/bin/controls/provider/Media
 * @author www.pcsg.de (Patrick Müller)
 */
define('package/quiqqer/backendsearch/bin/controls/provider/Media', [

    'utils/Panels'

], function (PanelUtils) {
    "use strict";

    return new Class({
        Type: 'package/quiqqer/backendsearch/bin/controls/provider/Media',

        initialize: function (options) {
            PanelUtils.openMediaPanel(options.project, {'fileid':options.id});
        }
    });
});
