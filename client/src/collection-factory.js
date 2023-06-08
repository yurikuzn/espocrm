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

/** @module collection-factory */

/**
 * A collection factory.
 *
 * @class
 * @param {module:model-factory} modelFactory
 * @param {module:models/settings} config
 * @param {module:metadata} metadata
 * @param {module:models/user} user
 */
const Class = function (modelFactory, config, metadata, user) {
    this.modelFactory = modelFactory;
    this.config = config;
    this.metadata = metadata;
    this.user = user;
};

_.extend(Class.prototype, /** @lends Class# */ {

    /** @private */
    modelFactory: null,
    /** @private */
    recordListMaxSizeLimit: 200,

    /**
     * Create a collection.
     *
     * @param {string} entityType An entity type.
     * @param {Function} [callback] Deprecated.
     * @param {Object} [context] Deprecated.
     * @returns {Promise<module:collection>}
     */
    create: function (entityType, callback, context) {
        return new Promise(resolve => {
            context = context || this;

            this.modelFactory.getSeed(entityType, Model => {
                let orderBy = this.modelFactory.metadata
                    .get(['entityDefs', entityType, 'collection', 'orderBy']);

                let order = this.modelFactory.metadata
                    .get(['entityDefs', entityType, 'collection', 'order']);

                let className = this.modelFactory.metadata
                    .get(['clientDefs', entityType, 'collection']) || 'collection';

                let defs = this.metadata.get(['entityDefs', entityType]) || {};

                Espo.loader.require(className, Collection => {
                    let collection = new Collection(null, {
                        name: entityType,
                        orderBy: orderBy,
                        order: order,
                        defs: defs,
                    });

                    collection.model = Model;
                    collection._user = this.user;
                    collection.entityType = entityType;

                    collection.maxMaxSize = this.config.get('recordListMaxSizeLimit') ||
                        this.recordListMaxSizeLimit;

                    if (callback) {
                        callback.call(context, collection);
                    }

                    resolve(collection);
                });
            });
        });
    },
});

export default Class;
