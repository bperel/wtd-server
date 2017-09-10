<?php
namespace DmServer\Test;

use DmServer\DmServer;
use DmServer\SimilarImagesHelper;

class StatusTest extends TestCommon
{
    public function testGetStatus() {
        $collectionUserInfo = self::createTestCollection();
        self::setSessionUser($this->app, $collectionUserInfo);
        self::createCoverIds();
        self::createCoaData();
        $this->createStatsData();
        $this->createEdgeCreatorData();

        SimilarImagesHelper::$mockedResults = json_encode(['image_ids' => [1,2,3], 'type' => 'INDEX_IMAGE_IDS']);

        $response = $this->buildAuthenticatedService('/status/pastec', TestCommon::$dmUser, [], [], 'GET')->call();

        $this->assertEquals('Pastec OK with 3 images indexed', $response->getContent());
    }

    public function testGetStatusNoCoverData() {
        $collectionUserInfo = self::createTestCollection();
        self::setSessionUser($this->app, $collectionUserInfo);
        self::createCoverIds();
        self::createCoaData();
        $this->createStatsData();
        $this->createEdgeCreatorData();

        SimilarImagesHelper::$mockedResults = json_encode(['image_ids' => [], 'type' => 'INDEX_IMAGE_IDS']);

        $response = $this->buildAuthenticatedService('/status/pastec', TestCommon::$dmUser, [], [], 'GET')->call();

        $this->assertEquals('<b>Pastec has no images indexed</b>', $response->getContent());
    }

    public function testGetStatusMissingCoaData() {
        $collectionUserInfo = self::createTestCollection();
        self::setSessionUser($this->app, $collectionUserInfo);
        self::createCoverIds();
        $this->createStatsData();

        $response = $this->buildAuthenticatedService('/status/db', TestCommon::$dmUser, [], [], 'GET')->call();

        $this->assertContains('Error for db_coa', $response->getContent());
    }

    public function testGetStatusDBDown() {
        unset(DmServer::$entityManagers[DmServer::CONFIG_DB_KEY_COA]);

        try {
            $response = $this->buildAuthenticatedService('/status/db', TestCommon::$dmUser, [], [], 'GET')->call();
            $this->assertContains('Error for db_coa : JSON cannot be decoded', $response->getContent());
        }
        finally {
            self::recreateSchema(DmServer::CONFIG_DB_KEY_COA);
        }
    }
}
