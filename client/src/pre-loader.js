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

/**
 * A pre-loader.
 *
 * Not used. Maybe utilize for post-loading additional resources in idle, after the page is fully rendered.
 *
 * @class
 * @param {module:cache} cache A cache.
 * @param {Bull.Factory} viewFactory A view factory.
 * @param {string} [basePath] A base path.
 */
const PreLoader = function (cache, viewFactory, basePath) {
    /**
     * @private
     * @type {module:cache}
     */
    this.cache = cache;

    /**
     * @private
     * @type {Bull.Factory}
     */
    this.viewFactory = viewFactory;

    /**
     * @private
     * @type {string}
     */
    this.basePath = basePath || '';
};

_.extend(PreLoader.prototype, /** @lends PreLoader# */{

    /**
     * @private
     * @type {string}
     */
    configUrl: 'client/cfg/pre-load.json',

    /**
     * Load.
     *
     * @param {Function} callback A callback.
     * @param {module:app} app An application instance.
     */
    load: function (callback, app) {
        let bar = $(
            '<div class="progress pre-loading">' +
            '<div class="progress-bar" id="loading-progress-bar" role="progressbar" ' +
            'aria-valuenow="0" style="width: 0"></div></div>'
        ).prependTo('body');

        bar = bar.children();

        bar.css({
            'transition': 'width .1s linear',
            '-webkit-transition': 'width .1s linear'
        });

        let count = 0;
        let countLoaded = 0;
        let classesLoaded = 0;
        let layoutTypesLoaded = 0;
        let templatesLoaded = 0;

        let updateBar = () => {
            let percents = countLoaded / count * 100;

            bar.css('width', percents + '%').attr('aria-valuenow', percents);
        };

        let checkIfReady = () => {
            if (countLoaded >= count) {
                clearInterval(timer);
                callback.call(app, app);
            }
        };

        let timer = setInterval(checkIfReady, 100);

        let load = (data) => {
            data.classes = data.classes || [];
            data.templates = data.templates || [];
            data.layoutTypes = data.layoutTypes || [];

            let d = [];

            data.classes.forEach(item => {
                if (item !== 'views/fields/enum') {
                    d.push(item); // TODO remove this hack
                }
            });

            data.classes = d;

            count = data.templates.length + data.layoutTypes.length+ data.classes.length;

            let loadTemplates = () => {
                data.templates.forEach(name =>  {
                    this.viewFactory._loader.load('template', name, () => {
                        templatesLoaded++;
                        countLoaded++;

                        updateBar();
                    });
                });
            };

            let loadLayoutTypes = () => {
                data.layoutTypes.forEach(name => {
                    this.viewFactory._loader.load('layoutTemplate', name, () => {
                        layoutTypesLoaded++;
                        countLoaded++;

                        updateBar();
                    });
                });
            };

            let loadClasses = () => {
                data.classes.forEach(name => {
                    Espo.loader.require(name, () => {
                        classesLoaded++;
                        countLoaded++;

                        updateBar();
                    });
                });
            };

            loadTemplates();
            loadLayoutTypes();
            loadClasses();
        };

        Espo.Ajax
            .getRequest(this.basePath + this.configUrl, null, {
                dataType: 'json',
                local: true,
            })
            .then(data => load(data));
    }
});

export default PreLoader;
