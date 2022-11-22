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

define('crm:views/meeting/modals/send-invitations', ['views/modal', 'collection'], function (Dep, Collection) {
    /**
     * @module crm_views/meeting/modals/send-invitations
     */

    /**
     * @class
     * @name Class
     * @extends module:views/modal.Class
     * @memberOf module:crm_views/meeting/modals/send-invitations
     */
    return Dep.extend(/** @lends module:crm_views/meeting/modals/send-invitations.Class# */{

        backdrop: true,

        templateContent: `
            <div class="margin-bottom">
                <p>{{message}}</p>
            </div>
            <div class="list-container">{{{list}}}</div>
        `,

        data: function () {
            return {
                message: this.translate('sendInvitationsConfirmation', 'messages', 'Meeting'),
            };
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.shortcutKeys = {};
            this.shortcutKeys['Control+Enter'] = e => {
                if (!this.hasAvailableActionItem('send')) {
                    return;
                }

                e.preventDefault();

                this.actionSend();
            };

            this.$header = $('<span>').append(
                $('<span>')
                    .text(this.translate(this.model.entityType, 'scopeNames')),
                ' <span class="chevron-right"></span> ',
                $('<span>')
                    .text(this.model.get('name')),
                ' <span class="chevron-right"></span> ',
                $('<span>')
                    .text(this.translate('Send Invitations', 'labels', 'Meeting'))
            );

            this.addButton({
                label: 'Send',
                name: 'send',
                style: 'danger',
                disabled: true,
            });

            this.addButton({
                label: 'Cancel',
                name: 'cancel',
            });

            this.collection = new Collection();
            this.collection.url = this.model.entityType + `/${this.model.id}/attendees`;

            this.wait(
                this.collection.fetch()
                    .then(() => {
                        this.collection.models.forEach(model => {
                            model.entityType = model.get('_scope');
                        });

                        return this.createView('list', 'views/record/list', {
                            selector: '.list-container',
                            collection: this.collection,
                            rowActionsDisabled: true,
                            massActionsDisabled: true,
                            checkAllResultDisabled: true,
                            selectable: true,
                            buttonsDisabled: true,
                            listLayout: [
                                {
                                    name: 'name',
                                    noLabel: true,
                                    notSortable: true,
                                },
                                {
                                    name: 'acceptanceStatus',
                                    width: 40,
                                    noLabel: true,
                                    notSortable: true,
                                    view: 'views/fields/enum',
                                    params: {
                                        options: this.model.getFieldParam('acceptanceStatus', 'options'),
                                        style: this.model.getFieldParam('acceptanceStatus', 'style'),
                                    },
                                },
                            ],
                        });
                    })
                    .then(view => {
                        view.selectAllHandler(true);

                        this.listenTo(view, 'check', () => this.controlSendButton());
                    })
            );
        },

        controlSendButton: function () {
            this.getListView().checkedList.length ?
                this.enableButton('send') :
                this.disableButton('send');
        },

        /**
         * @return {module:views/record/list.Class}
         */
        getListView: function () {
            return this.getView('list');
        },

        actionSend: function () {
            this.disableButton('sendInvitations');

            Espo.Ui.notify(' ... ');

            let targets = this.getListView().checkedList.map(id => {
                return {
                    entityType: this.collection.get(id).entityType,
                    id: id,
                };
            });

            Espo.Ajax
                .postRequest(this.model.entityType + '/action/sendInvitations', {
                    id: this.model.id,
                    targets: targets,
                })
                .then(result => {
                    result ?
                        Espo.Ui.success(this.translate('Sent')) :
                        Espo.Ui.warning(this.translate('nothingHasBeenSent', 'messages', 'Meeting'));

                    this.close();
                })
                .catch(() => {
                    this.enableButton('send');
                });
        },
    });
});
