/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2022 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
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

define('views/export/record/record', ['views/record/edit-for-modal'], function (Dep) {

    /**
     * @class
     * @name Class
     * @memberOf module:views/export/record/record
     * @extends module:views/record/edit-for-modal.Class
     */
    return Dep.extend(/** @lends module:views/export/record/record.Class# */{

        /**
         * @type {string[]},
         */
        formatList: null,

        /**
         * @type {Object.<string, string[]>},
         */
        customParams: null,

        setup: function () {
            Dep.prototype.setup.call(this);
        },

        setupBeforeFinal: function () {
            this.formatList = this.options.formatList;
            this.scope = this.options.scope;

            let fieldsData = this.getExportFieldsData();

            this.setupExportFieldDefs(fieldsData);
            this.setupExportLayout(fieldsData);
            this.setupExportDynamicLogic();

            this.controlFormatField();
            this.listenTo(this.model, 'change:format', () => this.controlFormatField());

            this.controlAllFields();
            this.listenTo(this.model, 'change:exportAllFields', () => this.controlAllFields());

            Dep.prototype.setupBeforeFinal.call(this);
        },

        setupExportFieldDefs: function (fieldsData) {
            let fieldDefs = {
                format: {
                    type: 'enum',
                    options: this.formatList,
                },
                fieldList: {
                    type: 'multiEnum',
                    options: fieldsData.list,
                    required: true,
                },
                exportAllFields: {
                    type: 'bool',
                },
            };

            this.customParams = {};

            this.formatList.forEach(format => {
                let fields = this.getFormatParamsDefs(format).fields || {};

                this.customParams[format] = [];

                for (let name in fields) {
                    let newName = this.modifyParamName(format, name);

                    this.customParams[format].push(name);

                    fieldDefs[newName] = Espo.Utils.cloneDeep(fields[name]);
                }
            });

            this.model.setDefs({fields: fieldDefs});
        },

        setupExportLayout: function (fieldsData) {
            this.detailLayout = [];

            let mainPanel = {
                rows: [
                    [
                        {name: 'format'},
                        false
                    ],
                    [
                        {name: 'exportAllFields'},
                        false
                    ],
                    [
                        {
                            name: 'fieldList',
                            options: {
                                translatedOptions: fieldsData.translations,
                            },
                        }
                    ],
                ]
            };

            this.detailLayout.push(mainPanel);

            this.formatList.forEach(format => {
                let rows = this.getFormatParamsDefs(format).layout || [];

                rows.forEach(row => {
                    row.forEach(item => {
                        item.name = this.modifyParamName(format, item.name);
                    });
                })

                this.detailLayout.push({
                    name: format,
                    rows: rows,
                })
            });
        },

        setupExportDynamicLogic: function () {
            this.dynamicLogicDefs = {
                fields: {},
            };

            this.formatList.forEach(format => {
                let defs = this.getFormatParamsDefs(format).dynamicLogic || {};

                this.customParams[format].forEach(param => {
                    let logic = defs[param] || {};

                    if (!logic.visible) {
                        logic.visible = {};
                    }

                    if (!logic.visible.conditionGroup) {
                        logic.visible.conditionGroup = [];
                    }

                    logic.visible.conditionGroup.push({
                        type: 'equals',
                        attribute: 'format',
                        value: format,
                    });

                    let newName = this.modifyParamName(format, param);

                    this.dynamicLogicDefs.fields[newName] = logic;
                });
            });
        },

        /**
         * @param {string} format
         * @return {string[]}
         */
        getFormatParamList: function (format) {
            return Object.keys(this.getFormatParamsDefs(format) || {});
        },

        /**
         * @private
         * @return {Object.<string, *>}
         */
        getFormatParamsDefs: function (format) {
            let defs = this.getMetadata().get(['app', 'export', 'formatDefs', format]) || {};

            return Espo.Utils.cloneDeep(defs.params || {});
        },

        /**
         * @param {string} format
         * @param {string} name
         * @return {string}
         */
        modifyParamName: function (format, name) {
            return format + Espo.Utils.upperCaseFirst(name);
        },

        /**
         * @return {{
         *   translations: Object.<string, string>,
         *   list: string[]
         * }}
         */
        getExportFieldsData: function () {
            let fieldList = this.getFieldManager().getEntityTypeFieldList(this.scope);
            let forbiddenFieldList = this.getAcl().getScopeForbiddenFieldList(this.scope);

            fieldList = fieldList.filter(item => {
                return !~forbiddenFieldList.indexOf(item);
            });

            fieldList = fieldList.filter(item => {
                let defs = this.getMetadata().get(['entityDefs', this.scope, 'fields', item]) || {};

                if (
                    defs.disabled ||
                    defs.exportDisabled ||
                    defs.type === 'map'
                ) {
                    return false
                }

                return true;
            });

            this.getLanguage().sortFieldList(this.scope, fieldList);

            fieldList.unshift('id');

            let fieldListTranslations = {};

            fieldList.forEach(item => {
                fieldListTranslations[item] = this.getLanguage().translate(item, 'fields', this.scope);
            });

            let setFieldList = this.model.get('fieldList') || [];

            setFieldList.forEach(item => {
                if (~fieldList.indexOf(item)) {
                    return;
                }

                if (!~item.indexOf('_')) {
                    return;
                }

                let arr = item.split('_');

                fieldList.push(item);

                let foreignScope = this.getMetadata().get(['entityDefs', this.scope, 'links', arr[0], 'entity']);

                if (!foreignScope) {
                    return;
                }

                fieldListTranslations[item] = this.getLanguage().translate(arr[0], 'links', this.scope) + '.' +
                    this.getLanguage().translate(arr[1], 'fields', foreignScope);
            });

            return {
                list: fieldList,
                translations: fieldListTranslations,
            };
        },

        controlAllFields: function () {
            if (!this.model.get('exportAllFields')) {
                this.showField('fieldList');

                return;
            }

            this.hideField('fieldList');
        },

        controlFormatField: function () {
            let format = this.model.get('format');

            this.formatList
                .filter(item => item !== format)
                .forEach(format => {
                    this.hidePanel(format);
                });

            this.formatList
                .filter(item => item === format)
                .forEach(format => {
                    this.customParams[format].length ?
                        this.showPanel(format) :
                        this.hidePanel(format);
                });
        },
    });
});
