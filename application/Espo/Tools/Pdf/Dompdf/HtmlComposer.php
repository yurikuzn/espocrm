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

class HtmlComposer
{
    public function __construct(
        private Config $config,
        private TemplateRendererFactory $templateRendererFactory,
        private ImageSourceProvider $imageSourceProvider
    ) {}

    public function composeHead(Template $template): string
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
                margin: {$topMargin}mm {$rightMargin}mm {$bottomMargin}mm {$leftMargin}mm;
            }

            body {
                font-size: {$fontSize}pt;
            }

            > header {
                position: fixed;
                margin-top: -{$topMargin}mm;
                margin-left: -{$rightMargin}mm;
                margin-right: -{$leftMargin}mm;
                top: {$headerPosition}mm;
                left: 0;
                right: 0;
            }

            > footer {
                position: fixed;
                margin-bottom: -{$bottomMargin}mm;
                margin-left: -{$leftMargin}mm;
                margin-right: -{$rightMargin}mm;
                bottom: {$footerPosition}mm;
                left: 0;
                right: 0;
            }

            > header .page-number:after,
            > footer .page-number:after {
                content: counter(page);
            }
            </style>
        ";

        return $html;
    }

    public function composeHeaderFooter(Template $template, Entity $entity, Params $params, Data $data): string
    {
        $html = "";

        $renderer = $this->templateRendererFactory
            ->create()
            ->setApplyAcl($params->applyAcl())
            ->setEntity($entity)
            ->setSkipInlineAttachmentHandling(true)
            ->setData($data->getAdditionalTemplateData());

        // @todo Apply pagination tags.

        if ($template->hasHeader()) {
            $htmlHeader = $renderer->renderTemplate($template->getHeader());

            $htmlHeader = $this->replaceHeadTags($htmlHeader);

            $html .= "<header>{$htmlHeader}</header>";
        }

        if ($template->hasFooter()) {
            $htmlFooter = $renderer->renderTemplate($template->getFooter());

            $htmlFooter = $this->replaceHeadTags($htmlFooter);

            $html .= "<footer>{$htmlFooter}</footer>";
        }

        return $html;
    }

    public function composeMain(
        Template $template,
        Entity $entity,
        Params $params,
        Data $data
    ): string {

        $renderer = $this->templateRendererFactory
            ->create()
            ->setApplyAcl($params->applyAcl())
            ->setEntity($entity)
            ->setSkipInlineAttachmentHandling(true)
            ->setData($data->getAdditionalTemplateData());

        $bodyTemplate = $template->getBody();

        $html = $renderer->renderTemplate($bodyTemplate);

        $html = $this->replaceTags($html);

        //echo $html;die;

        return "<main>{$html}</main>";
    }

    private function replaceTags(string $html): string
    {
        // @todo Convert barcode tags.

        $html = str_replace('<br pagebreak="true">', '<div style="page-break-after: always;"></div>', $html);

        $html = str_replace('?entryPoint=attachment&amp;', '?entryPoint=attachment&', $html);

        $html = preg_replace_callback(
            "/src=\"\?entryPoint=attachment\&id=([A-Za-z0-9]*)\"/",
            function ($matches) {
                $id = $matches[1];

                if (!$id) {
                    return '';
                }

                $src = $this->imageSourceProvider->get($id);

                if (!$src) {
                    return '';
                }

                return "src=\"{$src}\"";
            },
            $html
        );

        return $html;
    }

    private function replaceHeadTags(string $html): string
    {
        $html = str_replace('{pageNumber}', '<span class="page-number"></span>', $html);

        return $this->replaceTags($html);
    }
}
