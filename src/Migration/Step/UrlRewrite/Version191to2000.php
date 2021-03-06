<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Migration\Step\UrlRewrite;

use Migration\App\ProgressBar;
use Migration\App\Step\RollbackInterface;
use Migration\Resource\Destination;
use Migration\Resource\Document;
use Migration\Resource\Record;
use Migration\Resource\RecordFactory;
use Migration\Resource\Source;
use Migration\Reader\MapInterface;

/**
 * Class Version191to2000
 */
class Version191to2000 extends \Migration\Step\DatabaseStage implements RollbackInterface
{
    const SOURCE = 'core_url_rewrite';

    const DESTINATION = 'url_rewrite';
    const DESTINATION_PRODUCT_CATEGORY = 'catalog_url_rewrite_product_category';

    /**
     * @var Source
     */
    protected $source;

    /**
     * @var Destination
     */
    protected $destination;

    /**
     * @var ProgressBar\LogLevelProcessor
     */
    protected $progress;

    /**
     * @var RecordFactory
     */
    protected $recordFactory;

    /**
     * @var string
     */
    protected $stage;

    /**
     * @var array
     */
    protected $redirectTypesMapping = [
        '' => 0,
        'R' => 302,
        'RP' => 301
    ];

    /**
     * Expected table structure
     * @var array
     */
    protected $structure = [
        MapInterface::TYPE_SOURCE => [
            'core_url_rewrite' => [
                'url_rewrite_id' ,
                'store_id',
                'id_path',
                'request_path',
                'target_path',
                'is_system',
                'options',
                'description',
                'category_id',
                'product_id',
            ],
        ],
        MapInterface::TYPE_DEST => [
            'url_rewrite' => [
                'url_rewrite_id',
                'entity_type',
                'entity_id',
                'request_path',
                'target_path',
                'redirect_type',
                'store_id',
                'description',
                'is_autogenerated',
                'metadata'
            ],
        ]
    ];

    /**
     * @param \Migration\Config $config
     * @param Source $source
     * @param Destination $destination
     * @param ProgressBar\LogLevelProcessor $progress
     * @param RecordFactory $factory
     * @param string $stage
     * @throws \Migration\Exception
     */
    public function __construct(
        \Migration\Config $config,
        Source $source,
        Destination $destination,
        ProgressBar\LogLevelProcessor $progress,
        RecordFactory $factory,
        $stage
    ) {
        parent::__construct($config);
        $this->source = $source;
        $this->destination = $destination;
        $this->progress = $progress;
        $this->recordFactory = $factory;
        $this->stage = $stage;
    }

    /**
     * Integrity check
     *
     * @return bool
     */
    protected function integrity()
    {
        $result = true;
        $this->progress->start(1);
        $result &= array_keys($this->source->getStructure(self::SOURCE)->getFields())
            == $this->structure[MapInterface::TYPE_SOURCE][self::SOURCE];
        $result &= array_keys($this->destination->getStructure(self::DESTINATION)->getFields())
            == $this->structure[MapInterface::TYPE_DEST][self::DESTINATION];
        $this->progress->advance();
        $this->progress->finish();
        return (bool)$result;
    }

    /**
     * Run step
     *
     * @return bool
     */
    protected function data()
    {
        $this->progress->start($this->source->getRecordsCount(self::SOURCE));

        $sourceDocument = $this->source->getDocument(self::SOURCE);
        $destDocument = $this->destination->getDocument(self::DESTINATION);
        $destProductCategory = $this->destination->getDocument(self::DESTINATION_PRODUCT_CATEGORY);

        $this->destination->clearDocument(self::DESTINATION);
        $this->destination->clearDocument(self::DESTINATION_PRODUCT_CATEGORY);

        $pageNumber = 0;
        while (!empty($bulk = $this->source->getRecords(self::SOURCE, $pageNumber))) {
            $pageNumber++;
            $destinationRecords = $destDocument->getRecords();
            $destProductCategoryRecords = $destProductCategory->getRecords();
            foreach ($bulk as $recordData) {
                $this->progress->advance();
                /** @var Record $record */
                $record = $this->recordFactory->create(['document' => $sourceDocument, 'data' => $recordData]);
                /** @var Record $destRecord */
                $destRecord = $this->recordFactory->create(['document' => $destDocument]);
                $this->transform($record, $destRecord);
                if ($record->getValue('is_system')
                    && $record->getValue('product_id')
                    && $record->getValue('category_id')
                ) {
                    $destProductCategoryRecord = $this->recordFactory->create(['document' => $destProductCategory]);
                    $destProductCategoryRecord->setValue('url_rewrite_id', $record->getValue('url_rewrite_id'));
                    $destProductCategoryRecord->setValue('category_id', $record->getValue('category_id'));
                    $destProductCategoryRecord->setValue('product_id', $record->getValue('product_id'));
                    $destProductCategoryRecords->addRecord($destProductCategoryRecord);
                }

                $destinationRecords->addRecord($destRecord);
            }
            $this->destination->saveRecords(self::DESTINATION, $destinationRecords);
            $this->destination->saveRecords(self::DESTINATION_PRODUCT_CATEGORY, $destProductCategoryRecords);

        }
        $this->progress->finish();
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function perform()
    {
        if (!method_exists($this, $this->stage)) {
            throw new \Exception('Invalid step configuration');
        }

        return call_user_func([$this, $this->stage]);
    }

    /**
     * Volume check
     *
     * @return bool
     */
    protected function volume()
    {
        $result = true;
        $this->progress->start(1);
        $result &= $this->source->getRecordsCount(self::SOURCE) ==
            $this->destination->getRecordsCount(self::DESTINATION);
        $this->progress->advance();
        $this->progress->finish();
        return (bool)$result;
    }

    /**
     * @inheritdoc
     */
    public function rollback()
    {
        return true;
    }

    /**
     * Record transformer
     *
     * @param Record $record
     * @param Record $destRecord
     * @return void
     */
    private function transform(Record $record, Record $destRecord)
    {
        $destRecord->setValue('url_rewrite_id', $record->getValue('url_rewrite_id'));
        $destRecord->setValue('store_id', $record->getValue('store_id'));
        $destRecord->setValue('description', $record->getValue('description'));

        $destRecord->setValue('request_path', $record->getValue('request_path'));
        $destRecord->setValue('target_path', $record->getValue('target_path'));
        $destRecord->setValue('is_autogenerated', $record->getValue('is_system'));

        $destRecord->setValue('entity_type', $this->getRecordEntityType($record));

        $metadata = $this->doRecordSerialization($record)
            ? serialize(['category_id' => $record->getValue('category_id')])
            : null ;
        $destRecord->setValue('metadata', $metadata);

        $destRecord->setValue('entity_id', $record->getValue('product_id') ?: $record->getValue('category_id'));
        $destRecord->setValue('redirect_type', $this->redirectTypesMapping[$record->getValue('options')]);
    }

    /**
     * @param Record $record
     * @return bool
     */
    private function doRecordSerialization(Record $record)
    {
        return $record->getValue('is_system') && $record->getValue('product_id') && $record->getValue('category_id');
    }

    /**
     * @param Record $record
     * @return mixed
     */
    public function getRecordEntityType(Record $record)
    {
        $isCategory = $record->getValue('category_id') ? 'category' : null;
        $isProduct = $record->getValue('product_id') ? 'product' : null;
        return $isProduct ?: $isCategory;
    }
}
