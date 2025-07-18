<?php

declare(strict_types=1);
/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

namespace Elabftw\Models;

use DateInterval;
use DateTime;
use Elabftw\Enums\Action;
use Elabftw\Enums\BasePermissions;
use Elabftw\Enums\Scope;
use Elabftw\Exceptions\DatabaseErrorException;
use Elabftw\Exceptions\IllegalActionException;
use Elabftw\Exceptions\ImproperActionException;
use Symfony\Component\HttpFoundation\InputBag;

class SchedulerTest extends \PHPUnit\Framework\TestCase
{
    private Scheduler $Scheduler;

    private array $delta;

    private string $start;

    private string $end;

    protected function setUp(): void
    {
        $Users = new Users(1, 1);
        $Items = new Items($Users, 1);
        $d = new DateTime('now');
        $this->start = $d->format('c');
        $d->add(new DateInterval('PT2H'));
        $this->end = $d->format('c');
        $this->Scheduler = new Scheduler($Items, null, $this->start, $this->end);
        $this->delta = array(
            'years' => '0',
            'months' => '0',
            'days' => '1',
            'milliseconds' => '0',
        );
    }

    public function testGetApiPath(): void
    {
        $this->assertEquals('api/v2/event/', $this->Scheduler->getApiPath());
    }

    public function testCreate(): int
    {
        $id = $this->Scheduler->postAction(Action::Create, array('start' => $this->start, 'end' => $this->end, 'title' => 'Yep'));
        $this->assertIsInt($id);
        return $id;
    }

    public function testFailure(): void
    {
        $Users = new Users(1, 1);
        $Items = new Items($Users);
        $Scheduler = new Scheduler($Items, null, $this->start, $this->end);
        $this->expectException(ImproperActionException::class);
        $Scheduler->postAction(Action::Create, array());
    }

    public function testReadFromAnItem(): void
    {
        $this->assertIsArray($this->Scheduler->readAll());
        $Items = new Items(new Users(1, 1), 1);
        $this->Scheduler = new Scheduler($Items, null, $this->start, $this->end);
        $this->assertIsArray($this->Scheduler->readOne());
    }

    public function testReadAllWithVariousScopes(): void
    {
        foreach (array(Scope::User->value, Scope::Team->value, Scope::Everything->value) as $scope) {
            $Users = new Users(1, 1);
            $Users->userData['scope_events'] = $scope;

            $Items = new Items($Users, 1);
            $Scheduler = new Scheduler($Items, null, $this->start, $this->end);

            $this->assertReadAllReturnsValidEvents($Scheduler, $scope);
        }
    }

    public function testEventVisibilityByTeamAccess(): void
    {
        $Items = $this->createItem();

        // grant user 2 'canread' permissions only. Prevents 'access entity without permission'
        $Items->patch(Action::Update, array(
            'canread' => json_encode(array(
                'base' => BasePermissions::User->value,
                'users' => array(2),
                'teams' => array(),
                'teamgroups' => array(),
            )),
        ));

        $title = 'Bookable only by user 1';
        // add event to scheduler by user 1
        $Scheduler1 = new Scheduler($Items);
        $Scheduler1->postAction(Action::Create, array('start' => $this->start, 'end' => $this->end, 'title' => $title));

        // User 2 can read but cannot book
        $User2 = new Users(2, 2);
        // Sets scope->Everything and tries to see user 1's booking
        $User2->userData['scope_events'] = Scope::Everything->value;
        $Items2 = new Items($User2, $Items->id);
        $Scheduler2 = new Scheduler($Items2, null, $this->start, $this->end);

        $events = $Scheduler2->readAll();
        $this->assertIsArray($events);
        $this->assertCount(0, $events, 'User 2 should not see any events due to lack of book permission.');

        // now make it visible for user in the other team
        $Items->patch(Action::Update, array(
            'canbook' => json_encode(array(
                'base' => BasePermissions::User->value,
                'users' => array(2),
                'teams' => array(),
                'teamgroups' => array(),
            )),
        ));

        // Refresh scheduler then User 2 retries after being granted book permission
        $Scheduler2 = new Scheduler($Items2, null, $this->start, $this->end);
        $eventsAfterGrant = $Scheduler2->readAll();
        $this->assertIsArray($eventsAfterGrant);
        $this->assertCount(1, $eventsAfterGrant, 'User 2 should now see the event.');
        $this->assertEquals($title, $eventsAfterGrant[0]['title_only']);
    }

    public function testReadAllWithFilters(): void
    {
        $categoryId = 6;
        $Items = $this->createItem(1, 1, $categoryId);
        $Scheduler = new Scheduler($Items);

        $title = 'The filtered event';
        $Scheduler->postAction(Action::Create, array('start' => $this->start, 'end' => $this->end, 'title' => $title));

        // Filtering by item id
        $q = $this->Scheduler->getQueryParams(new InputBag(array('items' => array($Items->id))));
        $allEvents = $this->Scheduler->readAll();
        $filteredEvent = $this->Scheduler->readAll($q);

        $this->assertNotEmpty($allEvents);
        $this->assertCount(1, $filteredEvent);
        $this->assertEquals($title, $filteredEvent[0]['title_only']);
        $this->assertEquals($Items->id, $filteredEvent[0]['items_id'], 'Item ID should match the filtered item');

        // Filtering by category
        $titleItem2 = 'New Item in category 6';
        $Scheduler->postAction(Action::Create, array('start' => $this->start, 'end' => $this->end, 'title' => $titleItem2));

        $qCat = $this->Scheduler->getQueryParams(new InputBag(array('category' => $categoryId)));
        $filteredCatEvents = $this->Scheduler->readAll($qCat);
        // two events in category 4 now
        $this->assertCount(2, $filteredCatEvents);
        $this->assertEquals($title, $filteredCatEvents[0]['title_only']);
        $this->assertEquals($titleItem2, $filteredCatEvents[1]['title_only']);
    }

    public function testPatchEpoch(): Scheduler
    {
        $Items = new Items(new Users(1, 1), 1);
        $id = $this->testCreate();
        $Scheduler = new Scheduler($Items, $id, $this->start, $this->end);
        $this->assertIsArray($Scheduler->patch(Action::Update, array('target' => 'start_epoch', 'epoch' => date('U'))));
        $this->assertIsArray($Scheduler->patch(Action::Update, array('target' => 'end_epoch', 'epoch' => date('U'))));
        return $Scheduler;
    }

    public function testPatchEpochInvalidTarget(): void
    {
        $Scheduler = $this->testPatchEpoch();
        $this->expectException(ImproperActionException::class);
        $Scheduler->patch(Action::Update, array('target' => 'oops', 'epoch' => date('U')));
    }

    public function testPatchEpochInvalidEpoch(): void
    {
        $Scheduler = $this->testPatchEpoch();
        $this->expectException(ImproperActionException::class);
        $Scheduler->patch(Action::Update, array('target' => 'end_epoch', 'epoch' => ''));
    }

    public function testPatchTitle(): void
    {
        $Items = new Items(new Users(1, 1), 1);
        $id = $this->testCreate();
        $Scheduler = new Scheduler($Items, $id, $this->start, $this->end);
        $res = $Scheduler->patch(Action::Update, array('target' => 'title', 'content' => 'new title'));
        $this->assertEquals('new title', $res['title']);
    }

    public function testDestroyNonCancellableEvent(): void
    {
        $Items = $this->createItem(2, 1);
        $Items->patch(Action::Update, array('book_is_cancellable' => 0));
        $Scheduler = new Scheduler($Items);
        $d = new DateTime('tomorrow');
        $start = $d->format('c');
        $d->add(new DateInterval('PT2H'));
        $end = $d->format('c');
        $id = $Scheduler->postAction(Action::Create, array('start' => $start, 'end' => $end, 'title' => 'Yep'));
        $Scheduler->setId($id);
        $this->expectException(ImproperActionException::class);
        $Scheduler->destroy();
    }

    public function testCancelTooClose(): void
    {
        $Items = $this->createItem(2);
        $Items->patch(Action::Update, array('book_cancel_minutes' => 666));
        $Scheduler = new Scheduler($Items);
        $d = new DateTime('5 minutes');
        $start = $d->format('c');
        $d->add(new DateInterval('PT2H'));
        $end = $d->format('c');
        $id = $Scheduler->postAction(Action::Create, array('start' => $start, 'end' => $end, 'title' => 'Yep'));
        $Scheduler->setId($id);
        $this->expectException(ImproperActionException::class);
        $Scheduler->destroy();
    }

    public function testSlotTime(): void
    {
        $Items = $this->createItem(2, 1);
        $Items->patch(Action::Update, array('book_max_minutes' => 12));
        $Scheduler = new Scheduler($Items);
        $d = new DateTime('5 minutes');
        $start = $d->format('c');
        $d->add(new DateInterval('PT2H'));
        $end = $d->format('c');
        $this->expectException(ImproperActionException::class);
        $Scheduler->postAction(Action::Create, array('start' => $start, 'end' => $end, 'title' => 'Yep'));
    }

    public function testOverlap(): void
    {
        $Items = $this->createItem(2, 1);
        $Items->patch(Action::Update, array('book_can_overlap' => 0));
        $Scheduler = new Scheduler($Items);
        // first one
        $d = new DateTime('5 minutes');
        $start = $d->format('c');
        $d->add(new DateInterval('PT2H'));
        $end = $d->format('c');
        $Scheduler->postAction(Action::Create, array('start' => $start, 'end' => $end, 'title' => 'Yep'));
        // second one
        $d = new DateTime('15 minutes');
        $start = $d->format('c');
        $d->add(new DateInterval('PT2H'));
        $end = $d->format('c');
        $this->expectException(ImproperActionException::class);
        $Scheduler->postAction(Action::Create, array('start' => $start, 'end' => $end, 'title' => 'Yep'));
    }

    public function testOverlapWhileChangingExisting(): void
    {
        $Items = $this->createItem(2, 1);
        $Items->patch(Action::Update, array('book_can_overlap' => 0));
        $Scheduler = new Scheduler($Items);
        // first one
        $d = new DateTime('5 minutes');
        $start = $d->format('c');
        $d->add(new DateInterval('PT2H'));
        $end = $d->format('c');
        $Scheduler->postAction(Action::Create, array('start' => $start, 'end' => $end, 'title' => 'Yep'));
        // second one
        $d = new DateTime('3 hours');
        $start = $d->format('c');
        $d->add(new DateInterval('PT2H'));
        $end = $d->format('c');
        $id = $Scheduler->postAction(Action::Create, array('start' => $start, 'end' => $end, 'title' => 'Yep'));
        $Scheduler->setId($id);
        $this->expectException(ImproperActionException::class);
        $Scheduler->patch(Action::Update, array('target' => 'start_epoch', 'epoch' => (string) time()));
    }

    public function testCheckMaxSlots(): void
    {
        $Items = $this->createItem(2, 1);
        $Items->patch(Action::Update, array('book_max_slots' => 2));
        $Scheduler = new Scheduler($Items);
        $d = new DateTime('5 minutes');
        $start = $d->format('c');
        $d->add(new DateInterval('PT2H'));
        $end = $d->format('c');
        $Scheduler->postAction(Action::Create, array('start' => $start, 'end' => $end, 'title' => 'Yep'));
        $Scheduler->postAction(Action::Create, array('start' => $start, 'end' => $end, 'title' => 'Yep'));
        $this->expectException(ImproperActionException::class);
        $Scheduler->postAction(Action::Create, array('start' => $start, 'end' => $end, 'title' => 'Yep'));
    }

    public function testBind(): void
    {
        $this->Scheduler->setId($this->testCreate());
        $this->assertIsArray($this->Scheduler->patch(Action::Update, array('target' => 'experiment', 'id' => 3)));
        $this->assertIsArray($this->Scheduler->patch(Action::Update, array('target' => 'item_link', 'id' => 3)));
    }

    public function testBindIncorrect(): void
    {
        $this->Scheduler->setId($this->testCreate());
        $this->expectException(DatabaseErrorException::class);
        $this->Scheduler->patch(Action::Update, array('target' => 'experiment', 'id' => -12));
    }

    public function testUnbind(): void
    {
        $this->Scheduler->setId($this->testCreate());
        $this->assertIsArray($this->Scheduler->patch(Action::Update, array('target' => 'experiment', 'id' => null)));
        $this->assertIsArray($this->Scheduler->patch(Action::Update, array('target' => 'item_link', 'id' => null)));
    }

    public function testCanWriteAndWeAreAdmin(): void
    {
        $Users = new Users(2, 1);
        $Items = new Items($Users, 3);
        $Scheduler = new Scheduler($Items, null, $this->start, $this->end);
        // create with user, make sure it's in the future!
        $d = new DateTime('now');
        $d->add(new DateInterval('PT2H'));
        $start = $d->format('c');
        $d->add(new DateInterval('PT4H'));
        $end = $d->format('c');
        $id = $Scheduler->postAction(Action::Create, array('start' => $start, 'end' => $end, 'title' => 'Yep'));
        // write with admin
        $this->Scheduler->setId($id);
        $this->assertTrue($this->Scheduler->destroy());
    }

    public function testCanNotWrite(): void
    {
        // normal user
        $Users = new Users(2, 1);
        $Items = new Items($Users, 1);
        $d = new DateTime('now');
        $d->add(new DateInterval('PT2H'));
        $start = $d->format('c');
        $d->add(new DateInterval('PT4H'));
        $end = $d->format('c');
        $Scheduler = new Scheduler($Items, null, $start, $end);
        $Scheduler->setId($this->testCreate());
        // try write event created by admin as user
        $this->expectException(IllegalActionException::class);
        $Scheduler->patch(Action::Update, array('target' => 'experiment', 'id' => 3));
    }

    public function testUpdateStart(): void
    {
        $this->Scheduler->setId($this->testCreate());
        $this->Scheduler->patch(Action::Update, array('target' => 'start', 'delta' => $this->delta));
        $delta = array(
            'years' => '0',
            'months' => '0',
            'days' => '1',
            'milliseconds' => '1111',
        );
        $this->Scheduler->patch(Action::Update, array('target' => 'start', 'delta' => $delta));
    }

    public function testUpdateEnd(): void
    {
        $this->Scheduler->setId($this->testCreate());
        $this->Scheduler->patch(Action::Update, array('target' => 'end', 'delta' => $this->delta));
        $delta = array(
            'years' => '0',
            'months' => '0',
            'days' => '1',
            'milliseconds' => '1111',
        );
        $this->Scheduler->patch(Action::Update, array('target' => 'end', 'delta' => $delta));
    }

    public function testDestroy(): void
    {
        $id = $this->Scheduler->postAction(Action::Create, array(
            'start' => '2016-07-22T19:42:00+02:00',
            'end' => '2016-07-23T19:42:00+02:00',
            'title' => 'Yep',
        ));
        $this->Scheduler->setId($id);
        $this->assertTrue($this->Scheduler->destroy());
    }

    public function testCanCancelDuringGracePeriod(): void
    {
        $Items = $this->createItem(2, 1);
        $Scheduler = new Scheduler($Items);
        $d = new DateTime('+1 hour');
        $start = $d->format('c');
        $d->add(new DateInterval('PT2H'));
        $end = $d->format('c');
        $id = $Scheduler->postAction(Action::Create, array('start' => $start, 'end' => $end, 'title' => 'test grace period'));
        $Scheduler->setId($id);
        $this->assertTrue($Scheduler->destroy());
    }

    private function assertReadAllReturnsValidEvents(Scheduler $Scheduler, int $scope): void
    {
        $events = $Scheduler->readAll();
        $this->assertNotEmpty($events, 'Expected events but got none');

        foreach ($events as $event) {
            $this->assertArrayHasKey('id', $event);
            $this->assertArrayHasKey('userid', $event);
            $this->assertArrayHasKey('start', $event);
            $this->assertArrayHasKey('end', $event);
        }

        $this->assertReadAllByScope($events, $scope);
    }

    private function assertReadAllByScope(array $events, int $scope): void
    {
        foreach ($events as $event) {
            switch ($scope) {
                case Scope::User->value:
                    $this->assertEquals(1, $event['userid'], 'Event does not belong to user with id 1');
                    break;

                case Scope::Team->value:
                    $this->assertEquals(1, $event['team'], 'Event is not from team with id 1');
                    break;

                case Scope::Everything->value:
                    $this->assertTrue(
                        $event['userid'] === 1 || $event['team'] === 1,
                        'Event does not match user or team!'
                    );
                    break;

                default:
                    $this->fail("Unknown scope value: $scope");
            }
        }
    }

    private function createItem(int $userId = 1, int $teamId = 1, int $categoryId = 6): Items
    {
        $user = new Users($userId, $teamId);
        $items = new Items($user);
        $itemId = $items->create(template: $categoryId);
        $items->setId($itemId);
        return $items;
    }
}
