/**
 * Display a password result for QUIQQER Desktop Search
 */
define('package/quiqqer/backendsearch/bin/controls/provider/UsersAndGroups', [

    'utils/Panels',
    'controls/users/User',
    'controls/groups/Group'

], function (PanelUtils, UserPanel, GroupPanel) {
    "use strict";

    return new Class({
        Type: 'package/quiqqer/backendsearch/bin/controls/provider/UsersAndGroups',

        initialize: function (options) {
            if (options.type === 'user') {
                PanelUtils.openPanelInTasks(new UserPanel(options.id, {
                    '#id': options.id
                }));

                return;
            }

            PanelUtils.openPanelInTasks(new GroupPanel(options.id, {
                '#id': options.id
            }));
        }
    });
});
