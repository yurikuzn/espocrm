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

define('crm:views/opportunity/detail', ['views/detail'], function (Dep) {

    return Dep.extend({

        relatedAttributeFunctions: {
            'documents': function () {
                var data = {};
                if (this.model.get('accountId')) {
                    data['accountsIds'] = [this.model.get('accountId')]
                }
                return data;
            }
        },

        selectRelatedFilters: {
            'contacts': {
                'account': function () {
                    if (this.model.get('accountId')) {
                        return {
                            attribute: 'accountId',
                            type: 'equals',
                            value: this.model.get('accountId'),
                            data: {
                                type: 'is',
                                nameValue: this.model.get('accountName')
                            }
                        };
                    }
                },
            },
            'documents': {
                'accounts': function () {
                    var accountId = this.model.get('accountId');
                    if (accountId) {
                        var nameHash = {};
                        nameHash[accountId] = this.model.get('accountName');
                        return {
                            field: 'accounts',
                            type: 'linkedWith',
                            value: [accountId],
                            data: {
                                nameHash: nameHash
                            }
                        };
                    }
                },
            },
        },
    });
});
