<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\ORM\Indexing;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Doctrine\MultiInsertQueryQueue;
use Shopware\Core\Framework\Event\ProgressAdvancedEvent;
use Shopware\Core\Framework\Event\ProgressFinishedEvent;
use Shopware\Core\Framework\Event\ProgressStartedEvent;
use Shopware\Core\Framework\ORM\Dbal\Common\IndexTableOperator;
use Shopware\Core\Framework\ORM\Dbal\Common\LastIdQuery;
use Shopware\Core\Framework\ORM\Dbal\EntityDefinitionQueryHelper;
use Shopware\Core\Framework\ORM\Dbal\Indexing\IndexerInterface;
use Shopware\Core\Framework\ORM\DefinitionRegistry;
use Shopware\Core\Framework\ORM\EntityCollection;
use Shopware\Core\Framework\ORM\EntityDefinition;
use Shopware\Core\Framework\ORM\EntityRepository;
use Shopware\Core\Framework\ORM\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\ORM\Read\ReadCriteria;
use Shopware\Core\Framework\ORM\RepositoryInterface;
use Shopware\Core\Framework\ORM\Search\Criteria;
use Shopware\Core\Framework\SourceContext;
use Shopware\Core\Framework\Struct\Uuid;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class SearchKeywordIndexer implements IndexerInterface
{
    public const DICTIONARY = 'search_dictionary';

    public const DOCUMENT_TABLE = 'search_document';

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var SearchAnalyzerRegistry
     */
    private $analyzerRegistry;

    /**
     * @var IndexTableOperator
     */
    private $indexTableOperator;

    /**
     * @var RepositoryInterface
     */
    private $languageRepository;

    /**
     * @var RepositoryInterface
     */
    private $catalogRepository;

    /**
     * @var DefinitionRegistry
     */
    private $registry;

    /**
     * @var ContainerInterface
     */
    private $container;

    public function __construct(
        Connection $connection,
        ContainerInterface $container,
        DefinitionRegistry $registry,
        EventDispatcherInterface $eventDispatcher,
        SearchAnalyzerRegistry $analyzerRegistry,
        IndexTableOperator $indexTableOperator,
        RepositoryInterface $languageRepository,
        RepositoryInterface $catalogRepository
    ) {
        $this->connection = $connection;
        $this->eventDispatcher = $eventDispatcher;
        $this->analyzerRegistry = $analyzerRegistry;
        $this->indexTableOperator = $indexTableOperator;
        $this->languageRepository = $languageRepository;
        $this->catalogRepository = $catalogRepository;
        $this->container = $container;
        $this->registry = $registry;
    }

    public function index(\DateTime $timestamp, string $tenantId): void
    {
        $this->indexTableOperator->createTable(self::DICTIONARY, $timestamp);
        $this->indexTableOperator->createTable(self::DOCUMENT_TABLE, $timestamp);

        $table = $this->indexTableOperator->getIndexName(self::DICTIONARY, $timestamp);
        $documentTable = $this->indexTableOperator->getIndexName(self::DOCUMENT_TABLE, $timestamp);

        $this->connection->executeUpdate('ALTER TABLE `' . $table . '` ADD PRIMARY KEY `language_keyword` (`keyword`, `scope`, `language_id`, `version_id`, `tenant_id`, `language_tenant_id`);');
        $this->connection->executeUpdate('ALTER TABLE `' . $table . '` ADD INDEX `keyword` (`keyword`, `language_id`, `language_tenant_id`, `tenant_id`);');
        $this->connection->executeUpdate('ALTER TABLE `' . $table . '` ADD FOREIGN KEY (`language_id`, `language_tenant_id`) REFERENCES `language` (`id`, `tenant_id`) ON DELETE CASCADE ON UPDATE CASCADE');

        $this->connection->executeUpdate('ALTER TABLE `' . $documentTable . '` ADD PRIMARY KEY (`id`, `version_id`, `tenant_id`);');
        $this->connection->executeUpdate('ALTER TABLE `' . $documentTable . '` ADD UNIQUE  KEY (`language_id`, `keyword`, `entity`, `entity_id`, `ranking`, `version_id`, `tenant_id`);');
        $this->connection->executeUpdate('ALTER TABLE `' . $documentTable . '` ADD FOREIGN KEY (`language_id`, `language_tenant_id`) REFERENCES `language` (`id`, `tenant_id`) ON DELETE CASCADE ON UPDATE CASCADE');

        $languages = $this->languageRepository->search(new Criteria(), Context::createDefaultContext($tenantId));
        $catalogIds = $this->catalogRepository->searchIds(new Criteria(), Context::createDefaultContext($tenantId));

        $sourceContext = new SourceContext();
        $sourceContext->setTouchpointId(Defaults::TOUCHPOINT);

        foreach ($languages as $language) {
            $context = new Context(
                $tenantId,
                $sourceContext,
                $catalogIds->getIds(),
                [],
                Defaults::CURRENCY,
                $language->getId(),
                $language->getParentId(),
                Defaults::LIVE_VERSION
            );

            $this->indexContext($context, $timestamp);
        }

        $this->connection->transactional(function () use ($table, $documentTable, $tenantId) {
            $tenantId = Uuid::fromStringToBytes($tenantId);

            $this->connection->executeUpdate('DELETE FROM ' . self::DOCUMENT_TABLE . ' WHERE tenant_id = :tenant', ['tenant' => $tenantId]);
            $this->connection->executeUpdate('DELETE FROM ' . self::DICTIONARY . ' WHERE tenant_id = :tenant', ['tenant' => $tenantId]);

            $this->connection->executeUpdate('REPLACE INTO ' . self::DOCUMENT_TABLE . ' SELECT * FROM ' . $documentTable);
            $this->connection->executeUpdate('REPLACE INTO ' . self::DICTIONARY . ' SELECT * FROM ' . $table);

            $this->connection->executeUpdate('DROP TABLE ' . $table);
            $this->connection->executeUpdate('DROP TABLE ' . $documentTable);
        });
    }

    public function refresh(EntityWrittenContainerEvent $event): void
    {
//        $productEvent = $event->getEventByDefinition(ProductDefinition::class);
//        if (!$productEvent) {
//            return;
//        }
//
//        $context = $productEvent->getContext();
//        $products = $this->productRepository->read(new ReadCriteria($productEvent->getIds()), $context);
//
//        $queue = new MultiInsertQueryQueue($this->connection, 250, false, true);
//        foreach ($products as $product) {
//            $keywords = $this->analyzerRegistry->analyze($product, $context);
//            $this->updateQueryQueue($queue, $context, $product->getId(), $keywords, self::TABLE, self::DOCUMENT_TABLE);
//        }
//        $queue->execute();
    }

    public static function stringReverse($keyword)
    {
        $keyword = (string) $keyword;
        $peaces = preg_split('//u', $keyword, -1, PREG_SPLIT_NO_EMPTY);
        $peaces = array_reverse($peaces);

        return implode('', $peaces);
    }

    private function indexContext(Context $context, \DateTime $timestamp): void
    {
        foreach ($this->registry->getElements() as $definition) {
            /** @var string|EntityDefinition $definition */
            if (!$definition::useKeywordSearch()) {
                continue;
            }

            /** @var EntityRepository $repository */
            $repository = $this->container->get($definition::getEntityName() . '.repository');

            $iterator = $this->createIterator($definition, $context->getTenantId());

            $this->eventDispatcher->dispatch(
                ProgressStartedEvent::NAME,
                new ProgressStartedEvent(
                    sprintf('Start analyzing search keywords for entity %s in language %s', $definition::getEntityName(), $context->getLanguageId()),
                    $iterator->fetchCount()
                )
            );

            $table = $this->indexTableOperator->getIndexName(self::DICTIONARY, $timestamp);
            $documentTable = $this->indexTableOperator->getIndexName(self::DOCUMENT_TABLE, $timestamp);

            while ($ids = $iterator->fetch()) {
                $ids = array_map(function ($id) {
                    return Uuid::fromBytesToHex($id);
                }, $ids);

                $entities = $repository->read(new ReadCriteria($ids), $context);

                $this->indexEntities($definition, $context, $entities, $table, $documentTable);

                $this->eventDispatcher->dispatch(
                    ProgressAdvancedEvent::NAME,
                    new ProgressAdvancedEvent($entities->count())
                );
            }

            $this->eventDispatcher->dispatch(
                ProgressFinishedEvent::NAME,
                new ProgressFinishedEvent(sprintf('Finished analyzing search keywords for entity %s for language %s', $definition::getEntityName(), $context->getLanguageId()))
            );
        }
    }

    private function createIterator(string $definition, string $tenantId): LastIdQuery
    {
        $query = $this->connection->createQueryBuilder();

        /** @var string|EntityDefinition $definition */
        $escaped = EntityDefinitionQueryHelper::escape($definition::getEntityName());

        $query->select([$escaped . '.auto_increment', $escaped . '.id']);
        $query->from($escaped);
        $query->andWhere($escaped . '.tenant_id = :tenantId');
        $query->andWhere($escaped . '.auto_increment > :lastId');
        $query->addOrderBy($escaped . '.auto_increment');

        $query->setMaxResults(50);

        $query->setParameter('tenantId', Uuid::fromHexToBytes($tenantId));
        $query->setParameter('lastId', 0);

        return new LastIdQuery($query);
    }

    private function indexEntities(string $definition, Context $context, EntityCollection $entities, string $table, string $documentTable): void
    {
        $queue = new MultiInsertQueryQueue($this->connection, 250, false, true);

        $languageId = $this->connection->quote(Uuid::fromStringToBytes($context->getLanguageId()));
        $versionId = $this->connection->quote(Uuid::fromStringToBytes($context->getVersionId()));
        $tenantId = $this->connection->quote(Uuid::fromStringToBytes($context->getTenantId()));

        /** @var string|EntityDefinition $definition */
        $entityName = $this->connection->quote($definition::getEntityName());

        foreach ($entities as $entity) {
            $keywords = $this->analyzerRegistry->analyze($definition, $entity, $context);

            $entityId = $this->connection->quote(Uuid::fromStringToBytes($entity->getId()));

            foreach ($keywords as $keyword => $ranking) {
                $reversed = static::stringReverse($keyword);

                $keyword = $this->connection->quote($keyword);
                $reversed = $this->connection->quote($reversed);
                $ranking = $this->connection->quote($ranking);

                $queue->addInsert($table, [
                    'scope' => $entityName,
                    'tenant_id' => $tenantId,
                    'language_id' => $languageId,
                    'language_tenant_id' => $tenantId,
                    'version_id' => $versionId,
                    'keyword' => $keyword,
                    'reversed' => $reversed,
                ], null, true);

                $queue->addInsert($documentTable, [
                    'id' => $this->connection->quote(Uuid::uuid4()->getBytes()),
                    'tenant_id' => $tenantId,
                    'version_id' => $versionId,
                    'entity' => $entityName,
                    'entity_id' => $entityId,
                    'language_id' => $languageId,
                    'language_tenant_id' => $tenantId,
                    'keyword' => $keyword,
                    'ranking' => $ranking,
                ], null, true);
            }
        }

        $queue->execute();
    }
}
