<?php
namespace DmServer\Test;

class StatsTest extends TestCommon
{

    public function setUp()
    {
        parent::setUp();
        self::createCoaData();
        $collectionUserInfo = self::createTestCollection();
        self::setSessionUser($this->app, $collectionUserInfo);
        self::createStatsData();
    }

    public function testGetWatchedAuthors() {
        $service = $this->buildAuthenticatedServiceWithTestUser('/collection/stats/watchedauthorsstorycount', TestCommon::$dmUser);
        $response = $service->call();

        $objectResponse = json_decode($response->getContent());
        $this->assertInternalType('object', $objectResponse);
        $this->assertEquals('CB', array_keys(get_object_vars($objectResponse))[0]);
        $this->assertEquals('Carl Barks', $objectResponse->CB->fullname);
        $this->assertEquals(3, $objectResponse->CB->storycount);
        $this->assertEquals(2, $objectResponse->CB->missingstorycount);
    }

    public function testGetSuggestions() {
        $service = $this->buildAuthenticatedServiceWithTestUser('/collection/stats/suggestedpublications', TestCommon::$dmUser);
        $response = $service->call();

        $objectResponse = json_decode($response->getContent());
        $this->assertInternalType('object', $objectResponse);

        $this->assertEquals('us/CBL 7', array_keys(get_object_vars($objectResponse))[0]);

        $this->assertEquals('W WDC  31-05', array_keys(get_object_vars($objectResponse->{'us/CBL 7'}))[0]);
        $story1 = $objectResponse->{'us/CBL 7'}->{'W WDC  31-05'};
        $this->assertEquals('CB', $story1->author->personcode);
        $this->assertEquals('Carl Barks', $story1->author->fullname);
        $this->assertEquals('Title of story W WDC  31-05', $story1->story->title);
        $this->assertEquals('Comment of story W WDC  31-05', $story1->story->storycomment);

        $this->assertEquals('W WDC  32-02', array_keys(get_object_vars($objectResponse->{'us/CBL 7'}))[1]);
        $story2 = $objectResponse->{'us/CBL 7'}->{'W WDC  32-02'};
        $this->assertEquals('CB', $story2->author->personcode);
        $this->assertEquals('Carl Barks', $story2->author->fullname);
        $this->assertEquals('Title of story W WDC  32-02', $story2->story->title);
        $this->assertEquals('Comment of story W WDC  32-02', $story2->story->storycomment);
    }
}
