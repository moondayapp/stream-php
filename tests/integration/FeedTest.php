<?php

namespace GetStream\Integration;

use DateTime;
use DateTimeZone;
use GetStream\Stream\Client;
use GetStream\Stream\Feed;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

class FeedTest extends TestCase
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var Feed
     */
    protected $user1;

    /**
     * @var Feed
     */
    protected $aggregated2;

    /**
     * @var Feed
     */
    protected $aggregated3;

    /**
     * @var Feed
     */
    protected $flat3;

    protected function setUp()
    {
        $this->client = new Client(
            getenv('STREAM_API_KEY'),
            getenv('STREAM_API_SECRET'),
            'v1.0',
            getenv('STREAM_REGION')
        );
        $this->client->setLocation('qa');
        $this->client->timeout = 10000;
        $this->user1 = $this->client->feed('user', Uuid::uuid4());
        $this->aggregated2 = $this->client->feed('aggregated', Uuid::uuid4());
        $this->aggregated3 = $this->client->feed('aggregated', Uuid::uuid4());
        $this->flat3 = $this->client->feed('flat', Uuid::uuid4());
    }

    public function testRedirectUrl()
    {
        $targetUrl = 'http://google.com/?a=1';
        $impression = [
          'content_list' => ['tweet:34349698', 'tweet:34349699', 'tweet:34349697'],
          'feed_id'      => 'flat:tommaso',
          'location'     => 'profile_page',
          'user_data'    => ['id' => 'bubbles'],
          'label'        => 'impression'
        ];
        $engagement = [
          'content'      => 'tweet:34349698',
          'feed_id'      => 'flat:tommaso',
          'location'     => 'profile_page',
          'user_data'    => ['id' => 'frank'],
          'label'        => 'click'
        ];
        $events = [$impression, $engagement];
        $finalUrl = $this->client->createRedirectUrl($targetUrl, $events);
    }

    public function testUpdateActivity()
    {
        $now = new DateTime('now', new DateTimeZone('Pacific/Nauru'));
        $activity = [
            'actor' => 1,
            'verb' => 'tweet',
            'object' => 1,
            'time' => $now->format(DateTime::ISO8601),
            'foreign_id' => 'batch1',
            'popularity' => 100
        ];

        $this->client->updateActivity($activity);
        $activity['popularity'] = 10;
        $this->client->updateActivity($activity);
    }

    public function testAddToMany()
    {
        $id = Uuid::uuid4();

        $batcher = $this->client->batcher();
        $activityData = [
            'actor' => 1,
            'verb' => 'tweet',
            'object' => 1,
            'foreign_id' => 'batch1'
        ];
        $feeds = ['flat:'.$id, 'user:'.$id];

        $batcher->addToMany($activityData, $feeds);
        $b1 = $this->client->feed('flat', $id);
        $response = $b1->getActivities();
        $this->assertSame('batch1', $response['results'][0]['foreign_id']);
    }

    public function testFollowManyWithActivityCopyLimitZero()
    {
        $id1 = Uuid::uuid4();
        $id2 = Uuid::uuid4();

        $activity_data = ['actor' => 1, 'verb' => 'tweet', 'object' => 1];
        $response = $this->client->feed('user', $id1)->addActivity($activity_data);
        $batcher = $this->client->batcher();
        $follows = [
            ['source' => 'flat:'.$id1, 'target' => 'user:'.$id1],
            ['source' => 'flat:'.$id1, 'target' => 'user:'.$id2]
        ];
        $batcher->followMany($follows, 0);
        sleep(5);
        $b11 = $this->client->feed('flat', $id1);
        $response = $b11->following();
        $this->assertCount(2, $response['results']);
    }

    public function testFollowMany()
    {
        $id1 = Uuid::uuid4();
        $id2 = Uuid::uuid4();

        $batcher = $this->client->batcher();
        $follows = [
            ['source' => 'flat:'.$id1, 'target' => 'user:'.$id1],
            ['source' => 'flat:'.$id1, 'target' => 'user:'.$id2]
        ];
        $batcher->followMany($follows);

        $b1 = $this->client->feed('flat', $id1);
        $response = $b1->following();
        $this->assertCount(2, $response['results']);
    }

    public function testUnfollowMany()
    {
        $u1 = $this->client->feed('user', Uuid::uuid4());
        $u2 = $this->client->feed('user', Uuid::uuid4());
        $f1 = $this->client->feed('flat', Uuid::uuid4());
        $f2 = $this->client->feed('flat', Uuid::uuid4());

        $batcher = $this->client->batcher();
        $follows = [
            ['source' => $f1->getId(), 'target' => $u1->getId()],
            ['source' => $f2->getId(), 'target' => $u2->getId()]
        ];
        $batcher->followMany($follows);

        $activity = ['actor' => 'bob', 'verb' => 'does', 'object' => 'something'];
        $u1->addActivity($activity);
        $u2->addActivity($activity);

        $this->assertCount(1, $f1->getActivities()['results']);
        $this->assertCount(1, $f2->getActivities()['results']);

        $unfollows = [
            ['source' => $f1->getId(), 'target' => $u1->getId()],
            ['source' => $f2->getId(), 'target' => $u2->getId(), 'keep_history' => true]
        ];
        $batcher->unfollowMany($unfollows);
        
        $resp = $f1->following();
        $this->assertCount(0, $resp['results']);
        $resp = $f2->following();
        $this->assertCount(0, $resp['results']);

        $this->assertCount(0, $f1->getActivities()['results']);
        $this->assertCount(1, $f2->getActivities()['results']);
        
    }

    public function testReadonlyToken()
    {
        $token = $this->user1->getReadonlyToken();
        $this->assertStringStartsWith('eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJhY3Rpb24iOiJyZWFkI', $token);
    }

    public function testAddActivity()
    {
        $activity_data = ['actor' => 1, 'verb' => 'tweet', 'object' => 1];
        $response = $this->user1->addActivity($activity_data);
        $activity_id = $response['id'];
        $activities = $this->user1->getActivities(0, 1)['results'];
        $this->assertCount(1, $activities);
        $this->assertSame($activity_id, $activities[0]['id']);
    }

    public function testAddActivities()
    {
        $activities = [
            ['actor' => 'multi1', 'verb' => 'tweet', 'object' => 1],
            ['actor' => 'multi2', 'verb' => 'tweet', 'object' => 2],
        ];
        $response = $this->user1->addActivities($activities);
        $activities = $this->user1->getActivities(0, 2)['results'];
        $this->assertCount(2, $activities);
        $actors = [$activities[0]['actor'], $activities[1]['actor']];
        $expected = ['multi1', 'multi2'];
        sort($expected);
        sort($actors);
        $this->assertSame($expected, $actors);
    }

    public function testAddActivityWithTime()
    {
        $now = new DateTime('now', new DateTimeZone('Pacific/Nauru'));
        $time = $now->format(DateTime::ISO8601);
        $activity_data = ['actor' => 1, 'verb' => 'tweet', 'object' => 1, 'time' => $time];
        $response = $this->user1->addActivity($activity_data);
        $activities = $this->user1->getActivities(0, 1)['results'];
        $this->assertCount(1, $activities);
        $utc_time = new DateTime($activities[0]['time'], new DateTimeZone('UTC'));
        $this->assertSame($now->format('U'), $utc_time->format('U'));
    }

    public function testAddActivityWithArray()
    {
        $complex = ['tommaso', 'thierry'];
        $activity_data = ['actor' => 1, 'verb' => 'tweet', 'object' => 1, 'complex' => $complex];
        $response = $this->user1->addActivity($activity_data);
        $activity_id = $response['id'];
        $activities = $this->user1->getActivities(0, 1)['results'];
        $this->assertCount(1, $activities);
        $this->assertSame($activity_id, $activities[0]['id']);
        sort($activities[0]['complex']);
        sort($complex);
        $this->assertSame($complex, $activities[0]['complex']);
    }

    public function testAddActivityWithAssocArray()
    {
        $complex = ['author' => 'tommaso', 'bcc' => 'thierry'];
        $activity_data = ['actor' => 1, 'verb' => 'tweet', 'object' => 1, 'complex' => $complex];
        $response = $this->user1->addActivity($activity_data);
        $activity_id = $response['id'];
        $activities = $this->user1->getActivities(0, 1)['results'];
        $this->assertCount(1, $activities);
        $this->assertSame($activity_id, $activities[0]['id']);
        sort($activities[0]['complex']);
        sort($complex);
        $this->assertSame($complex, $activities[0]['complex']);
    }

    public function testRemoveActivity()
    {
        $activity_data = ['actor' => 1, 'verb' => 'tweet', 'object' => 1];
        $response = $this->user1->addActivity($activity_data);
        $activity_id = $response['id'];
        $activities = $this->user1->getActivities(0, 1)['results'];
        $this->assertCount(1, $activities);
        $this->assertSame($activity_id, $activities[0]['id']);
        $this->user1->removeActivity($activity_id);
        sleep(2);
        $activities = $this->user1->getActivities(0, 1)['results'];
        $this->assertCount(0, $activities);
    }

    public function testRemoveActivityByForeignId()
    {
        $fid = 'post:42';
        $activity_data = ['actor' => 1, 'verb' => 'tweet', 'object' => 1, 'foreign_id' => $fid];
        $response = $this->user1->addActivity($activity_data);
        $activity_id = $response['id'];
        $activities = $this->user1->getActivities(0, 1)['results'];
        $this->assertCount(1, $activities);
        $this->assertSame($activities[0]['id'], $activity_id);
        $this->assertSame($activities[0]['foreign_id'], $fid);
        $this->user1->removeActivity($fid, true);
        sleep(2);
        $activities = $this->user1->getActivities(0, 1)['results'];
        $this->assertCount(0, $activities);
    }

    public function testException()
    {
        $activity_data = ['actor' => 1, 'verb' => 'tweet', 'object' => 1, 'new_field' => '42'];
        $response = $this->user1->addActivity($activity_data);
        $activities = $this->user1->getActivities(0, 1)['results'];
        $this->assertNotSame(42, $activities[0]['new_field']);
    }

    public function testFlatFollowUnfollow()
    {
        $activity_data = ['actor' => 1, 'verb' => 'FlatFollowUnfollow', 'object' => 1];
        $response = $this->flat3->addActivity($activity_data);
        $activity_id = $response['id'];
        $this->user1->follow('flat', $this->flat3->getUserId());
        sleep(2);
        $activities = $this->user1->getActivities()['results'];
        $this->assertCount(1, $activities);
        $this->assertSame($activity_id, $activities[0]['id']);
        $this->user1->unfollow('flat', $this->flat3->getUserId());
        sleep(2);
        $activities = $this->user1->getActivities()['results'];
        $this->assertCount(0, $activities);
    }

    public function testFlatFollowUnfollowKeepHistory()
    {
        $id = Uuid::uuid4();
        $now = new DateTime('now', new DateTimeZone('Pacific/Nauru'));
        $activity = [
            'actor' => 1,
            'verb' => 'tweet',
            'object' => 1,
            'time' => $now->format(DateTime::ISO8601),
        ];
        $feed = $this->client->feed('user', 'keephistory');
        $feed->follow($this->flat3->getSlug(), $this->flat3->getUserID());
        $this->flat3->addActivity($activity);
        $activities = $feed->getActivities(0, 1)['results'];
        $this->assertCount(1, $activities);
        $feed->unfollow('flat', $id, true);
        sleep(2);
        $activities = $feed->getActivities(0, 1)['results'];
        $this->assertCount(1, $activities);
    }

    public function testFlatFollowUnfollowPrivate()
    {
        $id = Uuid::uuid4();

        $secret = $this->client->feed('secret', $id);
        $this->user1->unfollow('secret', $id);
        $activity_data = ['actor' => 1, 'verb' => 'tweet', 'object' => 1];
        $response = $secret->addActivity($activity_data);
        $activity_id = $response['id'];
        $this->user1->follow('secret', $id);
        sleep(2);
        $activities = $this->user1->getActivities(0, 1)['results'];
        $this->assertCount(1, $activities);
        $this->assertSame($activity_id, $activities[0]['id']);
        $this->user1->unfollow('secret', $id);
    }

    public function testGet()
    {
        $activity_data = ['actor' => 1, 'verb' => 'tweet', 'object' => 1];
        $first_id = $this->user1->addActivity($activity_data)['id'];

        $activity_data = ['actor' => 1, 'verb' => 'tweet', 'object' => 2];
        $second_id = $this->user1->addActivity($activity_data)['id'];

        $activity_data = ['actor' => 1, 'verb' => 'tweet', 'object' => 3];
        $third_id = $this->user1->addActivity($activity_data)['id'];

        $activities = $this->user1->getActivities(0, 2)['results'];
        $this->assertCount(2, $activities);
        $this->assertSame($third_id, $activities[0]['id']);
        $this->assertSame($second_id, $activities[1]['id']);

        $activities = $this->user1->getActivities(1, 2)['results'];
        $this->assertSame($second_id, $activities[0]['id']);

        $id_offset =  ['id_lt' => $third_id];
        $activities = $this->user1->getActivities(0, 2, $id_offset)['results'];
        $this->assertSame($second_id, $activities[0]['id']);
    }

    public function testVerifyOff()
    {
        $this->user1->setGuzzleDefaultOption('verify', true);
        $activities = $this->user1->getActivities(0, 2);
    }

    public function testMarkRead()
    {
        $notification_feed = $this->client->feed('notification', Uuid::uuid4());
        $activity_data = ['actor' => 1, 'verb' => 'tweet', 'object' => 1];
        $notification_feed->addActivity($activity_data);

        $activity_data = ['actor' => 2, 'verb' => 'run', 'object' => 2];
        $notification_feed->addActivity($activity_data);

        $activity_data = ['actor' => 3, 'verb' => 'share', 'object' => 3];
        $notification_feed->addActivity($activity_data);

        $options = ['mark_read' => true];
        $activities = $notification_feed->getActivities(0, 2, $options)['results'];
        $this->assertCount(2, $activities);
        $this->assertFalse($activities[0]['is_read']);
        $this->assertFalse($activities[1]['is_read']);

        $activities = $notification_feed->getActivities(0, 2)['results'];
        $this->assertCount(2, $activities);
        $this->assertTrue($activities[0]['is_read']);
        $this->assertTrue($activities[1]['is_read']);
    }

    public function testMarkReadByIds()
    {
        $notification_feed = $this->client->feed('notification', Uuid::uuid4());
        $activity_data = ['actor' => 1, 'verb' => 'tweet', 'object' => 1];
        $notification_feed->addActivity($activity_data);

        $activity_data = ['actor' => 2, 'verb' => 'run', 'object' => 2];
        $notification_feed->addActivity($activity_data);

        $activity_data = ['actor' => 3, 'verb' => 'share', 'object' => 3];
        $notification_feed->addActivity($activity_data);

        $options = ['mark_read' => []];
        $activities = $notification_feed->getActivities(0, 2)['results'];
        foreach ($activities as $activity) {
            $options['mark_read'][] = $activity['id'];
        }
        $this->assertFalse($activities[0]['is_read']);
        $this->assertFalse($activities[1]['is_read']);
        $notification_feed->getActivities(0, 3, $options);

        $activities = $notification_feed->getActivities(0, 3, $options)['results'];
        $this->assertTrue($activities[0]['is_read']);
        $this->assertTrue($activities[1]['is_read']);
        $this->assertFalse($activities[2]['is_read']);
    }

    public function testFollowersEmpty()
    {
        $lonely = $this->client->feed('flat', Uuid::uuid4());
        $response = $lonely->followers();
        $this->assertCount(0, $response['results']);
        $this->assertSame([], $response['results']);
    }

    public function testFollowersWithLimit()
    {
        $id1 = Uuid::uuid4();
        $id2 = Uuid::uuid4();
        $id3 = Uuid::uuid4();

        $this->client->feed('flat', $id2)->follow('flat', $id1);
        $this->client->feed('flat', $id3)->follow('flat', $id1);
        $response = $this->client->feed('flat', $id1)->followers(0, 2);
        $this->assertCount(2, $response['results']);
        $this->assertSame('flat:'.$id3, $response['results'][0]['feed_id']);
        $this->assertSame('flat:'.$id1, $response['results'][0]['target_id']);
    }

    public function testFollowingEmpty()
    {
        $lonely = $this->client->feed('flat', Uuid::uuid4());
        $response = $lonely->following();
        $this->assertCount(0, $response['results']);
        $this->assertSame([], $response['results']);
    }

    public function testFollowingsWithLimit()
    {
        $id1 = Uuid::uuid4();
        $id2 = Uuid::uuid4();
        $id3 = Uuid::uuid4();

        $this->client->feed('flat', $id2)->follow('flat', $id1);
        $this->client->feed('flat', $id2)->follow('flat', $id3);
        $response = $this->client->feed('flat', $id2)->following(0, 2);
        $this->assertCount(2, $response['results']);
        $this->assertSame('flat:'.$id2, $response['results'][0]['feed_id']);
        $this->assertSame('flat:'.$id3, $response['results'][0]['target_id']);
    }

    public function testDoIFollowEmpty()
    {
        $lonely = $this->client->feed('flat', Uuid::uuid4());
        $response = $lonely->following(0, 10, ['flat:asocial']);
        $this->assertCount(0, $response['results']);
        $this->assertSame([], $response['results']);
    }

    public function testDoIFollow()
    {
        $id1 = Uuid::uuid4();
        $id2 = Uuid::uuid4();
        $id3 = Uuid::uuid4();

        $this->client->feed('flat', $id2)->follow('flat', $id1);
        $this->client->feed('flat', $id2)->follow('flat', $id3);
        $response = $this->client->feed('flat', $id2)->following(0, 10, ['flat:'.$id1]);
        $this->assertCount(1, $response['results']);
        $this->assertSame('flat:'.$id2, $response['results'][0]['feed_id']);
        $this->assertSame('flat:'.$id1, $response['results'][0]['target_id']);
    }

    public function testAddActivityTo()
    {
        $to = Uuid::uuid4();

        $activity = [
            'actor' => 'multi1', 'verb' => 'tweet', 'object' => 1,
            'to' => ['flat:'.$to],
        ];
        $this->user1->addActivity($activity);
        $response = $this->client->feed('flat', $to)->getActivities(0, 2);
        $this->assertSame('multi1', $response['results'][0]['actor']);
    }

    public function testAddActivitiesTo()
    {
        $to = Uuid::uuid4();

        $activities = [
            [
                'actor' => 'many1', 'verb' => 'tweet', 'object' => 1,
                'to' => ['flat:'.$to],
            ],
            [
                'actor' => 'many2', 'verb' => 'tweet', 'object' => 1,
                'to' => ['flat:'.$to],
            ],
        ];
        $this->user1->addActivities($activities);
        $response = $this->client->feed('flat', $to)->getActivities(0, 2);
        $this->assertSame('many2', $response['results'][0]['actor']);
    }

    public function testUpdateActivitiesToRemoveTarget()
    {
        $feed = $this->client->feed('user', Uuid::uuid4());
        $target = Uuid::uuid4();
        $now = new DateTime('now', new DateTimeZone('Pacific/Nauru'));
        $time = $now->format(DateTime::ISO8601);
        $activities = [
            [
                'actor' => 'actor', 'verb' => 'tweet', 'object' => 1,
                'to'    => ["flat:${target}"], 'time' => $time,
                'foreign_id' => 'fid1',
            ],
        ];
        $feed->addActivities($activities);
        $response = $this->client->feed('flat', $target)->getActivities();
        $this->assertCount(1, $response['results']);

        $feed->updateActivityToTargets('fid1', $time, [], [], ["flat:${target}"]);

        $response = $this->client->feed('flat', $target)->getActivities();
        $this->assertCount(0, $response['results']);
    }

    public function testUpdateActivitiesToAddTarget()
    {
        $feed = $this->client->feed('user', Uuid::uuid4());
        $target = Uuid::uuid4();
        $now = new DateTime('now', new DateTimeZone('Pacific/Nauru'));
        $time = $now->format(DateTime::ISO8601);
        $activities = [
            [
                'actor' => 'actor', 'verb' => 'tweet', 'object' => 1,
                'time' => $time, 'foreign_id' => 'fid1',
            ],
        ];
        $feed->addActivities($activities);
        $response = $this->client->feed('flat', $target)->getActivities();
        $this->assertCount(0, $response['results']);

        $feed->updateActivityToTargets('fid1', $time, [], ["flat:${target}"], []);

        $response = $this->client->feed('flat', $target)->getActivities();
        $this->assertCount(1, $response['results']);
    }

    public function testUpdateActivitiesToAddRemoveTarget()
    {
        $feed = $this->client->feed('user', Uuid::uuid4());
        $target1 = Uuid::uuid4();
        $target2 = Uuid::uuid4();
        $now = new DateTime('now', new DateTimeZone('Pacific/Nauru'));
        $time = $now->format(DateTime::ISO8601);
        $activities = [
            [
                'actor' => 'actor', 'verb' => 'tweet', 'object' => 1,
                'to'    => ["flat:${target1}"], 'time' => $time,
                'foreign_id' => 'fid1',
            ],
        ];
        $feed->addActivities($activities);
        sleep(2);
        $response = $this->client->feed('flat', $target1)->getActivities();
        $this->assertCount(1, $response['results']);

        $feed->updateActivityToTargets('fid1', $time, [], ["flat:${target2}"], ["flat:${target1}"]);
        sleep(2);

        $response = $this->client->feed('flat', $target1)->getActivities();
        $this->assertCount(0, $response['results']);

        $response = $this->client->feed('flat', $target2)->getActivities();
        $this->assertCount(1, $response['results']);
    }

    public function testUpdateActivitiesToReplaceTargets()
    {
        $feed = $this->client->feed('user', Uuid::uuid4());
        $target1 = Uuid::uuid4();
        $target2 = Uuid::uuid4();
        $now = new DateTime('now', new DateTimeZone('Pacific/Nauru'));
        $time = $now->format(DateTime::ISO8601);
        $activities = [
            [
                'actor' => 'actor', 'verb' => 'tweet', 'object' => 1,
                'to'    => ["flat:${target1}"], 'time' => $time,
                'foreign_id' => 'fid1',
            ],
        ];
        $feed->addActivities($activities);
        sleep(2);
        $response = $this->client->feed('flat', $target1)->getActivities();
        $this->assertCount(1, $response['results']);

        $feed->updateActivityToTargets('fid1', $time, ["flat:${target2}"]);

        $response = $this->client->feed('flat', $target1)->getActivities();
        $this->assertCount(0, $response['results']);

        $response = $this->client->feed('flat', $target2)->getActivities();
        $this->assertCount(1, $response['results']);
    }

    public function testUpdateActivitiesWithZeroActivitiesShouldNotFail()
    {
        $this->client->updateActivities([]);
    }

    public function testGetAppActivities()
    {
        $fid = Uuid::uuid4();
        $time = '2006-01-02T15:04:05.999999999';
        $resp = $this->client->feed('flat', Uuid::uuid4())->addActivity(
            ['actor'=>'bob', 'verb'=>'does', 'object'=>'something', 'foreign_id'=>$fid, 'time'=>$time]
        );
        $id = $resp['id'];
        
        $resp = $this->client->getActivitiesById([$id]);
        $this->assertCount(1, $resp['results']);
        $this->assertSame($resp['results'][0]['id'], $id);

        $resp = $this->client->getActivitiesByForeignId([
            [$fid, $resp['results'][0]['time']],
        ]);
        $this->assertCount(1, $resp['results']);
        $this->assertSame($resp['results'][0]['id'], $id);
    }

    public function testActivityPartialUpdate()
    {
        $userId = Uuid::uuid4();
        $feed = $this->client->feed('flat', $userId);
        $activity = [
            'actor'=>'bob',
            'verb'=>'does',
            'object'=>'something',
            'foreign_id'=>Uuid::uuid4(),
            'time'=>'2006-01-02T15:04:05.999999999',
            'popularity'=>42,
            'foo'=> [
                'bar'=>'baz',
                'qux'=>'waldo',
            ],
        ];
        $resp = $feed->addActivity($activity);
        $set = [
            'popularity' => 23,
            'foo.bar' => 999,
        ];
        $unset = [ 'foo.qux' ];
        $this->client->activityPartialUpdateById($resp['id'], $set, $unset);
        $resp = $this->client->getActivitiesById([$resp['id']]);
        $this->assertCount(1, $resp['results']);
        $activity = $resp['results'][0];
        $expected = $activity;
        $expected['popularity'] = 23;
        $expected['foo']['bar'] = 999;
        $this->assertSame($resp['results'][0], $expected);
    }
}
