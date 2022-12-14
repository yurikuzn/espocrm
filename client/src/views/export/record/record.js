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

define('views/export/record/record', ['views/record/base'], function (Dep) {

    return Dep.extend({

        template: 'export/record/record',

        setup: function () {
            Dep.prototype.setup.call(this);

            this.scope = this.options.scope;

            let fieldList = this.getFieldManager().getEntityTypeFieldList(this.scope);
            let forbiddenFieldList = this.getAcl().getScopeForbiddenFieldList(this.scope);

            fieldList = fieldList.filter(item => {
                return !~forbiddenFieldList.indexOf(item);
            });

            fieldList = fieldList.filter(item => {
                var defs = this.getMetadata().get(['entityDefs', this.scope, 'fields', item]) || {};

                if (defs.disabled) {
                    return;
                }

                if (defs.exportDisabled) {
                    return;
                }

                if (defs.type === 'map') {
                    return;
                }

                return true;
            });

            this.getLanguage().sortFieldList(this.scope, fieldList);

            fieldList.unshift('id');

            let translatedOptions = {};

            fieldList.forEach(item => {
                translatedOptions[item] = this.getLanguage().translate(item, 'fields', this.scope);
            });

            this.createField('exportAllFields', 'views/fields/bool', {});

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

                translatedOptions[item] = this.getLanguage().translate(arr[0], 'links', this.scope) + '.' +
                    this.getLanguage().translate(arr[1], 'fields', foreignScope);
            });

            this.createField('fieldList', 'views/fields/multi-enum', {
                required: true,
                translatedOptions: translatedOptions,
                options: fieldList,
            });

            let formatList =
                this.getMetadata().get(['scopes', this.scope, 'exportFormatList']) ||
                this.getMetadata().get('app.export.formatList');

            this.createField('format', 'views/fields/enum', {
                options: formatList
            });

            this.controlAllFields();

            this.listenTo(this.model, 'change:exportAllFields', () => {
                this.controlAllFields();
            });
        },

        controlAllFields: function () {
            if (!this.model.get('exportAllFields')) {
                this.showField('fieldList');

                return;
            }

            this.hideField('fieldList');
        },
    });
});
