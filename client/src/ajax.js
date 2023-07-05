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

/** @module ajax */

let isConfigured = false;
/** @type {number} */
let defaultTimeout;
/** @type {string} */
let apiUrl;
/** @type {function (xhr: XMLHttpRequest, options: Object.<string, *>)} */
let beforeSend;
/** @type {function (xhr: XMLHttpRequest, options: Object.<string, *>)} */
let onSuccess;
/** @type {function (xhr: XMLHttpRequest, options: Espo.Ajax~CatchOptions)} */
let onError;
/** @type {function (xhr: XMLHttpRequest, options: Object.<string, *>)} */
let onTimeout;

const baseUrl = window.location.origin + window.location.pathname;

// noinspection JSUnusedGlobalSymbols
/**
 * Functions for API HTTP requests.
 */
const Ajax = Espo.Ajax = {

    /**
     * Options.
     *
     * @typedef {Object} Espo.Ajax~Options
     *
     * @property {Number} [timeout] A timeout.
     * @property {Object.<string, string>} [headers] A request headers.
     * @property {'json'|'text'} [dataType] A data type.
     * @property {string} [contentType] A content type.
     * @property {boolean} [fullResponse] To resolve with `module:ajax.XhrWrapper`.
     */

    /**
     * @typedef {Object} Espo.Ajax~CatchOnlyOptions
     * @property {boolean} errorIsHandled
     */

    /**
     * @typedef {Espo.Ajax~Options & Espo.Ajax~CatchOnlyOptions & Object.<string, *>} Espo.Ajax~CatchOptions
     */

    /**
     * Request.
     *
     * @param {string} url An URL.
     * @param {'GET'|'POST'|'PUT'|'DELETE'|'PATCH'|'OPTIONS'} method An HTTP method.
     * @param {*} [data] Data.
     * @param {Espo.Ajax~Options & Object.<string, *>} [options] Options.
     * @returns {AjaxPromise<any>}
     */
    request: function (url, method, data, options) {
        options = {...options};

        let timeout = 'timeout' in options ? options.timeout : defaultTimeout;

        if (apiUrl) {
            url = Espo.Utils.trimSlash(apiUrl) + '/' + url;
        }

        let urlObj = new URL(baseUrl + url);

        if (method === 'GET' && data) {
            for (let key in data) {
                urlObj.searchParams.append(key, data[key]);
            }
        }

        let xhr = new XMLHttpRequest();

        xhr.timeout = timeout;

        xhr.open(method, urlObj);

        let contentType = options.contentType || 'application/json';

        xhr.setRequestHeader('Content-Type', contentType);

        let body;

        if (!['GET', 'OPTIONS'].includes(method) && data) {
            body = data;

            if (contentType === 'application/json') {
                body = JSON.stringify(data);
            }
        }

        if (beforeSend) {
            beforeSend(xhr, options);
        }

        let promiseWrapper = {};

        let promise = new AjaxPromise((resolve, reject) => {
            const onErrorGeneral = (isTimeout) => {
                if (options.error) {
                    options.error(xhr, options);
                }

                reject(xhr, options);

                if (isTimeout) {
                    if (onTimeout) {
                        onTimeout(xhr, options);
                    }

                    return;
                }

                // @todo Check if executed after catch.
                if (onError) {
                    onError(xhr, /** @type {Espo.Ajax~CatchOptions} */options);
                }
            };

            xhr.ontimeout = () => onErrorGeneral(true);
            xhr.onerror = () => onErrorGeneral();

            xhr.onload = () => {
                if (xhr.status >= 400) {
                    onErrorGeneral();

                    return;
                }

                let response = xhr.responseText;

                if ((options.dataType || 'json') === 'json') {
                    try {
                        response = JSON.parse(xhr.responseText)
                    }
                    catch (e) {
                        console.error('Could not parse API response.');

                        onErrorGeneral();
                    }
                }

                if (options.success) {
                    options.success(response);
                }

                onSuccess(xhr, options);

                // @todo Revise. Pass xhr as a second parameter.
                let obj = options.fullResponse ? new XhrWrapper(xhr) : response;

                resolve(obj)
            }

            xhr.send(body);

            if (promiseWrapper.promise) {
                promiseWrapper.promise.xhr = xhr;

                return;
            }

            promiseWrapper.xhr = xhr;
        });

        promiseWrapper.promise = promise;
        promise.xhr = promise.xhr || promiseWrapper.xhr;

        return promise;
    },

    /**
     * POST request.
     *
     * @param {string} url An URL.
     * @param {*} [data] Data.
     * @param {Espo.Ajax~Options & Object.<string, *>} [options] Options.
     * @returns {Promise<any>}
     */
    postRequest: function (url, data, options) {
        if (data) {
            data = JSON.stringify(data);
        }

        return /** @type {Promise<any>} */ Ajax.request(url, 'POST', data, options);
    },

    /**
     * PATCH request.
     *
     * @param {string} url An URL.
     * @param {*} [data] Data.
     * @param {Espo.Ajax~Options & Object.<string, *>} [options] Options.
     * @returns {Promise<any>}
     */
    patchRequest: function (url, data, options) {
        if (data) {
            data = JSON.stringify(data);
        }

        return /** @type {Promise<any>} */ Ajax.request(url, 'PATCH', data, options);
    },

    /**
     * PUT request.
     *
     * @param {string} url An URL.
     * @param {*} [data] Data.
     * @param {Espo.Ajax~Options & Object.<string, *>} [options] Options.
     * @returns {Promise<any>}
     */
    putRequest: function (url, data, options) {
        if (data) {
            data = JSON.stringify(data);
        }

        return /** @type {Promise<any>} */ Ajax.request(url, 'PUT', data, options);
    },

    /**
     * DELETE request.
     *
     * @param {string} url An URL.
     * @param {*} [data] Data.
     * @param {Espo.Ajax~Options & Object.<string, *>} [options] Options.
     * @returns {Promise<any>}
     */
    deleteRequest: function (url, data, options) {
        if (data) {
            data = JSON.stringify(data);
        }

        return /** @type {Promise<any>} */ Ajax.request(url, 'DELETE', data, options);
    },

    /**
     * GET request.
     *
     * @param {string} url An URL.
     * @param {*} [data] Data.
     * @param {Espo.Ajax~Options & Object.<string, *>} [options] Options.
     * @returns {Promise<any>}
     */
    getRequest: function (url, data, options) {
        return /** @type {Promise<any>} */ Ajax.request(url, 'GET', data, options);
    },

    /**
     * @internal
     * @param {{
     *     apiUrl: string,
     *     timeout: number,
     *     beforeSend: function (xhr: XMLHttpRequest, options: Object.<string, *>),
     *     onSuccess: function (xhr: XMLHttpRequest, options: Object.<string, *>),
     *     onError: function (xhr: XMLHttpRequest, options: Espo.Ajax~CatchOptions),
     *     onTimeout: function (xhr: XMLHttpRequest, options: Object.<string, *>),
     * }} options Options.
     */
    configure: function (options) {
        if (isConfigured) {
            throw new Error("Ajax is already configured.");
        }

        apiUrl = options.apiUrl;
        defaultTimeout = options.timeout;
        beforeSend = options.beforeSend;
        onSuccess = options.onSuccess;
        onError = options.onError;
        onTimeout = options.onTimeout;

        isConfigured = true;
    },
};

/**
 * @memberOf module:ajax
 */
class AjaxPromise extends Promise {

    /**
     * @type {XMLHttpRequest|null}
     * @internal
     */
    xhr = null

    isAborted = false

    /**
     * @deprecated Use `catch`.
     * @todo Remove in v9.0.
     */
    fail(...args) {
        return this.catch(args[0]);
    }
    /**
     * @deprecated Use `then`
     * @todo Remove in v9.0.
     */
    done(...args) {
        return this.then(args[0]);
    }

    /**
     * Abort the request.
     */
    abort() {
        this.isAborted = true;

        if (this.xhr) {
            this.xhr.abort();
        }
    }

    /**
     * Get a ready state.
     *
     * @return {Number}
     */
    getReadyState() {
        if (!this.xhr) {
            return 0;
        }

        return this.xhr.readyState || 0;
    }

    /**
     * Get a status code
     *
     * @return {Number}
     */
    getStatus() {
        if (!this.xhr) {
            return 0;
        }

        return this.xhr.status;
    }
}

/**
 * @name module:ajax.XhrWrapper
 */
class XhrWrapper {

    /**
     * @param {XMLHttpRequest} xhr
     */
    constructor(xhr) {
        this.xhr = xhr;
    }

    /**
     * @param {string} name
     * @return {string}
     */
    getResponseHeader(name) {
        return this.xhr.getResponseHeader(name);
    }

    /**
     * @return {Number}
     */
    getStatus() {
        return this.xhr.status;
    }
}

export default Ajax;
