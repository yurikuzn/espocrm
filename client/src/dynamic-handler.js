/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2023 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

/** @module dynamic-handler */

/**
 * A dynamic handler. To be extended by a specific handler.
 *
 * @class
 * @param {module:views/record/detail} recordView A record view.
 */
const DynamicHandler = function (recordView) {
    /**
     * A record view.
     *
     * @protected
     * @type {module:views/record/detail}
     */
    this.recordView = recordView;

    /**
     * A model.
     *
     * @protected
     * @type {module:model}
     */
    this.model = recordView.model;
};

_.extend(DynamicHandler.prototype, /** @lends DynamicHandler# */{

    /**
     * Initialization logic. To be extended.
     *
     * @protected
     */
    init: function () {},

    /**
     * Called on model change. To be extended.
     *
     * @protected
     * @param {module:views/record/detail} model A model.
     * @param {Object} o Options.
     */
    onChange: function (model, o) {},

    /**
     * Get a metadata.
     *
     * @protected
     * @returns {module:metadata}
     */
    getMetadata: function () {
        return this.recordView.getMetadata()
    },
});

DynamicHandler.extend = Bull.View.extend;

export default DynamicHandler;
