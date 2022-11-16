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

define('ui/select', ['lib!Selectize'], (Selectize) => {

    /**
     * @typedef module:ui/select~Options
     * @type {Object}
     * @property {{value: string, label: string}[]} items
     * @property {boolean} [selectOnTab=false]
     * @property {boolean} [matchAnyWord=false]
     */

    /**
     * @module ui/select
     */
    let Select = {
        /**
         * @param {JQuery} $el An element.
         * @param {module:ui/select~Options} options Options.
         */
        init: function ($el, options) {
            options = Select.applyDefaultOptions(options);

            let plugins = [];

            Select.loadEspoSelectPlugin();
            plugins.push('espo_select');

            let allowedValues = $el.children().toArray().map(item => {
                return item.getAttributeNode('value').value;
            });

            let removedValue = null;

            let selectizeOptions = {
                plugins: plugins,
                highlight: false,
                selectOnTab: options.selectOnTab,
                copyClassesToDropdown: false,
                allowEmptyOption: false,
                showEmptyOptionInDropdown: true,
                onDelete: function (values)  {
                    if (values.length) {
                        removedValue = values[0];
                    }

                    while (values.length) {
                        this.removeItem(values.pop(), true);
                    }

                    this.showInput();
                    this.positionDropdown();
                    this.refreshOptions(true);
                },
                onBlur: function ()  {
                    let value = $el.val();

                    if (allowedValues.includes(value)) {
                        if (removedValue !== null) {
                            this.trigger('change');
                        }

                        return;
                    }

                    if (removedValue !== null) {
                        this.setValue(removedValue, true);
                    }

                    if (removedValue === null && allowedValues.length) {
                        this.setValue(allowedValues[0], true);
                    }

                    removedValue = null;
                },
            };

            if (!options.matchAnyWord) {
                /** @this Selectize */
                selectizeOptions.score = function (search) {
                    let score = this.getScoreFunction(search);

                    search = search.toLowerCase();

                    return function (item) {
                        if (item.text.toLowerCase().indexOf(search) === 0) {
                            return score(item);
                        }

                        return 0;
                    };
                };
            }

            $el.selectize(selectizeOptions);
        },

        /**
         * Set options.
         *
         * @param {JQuery} $el An element.
         * @param {{value: string, label: string}[]} options Options.
         */
        setOptions: function ($el, options) {
            let selectize = $el.get(0).selectize;

            selectize.clearOptions(true);
            selectize.load(callback => {
                callback(
                    options.map(item => {
                        return {
                            value: item.value,
                            text: item.label,
                        };
                    })
                );
            });
        },

        /**
         * Set value.
         *
         * @param {JQuery} $el An element.
         * @param {string} value A value.
         */
        setValue: function ($el, value) {
            let selectize = $el.get(0).selectize;

            selectize.setValue(value, true);
        },

        /**
         * @private
         * @param {module:ui/select~Options} options
         * @return {module:ui/select~Options}
         */
        applyDefaultOptions: function (options) {
            options = Espo.Utils.clone(options);

            let defaults = {
                selectOnTab: false,
                matchAnyWord: false,
            };

            for (let key in defaults) {
                if (key in options) {
                    continue;
                }

                options[key] = defaults[key];
            }

            return options;
        },

        /**
         * @private
         */
        loadCloseOnClickPlugin: function () {
            if ('close_on_click' in Selectize.plugins) {
                return;
            }

            Selectize.define('close_on_click', function () {
                let self = this;

                this.onFocus = (function() {
                    let original = self.onFocus;

                    return function (e) {
                        let wasFocused = self.isFocused;

                        if (wasFocused) {
                            self.showInput();

                            return;
                        }

                        return original.apply(this, arguments);
                    };
                })();
            });
        },

        /**
         * @private
         */
        loadEspoSelectPlugin: function () {
            if ('espo_select' in Selectize.plugins) {
                return;
            }

            const IS_MAC = /Mac/.test(navigator.userAgent);
            const KEY_BACKSPACE = 8;

            Selectize.define('espo_select', function () {
                let self = this;

                this.onFocus = (function() {
                    let original = self.onFocus;

                    return function (e) {
                        let wasFocused = self.isFocused;

                        if (wasFocused) {
                            self.showInput();

                            return;
                        }

                        return original.apply(this, arguments);
                    };
                })();

                this.onKeyDown = (function() {
                    let original = self.onKeyDown;

                    return function (e) {
                        if (e.code === 'Enter' && (IS_MAC ? e.metaKey : e.ctrlKey)) {
                            return;
                        }

                        if (self.isFull() || self.isInputHidden) {
                            if (
                                e.key.length === 1 &&
                                (
                                    e.key.match(/[a-z]/i) ||
                                    e.key.match(/[0-9]/)
                                )
                            ) {
                                let keyCode = e.keyCode;
                                e.keyCode = KEY_BACKSPACE;

                                self.deleteSelection(e);

                                e.keyCode = keyCode;
                            }
                        }

                        return original.apply(this, arguments);
                    };
                })();
            });
        },
    };

    return Select;
});
