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

/** @module views/record/panels/side */

import Dep from 'view';

/**
 * A side panel.
 *
 * @class
 * @name Class
 * @extends module:view
 */
export default Dep.extend(/** @lends Class# */{

    template: 'record/panels/side',

    /**
     * A field defs.
     *
     * @typedef module:views/record/panels/side~field
     *
     * @property {string} name
     * @property {string} [labelText] A translated label text.
     * @property {string} [view] A view name.
     * @property {boolean} [isAdditional]
     * @property {boolean} [readOnly]
     * @property {Object.<string,*>} [options] Options.
     */

    /**
     * A field list.
     *
     * @protected
     * @type {module:views/record/panels/side~field[]}
     */
    fieldList: null,

    /**
     * A mode.
     *
     * @protected
     * @type {'list'|'detail'|'edit'}
     */
    mode: 'detail',

    /**
     * @protected
     * @type {module:views/record/panels-container~action[]}
     */
    actionList: null,

    /**
     * @protected
     * @type {module:views/record/panels-container~button[]}
     */
    buttonList: null,

    /**
     * Read-only.
     *
     * @protected
     */
    readOnly: false,

    /**
     * Disable inline edit.
     *
     * @protected
     */
    inlineEditDisabled: false,

    /**
     * Disable.
     *
     * @protected
     */
    disabled: false,

    data: function () {
        return {
            fieldList: this.getFieldList(),
            hiddenFields: this.recordHelper.getHiddenFields(),
        };
    },

    events: {
        'click .action': function (e) {
            var $el = $(e.currentTarget);
            var action = $el.data('action');
            var method = 'action' + Espo.Utils.upperCaseFirst(action);

            if (typeof this[method] === 'function') {
                var data = $el.data();
                this[method](data, e);

                e.preventDefault();
            }
        }
    },

    init: function () {
        this.panelName = this.options.panelName;
        this.defs = this.options.defs || {};
        this.recordHelper = this.options.recordHelper;

        if ('disabled' in this.options) {
            this.disabled = this.options.disabled;
        }

        this.buttonList = _.clone(this.defs.buttonList || this.buttonList || []);
        this.actionList = _.clone(this.defs.actionList || this.actionList || []);

        this.fieldList = this.options.fieldList || this.fieldList || this.defs.fieldList || [];

        this.mode = this.options.mode || this.mode;

        this.readOnlyLocked = this.options.readOnlyLocked || this.readOnly;
        this.readOnly = this.readOnly || this.options.readOnly;
        this.inlineEditDisabled = this.inlineEditDisabled || this.options.inlineEditDisabled;

        this.recordViewObject = this.options.recordViewObject;
    },

    setup: function () {
        this.setupFields();

        this.fieldList = this.fieldList.map(d => {
            var item = d;

            if (typeof item !== 'object') {
                item = {
                    name: item,
                    viewKey: item + 'Field'
                };
            }

            item = Espo.Utils.clone(item);
            item.viewKey = item.name + 'Field';
            item.label = item.label || item.name;

            if (this.recordHelper.getFieldStateParam(item.name, 'hidden') !== null) {
                item.hidden = this.recordHelper.getFieldStateParam(item.name, 'hidden');
            } else {
                this.recordHelper.setFieldStateParam(item.name, item.hidden || false);
            }

            return item;
        });

        this.fieldList = this.fieldList.filter((item) => {
            if (!item.name) {
                return;
            }

            if (!item.isAdditional) {
                if (!(item.name in (((this.model.defs || {}).fields) || {}))) return;
            }

            return true;
        });

        this.createFields();
    },

    afterRender: function () {
        if (this.$el.children().length === 0) {
            this.$el.parent().addClass('hidden');
        }
    },

    /**
     * Set up fields.
     *
     * @protected
     */
    setupFields: function () {},

    /**
     * Create a field view.
     *
     * @protected
     * @param {string} field A field name.
     * @param {string|null} [viewName] A view name/path.
     * @param {Object<string,*>} [params] Field params.
     * @param {'detail'|'edit'|'list'|null} [mode='edit'] A mode.
     * @param {boolean} [readOnly] Read-only.
     * @param {Object<string,*>} [options] View options.
     */
    createField: function (field, viewName, params, mode, readOnly, options) {
        var type = this.model.getFieldType(field) || 'base';

        viewName = viewName ||
            this.model.getFieldParam(field, 'view') ||
            this.getFieldManager().getViewName(type);

        var o = {
            model: this.model,
            el: this.options.el + ' .field[data-name="' + field + '"]',
            defs: {
                name: field,
                params: params || {},
            },
            mode: mode || this.mode,
        };

        if (options) {
            for (var param in options) {
                o[param] = options[param];
            }
        }

        var readOnlyLocked = this.readOnlyLocked;

        if (this.readOnly) {
            o.readOnly = true;
        }
        else {
            if (readOnly !== null) {
                o.readOnly = readOnly;
            }
        }

        if (readOnly) {
            readOnlyLocked = true;
        }

        if (this.inlineEditDisabled) {
            o.inlineEditDisabled = true;
        }

        if (this.recordHelper.getFieldStateParam(field, 'hidden')) {
            o.disabled = true;
        }

        if (this.recordHelper.getFieldStateParam(field, 'hiddenLocked')) {
            o.disabledLocked = true;
        }

        if (this.recordHelper.getFieldStateParam(field, 'readOnly')) {
            o.readOnly = true;
        }

        if (this.recordHelper.getFieldStateParam(field, 'required') !== null) {
            o.defs.params.required = this.recordHelper.getFieldStateParam(field, 'required');
        }

        if (!readOnlyLocked && this.recordHelper.getFieldStateParam(field, 'readOnlyLocked')) {
            readOnlyLocked = true;
        }

        if (readOnlyLocked) {
            o.readOnlyLocked = readOnlyLocked;
        }

        if (this.recordHelper.hasFieldOptionList(field)) {
            o.customOptionList = this.recordHelper.getFieldOptionList(field);
        }

        if (this.recordViewObject) {
            o.validateCallback = () => this.recordViewObject.validateField(field);
        }

        o.recordHelper = this.recordHelper;

        var viewKey = field + 'Field';

        this.createView(viewKey, viewName, o);
    },

    /**
     * @private
     */
    createFields: function () {
        this.getFieldList().forEach(item => {
            var view = null;
            var field;
            var readOnly = null;

            if (typeof item === 'object') {
                field = item.name;
                view = item.view;

                if ('readOnly' in item) {
                    readOnly = item.readOnly;
                }
            }
            else {
               field = item;
            }

            if (!item.isAdditional) {
                if (!(field in this.model.defs.fields)) {
                    return;
                }
            }

            this.createField(field, view, null, null, readOnly, item.options);
        });
    },

    /**
     * @deprecated Use `getFieldViews`.
     */
    getFields: function () {
        return this.getFieldViews();
    },

    /**
     * Get field views.
     *
     * @return {Object.<string,module:views/fields/base.Class>}
     */
    getFieldViews: function () {
        var fields = {};

        this.getFieldList().forEach(item => {
            if (this.hasView(item.viewKey)) {
                fields[item.name] = this.getView(item.viewKey);
            }
        });

        return fields;
    },

    /**
     * Get a field list.
     *
     * @return {module:views/record/panels/side~field[]}
     */
    getFieldList: function () {
        return this.fieldList.map(item => {
            if (typeof item !== 'object') {
                return {
                    name: item,
                };
            }

            return item;
        });
    },

    /**
     * @return {module:views/record/panels-container~action[]}
     */
    getActionList: function () {
        return this.actionList || [];
    },

    /**
     * @return {module:views/record/panels-container~button[]}
     */
    getButtonList: function () {
        return this.buttonList || [];
    },

    /**
     * A `refresh` action.
     */
    actionRefresh: function () {
        this.model.fetch();
    },

    /**
     * Is tab-hidden.
     *
     * @return {boolean}
     */
    isTabHidden: function () {
        if (this.defs.tabNumber === -1 || typeof this.defs.tabNumber === 'undefined') {
            return false;
        }

        let parentView = this.getParentView();

        if (!parentView) {
            return this.defs.tabNumber > 0;
        }

        if (parentView && parentView.hasTabs) {
            return parentView.currentTab !== defs.tabNumber;
        }

        return false;
    },
});
