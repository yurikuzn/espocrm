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

import ModalView from 'views/modal';
import Model from 'model';
import EditForModalRecordView from 'views/record/edit-for-modal';

class EntityManagerExportModalView extends ModalView {

    // language=Handlebars
    templateContent = `
        <div class="record-container no-side-margin">{{{record}}}</div>
    `

    setup() {
        this.headerText = this.translate('Export');

        this.buttonList = [
            {
                name: 'export',
                label: 'Export',
                style: 'danger',
                onClick: () => this.export(),
            },
            {
                name: 'cancel',
                label: 'Cancel',
            },
        ];

        let manifest = this.getConfig().get('customExportManifest') || {};

        this.model = new Model({
            name: manifest.name ?? null,
            module: manifest.module ?? null,
            version: manifest.version ?? '0.0.1',
            author: manifest.author ?? null,
            description: manifest.description ?? null,
        });

        this.recordView = new EditForModalRecordView({
            model: this.model,
            detailLayout: [
                {
                    rows: [
                        [
                            {
                                name: 'name',
                                type: 'varchar',
                                params: {
                                    pattern: '$latinLettersDigitsWhitespace',
                                    required: true,
                                },
                                labelText: this.translate('name', 'fields'),
                            },
                            {
                                name: 'module',
                                type: 'varchar',
                                params: {
                                    pattern: '[A-Z][a-z][A-Za-z]+',
                                    required: true,
                                },
                                labelText: this.translate('module', 'fields', 'EntityManager'),
                            },
                        ],
                        [
                            {
                                name: 'version',
                                type: 'varchar',
                                params: {
                                    pattern: '[0-9]\\.[0-9]\\.[0-9]+',
                                    required: true,
                                },
                                labelText: this.translate('version', 'fields', 'EntityManager'),
                            },
                            false
                        ],
                        [
                            {
                                name: 'author',
                                type: 'varchar',
                                params: {
                                    required: true,
                                },
                                labelText: this.translate('author', 'fields', 'EntityManager'),
                            },
                            {
                                name: 'description',
                                type: 'varchar',
                                params: {},
                                labelText: this.translate('description', 'fields'),
                            },
                        ],
                    ]
                }
            ]
        });

        this.assignView('record', this.recordView);
    }

    export() {
        let data = this.recordView.fetch();

        if (this.recordView.validate()) {
            return;
        }

        this.disableButton('export');

        Espo.Ui.notify(' ... ');

        Espo.Ajax
            .postRequest('EntityManager/action/exportCustom', data)
            .then(response => {
                this.close();

                this.getConfig().set('customExportManifest', data);

                Espo.Ui.success(this.translate('Done'));

                window.location = this.getBasePath() + '?entryPoint=download&id=' + response.id;
            })
            .catch(() => this.enableButton('create'));
    }
}

export default EntityManagerExportModalView;
