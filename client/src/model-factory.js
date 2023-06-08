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

/** @module model-factory */

/**
 * A model factory.
 */
class Class {
    /**
     * @param {module:metadata} metadata
     * @param {module:models/user} user
     */
    constructor (metadata, user) {
        this.metadata = metadata;
        this.user = user;
    }

    /**
     * Used by default value expressions.
     * @public
     * @type {module:date-time|null}
     * @internal
     */
    dateTime = null

    /**
     * Create a model.
     *
     * @param {string} entityType An entity type.
     * @param {Function} [callback] Deprecated.
     * @param {Object} [context] Deprecated.
     * @returns {Promise<module:model>}
     */
    create(entityType, callback, context) {
        return new Promise(resolve => {
            context = context || this;

            this.getSeed(entityType, Seed => {
                let model = new Seed({}, {
                    entityType: entityType,
                    defs: this.metadata.get(['entityDefs', entityType]) || {},
                    user: this.user,
                    dateTime: this.dateTime,
                });

                if (callback) {
                    callback.call(context, model);
                }

                resolve(model);
            });
        });
    }

    /**
     * Get a class.
     *
     * @param {string} entityType An entity type.
     * @param {function(module:model): void} callback A callback.
     * @public
     */
    getSeed(entityType, callback) {
        let className = this.metadata.get(['clientDefs', entityType, 'model']) || 'model';

        Espo.loader.require(className, modelClass => callback(modelClass));
    }
}

export default Class;
