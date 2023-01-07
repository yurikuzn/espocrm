<?php
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

namespace Espo\Tools\Pdf\Dompdf;

use Espo\Core\Htmlizer\TemplateRendererFactory;
use Espo\Core\Utils\Config;
use Espo\ORM\Entity;
use Espo\Tools\Pdf\Data;
use Espo\Tools\Pdf\Params;
use Espo\Tools\Pdf\Template;

class HeadHtmlComposer
{
    public function __construct(
        private Config $config,
        private TemplateRendererFactory $templateRendererFactory
    ) {}

    public function compose(Template $template, Entity $entity, Params $params, Data $data): string
    {
        $topMargin = $template->getTopMargin();
        $rightMargin = $template->getRightMargin();
        $bottomMargin = $template->getBottomMargin();
        $leftMargin = $template->getLeftMargin();

        $fontSize = $this->config->get('pdfFontSize') ?? 12;

        $headerPosition = $template->getHeaderPosition();
        $footerPosition = $template->getFooterPosition();

        $html = "
            <style>
            @page {
                margin: {$topMargin}px {$rightMargin}px {$bottomMargin}px {$leftMargin}px;
            }

            body {
                font-size: {$fontSize}px;
            }

            > header {
                position: fixed;
                margin-top: -{$topMargin}px;
                margin-left: -{$rightMargin}px;
                margin-right: -{$leftMargin}px;
                top: {$headerPosition}px;
                left: 0;
                right: 0;
            }

            > footer {
                position: fixed;
                margin-bottom: -{$bottomMargin}px;
                margin-left: -{$leftMargin}px;
                margin-right: -{$rightMargin}px;
                bottom: {$footerPosition}px;
                left: 0;
                right: 0;
            }
            </style>
        ";

        $renderer = $this->templateRendererFactory
            ->create()
            ->setApplyAcl($params->applyAcl())
            ->setEntity($entity)
            ->setData($data->getAdditionalTemplateData());


        if ($template->hasHeader()) {
            $htmlHeader = $renderer->renderTemplate($template->getHeader());

            $html .= "<header>{$htmlHeader}</header>";
        }

        if ($template->hasFooter()) {
            $htmlFooter = $renderer->renderTemplate($template->getFooter());

            $html .= "<header>{$htmlFooter}</header>";
        }

        return $html;
    }
}
