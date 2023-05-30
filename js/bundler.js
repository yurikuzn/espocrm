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

const typescript = require('typescript');
const fs = require('fs');

/**
 * Normalizes and concatenates Espo modules.
 */
class Bundler {

    /**
     * @private
     * @type {string}
     */
    basePath = 'client/src'

    /**
     * @param {string[]} pathList
     * @return {string}
     */
    bundle(pathList) {
        let bundleContents = '';

        pathList = this.#sortPathList(pathList);

        pathList.forEach(path => {
            bundleContents += this.normalizeSourceFile(path);
        });

        return bundleContents;
    }

    /**
     * @param {string[]} pathList
     * @return {string[]}
     */
    #sortPathList(pathList) {
        /** @var {Object.<string, string[]>} */
        let map = {};

        let standalonePathList = [];

        let tree = {};
        let moduleList = [];

        pathList.forEach(path => {
            let data = this.#obtainModuleData(path);

            if (!data) {
                standalonePathList.push(path);

                return;
            }

            map[data.name] = data.deps;
            moduleList.push(data.name);
        });

        /** @var {Object.<string, number>} */
        let depthMap = {};

        for (let name in map) {
            this.#buildTreeItem(name, tree, map, depthMap);
        }

        moduleList.sort((v1, v2) => {
            return depthMap[v1] - depthMap[v2];
        });

        let modulePathList = moduleList.map(name => {
            return name + '.js';
        });

        return standalonePathList.concat(modulePathList);
    }

    /**
     * @param {string} name
     * @param {Object} tree
     * @param {Object.<string, string[]>} map
     * @param {Object.<string, number>} depthMap
     * @param {number} [depth]
     */
    #buildTreeItem(name, tree, map, depthMap, depth) {
        let deps = map[name] || [];
        depth = depth || 0;

        tree[name] = {};

        if (deps.length === 0) {
            if (!(name in depthMap)) {
                depthMap[name] = depth;

                return;
            }

            if (depth > depthMap[name]) {
                depthMap[name] = depth;

                return;
            }

            return;
        }

        deps.forEach(depName => {
            this.#buildTreeItem(
                depName,
                tree[name],
                map,
                depthMap,
                depth + 1
            );
        });
    }

    /**
     * @param {string} path
     * @return {{deps: string[], name: string}|null}
     */
    #obtainModuleData(path) {
        if (!this.#isClientJsFile(path)) {
            return null;
        }

        let tsSourceFile = typescript.createSourceFile(
            path,
            fs.readFileSync(path, 'utf-8'),
            typescript.ScriptTarget.Latest
        );

        let rootStatement = tsSourceFile.statements[0];

        if (
            !rootStatement.expression ||
            !rootStatement.expression.expression ||
            rootStatement.expression.expression.escapedText !== 'define'
        ) {
            return null;
        }

        let moduleName = path.slice(this._getBathPath().length, -3);

        let deps = [];

        let argumentList = rootStatement.expression.arguments;

        for (let argument of argumentList.slice(0, 2)) {
            if (argument.elements) {
                argument.elements.forEach(node => {
                    if (!node.text) {
                        return;
                    }

                    deps.push(node.text);
                });
            }
        }

        return {
            name: moduleName,
            deps: deps,
        };
    }

    /**
     * @param {string} path
     * @return {boolean}
     */
    #isClientJsFile(path) {
        return path.indexOf(this._getBathPath()) === 0 && path.slice(-3) === '.js';
    }

    /**
     * @private
     * @param {string} path
     * @return {string}
     */
    normalizeSourceFile(path) {
        let sourceCode = fs.readFileSync(path, 'utf-8');
        let basePath = this._getBathPath();

        if (!this.#isClientJsFile(path)) {
            return sourceCode;
        }

        let moduleName = path.slice(basePath.length, -3);

        let tsSourceFile = typescript.createSourceFile(
            path,
            sourceCode,
            typescript.ScriptTarget.Latest
        );

        let rootStatement = tsSourceFile.statements[0];

        if (
            !rootStatement.expression ||
            !rootStatement.expression.expression ||
            rootStatement.expression.expression.escapedText !== 'define'
        ) {
            return sourceCode;
        }

        let argumentList = rootStatement.expression.arguments;

        if (argumentList.length >= 3 || argumentList.length === 0) {
            return sourceCode;
        }

        let moduleNameNode = typescript.createStringLiteral(moduleName);

        if (argumentList.length === 1) {
            argumentList.unshift(
                typescript.createArrayLiteral([])
            );
        }

        argumentList.unshift(moduleNameNode);

        return typescript.createPrinter().printFile(tsSourceFile);
    }

    /**
     * @private
     * @return {string}
     */
    _getBathPath() {
        let path = this.basePath;

        if (path.slice(-1) !== '/') {
            path += '/';
        }

        return path;
    }
}

module.exports = Bundler;
