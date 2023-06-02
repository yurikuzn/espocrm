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

const Bundler = require("./bundler");
const Precompiler = require('./template-precompiler');
const {result} = require("underscore");

class BundlerGeneral {

    /**
     * @param {{
     * chunks: Object.<string, {
     *     files?: string[],
     *     patterns?: string[],
     *     allPatterns?: string[],
     *     templatePatterns?: string[],
     *   }>,
     *   modulePaths?: Object.<string, string>,
     *   allPatterns: string[],
     * }} config
     * @param {{
     *    src?: string,
     *    bundle?: boolean,
     *    key?: string,
     *  }[]} libs
     *  @param {string} [filePattern]
     */
    constructor(config, libs, filePattern) {
        this.config = config;
        this.libs = libs;
        this.mainBundleFiles = [];
        this.filePattern = filePattern || 'client/lib/espo-{*}.min.js';
    }

    /**
     * @return {Object.<string, string>}
     */
    bundle() {
        let result = {};
        let mapping = {};
        let mainName = null;

        let names = ['main'].concat(
            Object.keys(this.config.chunks)
                .filter(item => item !== 'main')
        );

        names.forEach((name, i) => {
            const data = this.#bundleChunk(name, i === 0);

            result[name] = data.contents;

            if (i === 0) {
                mainName = name;

                return;
            }

            data.modules.forEach(item => mapping[item] = name);

            let bundleFile = this.filePattern.replace('{*}', name);

            result[mainName] += `\nEspo.loader.mapBundleFile('${name}', '${bundleFile}');\n`;
        });

        let mappingPart = JSON.stringify(mapping);

        result[mainName] += `\nEspo.loader.addBundleMapping(${mappingPart});`

        return result;
    }

    /**
     * @param {string} name
     * @param {boolean} isMain
     * @return {{contents: string, modules: string[]}}
     */
    #bundleChunk(name, isMain) {
        let contents = '';

        let params = this.config.chunks[name];

        let modules = [];

        if (params.patterns) {
            let allPatterns = []
                .concat(this.config.allPatterns)
                .concat(params.allPatterns || []);

            let data = (new Bundler(this.config.modulePaths)).bundle({
                files: params.files,
                patterns: params.patterns,
                allPatterns: allPatterns,
                libs: this.libs,
                ignoreFiles: !isMain ? this.mainBundleFiles : [],
            });

            contents += data.contents;

            if (isMain) {
                this.mainBundleFiles = data.files;
            }

            modules = data.modules;
        }

        if (params.templatePatterns) {
            contents += '\n' +
                (new Precompiler()).precompile({
                    patterns: params.templatePatterns,
                    modulePaths: this.config.modulePaths || {},
                });
        }

        return {
            contents: contents,
            modules: modules,
        };
    }
}

module.exports = BundlerGeneral;
