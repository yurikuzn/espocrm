/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2024 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

/**
 * @internal
 */
export default class ListColumnResizeHelper {

    /**
     * @type {{
     *     startX: number,
     *     startWidth: number,
     *     thElement: HTMLTableCellElement,
     *     name: string,
     *     inPx: boolean,
     *     onRight: boolean,
     *     newWidth: number|null,
     *     thElements: HTMLTableCellElement[],
     * }}
     * @private
     */
    item

    /**
     * A min width in pixels.
     *
     * @private
     * @type {number}
     */
    minWidth = 30

    static selector = 'table > thead > tr > th > .column-resizer';

    /**
     * @param {import('views/record/list').default} view
     */
    constructor(view) {
        /** @private */
        this.view = view;

        this.onMouseUpBind = this.onMouseUp.bind(this);
        this.onMouseMoveBind = this.onMouseMove.bind(this);

        view.addHandler('mousedown', ListColumnResizeHelper.selector, (/** MouseEvent */e, target) => {
            this.onResizerMouseDown(e, target);
        });
    }

    /**
     * @private
     * @param {MouseEvent} event
     * @param {HTMLElement} target
     */
    onResizerMouseDown(event, target) {
        const th = /** @type {HTMLTableCellElement} */target.parentNode;

        const thElements = [...th.parentNode.querySelectorAll(':scope > th.field-header-cell')]
            .filter(it => !it.style.width);

        this.item = {
            startX: event.screenX,
            startWidth: th.clientWidth,
            thElement: th,
            name: th.dataset.name,
            inPx: th.style.width && th.style.width.endsWith('px'),
            onRight: target.classList.contains('column-resizer-right'),
            newWidth: null,
            thElements: thElements,
        };

        document.body.style.cursor = 'col-resize';

        document.addEventListener('mouseup', this.onMouseUpBind);
        document.addEventListener('mousemove', this.onMouseMoveBind);
    }

    /**
     * @private
     * @param {number} width
     */
    isWidthOk(width) {
        if (width < this.minWidth) {
            return false;
        }

        for (const th of this.item.thElements) {
            if (th.style.width) {
                continue;
            }

            if (th.clientWidth < this.minWidth) {
                return false;
            }
        }

        return true;
    }

    /**
     * @private
     * @param {MouseEvent} event
     */
    onMouseMove(event) {
        let diff = event.screenX - this.item.startX;

        if (!this.item.onRight) {
            diff *= -1;
        }

        const width = this.item.startWidth + diff;

        if (!this.isWidthOk(width)) {
            return;
        }

        const previousWidth = this.item.newWidth;
        const previousStyleWidth = this.item.thElement.style.width;

        this.item.newWidth = width;
        this.item.thElement.style.width = width.toString() + 'px';

        if (!this.isWidthOk(width)) {
            if (previousWidth) {
                this.item.newWidth = previousWidth;
            }

            this.item.thElement.style.width = previousStyleWidth;
        }
    }

    /**
     * @private
     * @param {MouseEvent} event
     */
    onMouseUp(event) {
        document.removeEventListener('mousemove', this.onMouseMoveBind);
        document.removeEventListener('mouseup', this.onMouseUpBind);

        document.body.style.cursor = '';

        console.log(event);

        this.item = undefined;
    }
}
