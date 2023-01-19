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

define('helpers/misc/authentication-provider', [], function () {

    /**
     * @memberOf module:helpers/misc/authentication-provider
     */
     class Class {
        /**
         * @param {module:views/record/detail.Class} view A view.
         */
        constructor(view) {
            /**
             * @private
             * @type {module:views/record/detail.Class}
             */
            this.view = view;

            this.metadata = view.getMetadata();

            /**
             * @private
             * @type {module:model.Class}
             */
            this.model = view.model;

            /** @var {Object.<string, Object.<string, *>>} defs */
            let defs = view.getMetadata().get(['authenticationMethods']) || {};

            /**
             * @private
             * @type {string[]}
             */
            this.methodList = Object.keys(defs).filter(item => defs[item].additional);

            /** @private */
            this.authFields = {};

            /** @private */
            this.dynamicLogicDefs = {
                fields: {},
                panels: {},
            };
        }

        setupPanelsVisibility() {
            this.handlePanelsVisibility();
            this.view.listenTo(this.model, 'change:method', () => this.handlePanelsVisibility());
        }

        /**
         * @return {Object}
         */
        setupMethods() {
            this.methodList.forEach(method => this.setupMethod(method));

            return this.dynamicLogicDefs;
        }

        /**
         * @private
         */
        setupMethod(method) {
            /** @var {string[]} */
            let fieldList = this.metadata
                .get(['authenticationMethods', method, 'settings', 'fieldList']) || [];

            fieldList = fieldList.filter(item => this.model.hasField(item));

            this.authFields[method] = fieldList;

            let mDynamicLogicFieldsDefs = this.metadata
                .get(['authenticationMethods', method, 'settings', 'dynamicLogic', 'fields']) || {};

            for (let f in mDynamicLogicFieldsDefs) {
                if (!fieldList.includes(f)) {
                    continue;
                }

                let defs = this.modifyDynamicLogic(mDynamicLogicFieldsDefs[f]);

                this.dynamicLogicDefs.fields[f] = Espo.Utils.cloneDeep(defs);
            }
        }

        /**
         * @private
         */
        modifyDynamicLogic(defs) {
            defs = Espo.Utils.clone(defs);

            if (Array.isArray(defs)) {
                return defs.map(item => this.modifyDynamicLogic(item));
            }

            if (typeof defs === 'object') {
                let o = {};

                for (let property in defs) {
                    let value = defs[property];

                    if (property === 'attribute' && value === 'authenticationMethod') {
                        value = 'method';
                    }

                    o[property] = this.modifyDynamicLogic(value);
                }

                return o;
            }

            return defs;
        }

        modifyDetailLayout(layout) {
            this.methodList.forEach(method => {
                let mLayout = this.metadata.get(['authenticationMethods', method, 'settings', 'layout']);

                if (!mLayout) {
                    return;
                }

                mLayout = Espo.Utils.cloneDeep(mLayout);
                mLayout.name = method;

                this.prepareLayout(mLayout, method);

                layout.push(mLayout);
            });
        }

        prepareLayout(layout, method) {
            layout.rows.forEach(row => {
                row
                    .filter(item => !item.noLabel && !item.labelText && item.name)
                    .forEach(item => {
                        if (item === null) {
                            return;
                        }

                        let labelText = this.view.translate(item.name, 'fields', 'Settings');

                        item.options = item.options || {};

                        if (labelText && labelText.toLowerCase().indexOf(method.toLowerCase() + ' ') === 0) {
                            item.labelText = labelText.substring(method.length + 1);
                        }

                        item.options.tooltipText = this.view.translate(item.name, 'tooltips', 'Settings');
                    });
            });

            layout.rows = layout.rows.map(row => {
                row = row.map(cell => {
                    if (
                        cell &&
                        cell.name &&
                        !this.model.hasField(cell.name)
                    ) {
                        return false;
                    }

                    return cell;
                })

                return row;
            });
        }

        handlePanelsVisibility() {
            let authenticationMethod = this.model.get('method');

            this.methodList.forEach(method => {
                let fieldList = (this.authFields[method] || []);

                if (method !== authenticationMethod) {
                    this.view.hidePanel(method);

                    fieldList.forEach(field => {
                        this.view.hideField(field);
                    });

                    return;
                }

                this.view.showPanel(method);

                fieldList.forEach(field => this.view.showField(field));

                this.view.processDynamicLogic();
            });
        }
    }

    return Class;
});
