<?php
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

namespace Espo\Tools\Stream;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Collection as RecordCollection;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\ORM\Collection;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Part\Order;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectBuilder;
use Espo\Tools\Stream\RecordService\Helper;
use RuntimeException;

class GlobalRecordService
{
    public const SCOPE_NAME = 'GlobalStream';

    public function __construct(
        private Acl $acl,
        private User $user,
        private Metadata $metadata,
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
        private Helper $helper,
        private NoteAccessControl $noteAccessControl
    ) {}

    /**
     * @return RecordCollection<Note>
     * @throws Forbidden
     * @throws BadRequest
     */
    public function find(SearchParams $searchParams): RecordCollection
    {
        if (!$this->acl->checkScope(self::SCOPE_NAME)) {
            throw new Forbidden();
        }

        if ($searchParams->getOffset()) {
            throw new BadRequest("Offset is not supported.");
        }

        $maxSize = $searchParams->getMaxSize();

        $entityTypeList = $this->getEntityTypeList();

        $entityTypeList = ['Meeting'];

        $baseBuilder = $this->helper->buildBaseQueryBuilder($searchParams)
            ->select($this->helper->getUserQuerySelect())
            ->order('number', Order::DESC)
            ->limit(0, $maxSize + 1);

        $queryList = [];

        foreach ($entityTypeList as $entityType) {
            $queryList[] = $this->buildForEntityType($entityType, $baseBuilder);
        }

        /** @var Note[] $list */
        $list = [];

        foreach ($queryList as $query) {
            $subCollection = $this->entityManager
                ->getRDBRepositoryByClass(Note::class)
                ->clone($query)
                ->sth()
                ->find();

            //echo $this->entityManager->getQueryComposer()->compose($query);die;

            foreach ($subCollection as $note) {
                $list[] = $note;
            }
        }

        usort($list, fn (Note $note1, Note $note2) => $note2->get('number') - $note1->get('number'));

        $list = array_slice($list, 0, $maxSize + 1);

        /** @var Collection<Note> $collection */
        $collection = $this->entityManager->getCollectionFactory()->create(null, $list);

        foreach ($collection as $note) {
            $note->loadAdditionalFields();
            $this->noteAccessControl->apply($note, $this->user);
        }

        return RecordCollection::createNoCount($collection, $maxSize);
    }

    /**
     * @return string[]
     */
    private function getEntityTypeList(): array
    {
        $list = [];

        /** @var array<string, array<string, mixed>> $scopes */
        $scopes = $this->metadata->get('scopes');

        foreach ($scopes as $scope => $item) {
            if (
                !($item['entity'] ?? false) ||
                !($item['stream'] ?? false)
            ) {
                continue;
            }

            if (
                !$this->acl->checkScope($scope, Acl\Table::ACTION_READ) ||
                !$this->acl->checkScope($scope, Acl\Table::ACTION_STREAM)
            ) {
                continue;
            }

            $list[] = $scope;
        }

        return $list;
    }

    /**
     * Applies access filtering according 'read' level as filtering by 'stream' level is not implemented
     * in the system.
     */
    private function buildForEntityType(string $entityType, SelectBuilder $baseBuilder): Select
    {
        try {
            $subQuery = $this->selectBuilderFactory
                ->create()
                ->from($entityType)
                ->withAccessControlFilter()
                ->buildQueryBuilder()
                ->select(['id'])
                ->build();
        }
        catch (BadRequest|Forbidden) {
            throw new RuntimeException();
        }

        $builder = (clone $baseBuilder)
            ->where(
                Cond::and(
                    Cond::equal(
                        Expr::column('parentType'),
                        $entityType
                    ),
                    Cond::in(
                        Expr::column('parentId'),
                        $subQuery
                    )
                )
            );

        return $builder->build();
    }
}
