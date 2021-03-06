<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Controller\Admin;

use App\Entity\Timesheet;
use App\Entity\User;
use App\Form\Type\DateRangeType;
use App\Tests\Controller\ControllerBaseTest;
use App\Tests\DataFixtures\TimesheetFixtures;

/**
 * @coversDefaultClass \App\Controller\Admin\TimesheetController
 * @group integration
 */
class TimesheetControllerTest extends ControllerBaseTest
{
    public function testIsSecure()
    {
        $this->assertUrlIsSecured('/team/timesheet/');
        $this->assertUrlIsSecuredForRole(User::ROLE_USER, '/team/timesheet/');
    }

    public function testIndexAction()
    {
        $client = $this->getClientForAuthenticatedUser(User::ROLE_TEAMLEAD);
        $this->assertAccessIsGranted($client, '/team/timesheet/');
        $this->assertTrue($client->getResponse()->isSuccessful());

        // there are no records by default in the test database
        $this->assertHasNoEntriesWithFilter($client);

        $result = $client->getCrawler()->filter('div.breadcrumb div.box-tools div.btn-group a.btn');
        $this->assertEquals(4, count($result));

        foreach ($result as $item) {
            $this->assertEquals('btn btn-default', $item->getAttribute('class'));
            $this->assertEquals('i', $item->firstChild->tagName);
        }
    }

    public function testIndexActionWithQuery()
    {
        $client = $this->getClientForAuthenticatedUser(User::ROLE_TEAMLEAD);

        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $user = $this->getUserByRole($em, User::ROLE_USER);
        $fixture = new TimesheetFixtures();
        $fixture->setAmount(10);
        $fixture->setUser($user);
        $fixture->setStartDate(new \DateTime('-10 days'));
        $this->importFixture($em, $fixture);

        $this->request($client, '/team/timesheet/');
        $this->assertTrue($client->getResponse()->isSuccessful());

        $dateRange = (new \DateTime('-10 days'))->format('Y-m-d') . DateRangeType::DATE_SPACER . (new \DateTime())->format('Y-m-d');

        $form = $client->getCrawler()->filter('form.navbar-form')->form();
        $client->submit($form, [
            'state' => 1,
            'user' => $user->getId(),
            'pageSize' => 25,
            'daterange' => $dateRange,
            'customer' => null,
        ]);

        $this->assertTrue($client->getResponse()->isSuccessful());
        $this->assertHasDataTable($client);
        $this->assertDataTableRowCount($client, 'datatable_timesheet_admin', 10);
    }

    public function testExportAction()
    {
        $client = $this->getClientForAuthenticatedUser(User::ROLE_TEAMLEAD);

        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $fixture = new TimesheetFixtures();
        $fixture->setAmount(7);
        $fixture->setUser($this->getUserByRole($em, User::ROLE_USER));
        $fixture->setStartDate(new \DateTime('-10 days'));
        $this->importFixture($em, $fixture);
        $fixture = new TimesheetFixtures();
        $fixture->setAmount(3);
        $fixture->setUser($this->getUserByRole($em, User::ROLE_TEAMLEAD));
        $fixture->setStartDate(new \DateTime('-10 days'));
        $this->importFixture($em, $fixture);

        $this->request($client, '/team/timesheet/');
        $this->assertTrue($client->getResponse()->isSuccessful());

        $dateRange = (new \DateTime('-10 days'))->format('Y-m-d') . DateRangeType::DATE_SPACER . (new \DateTime())->format('Y-m-d');

        $form = $client->getCrawler()->filter('form.navbar-form')->form();
        $form->getFormNode()->setAttribute('action', $this->createUrl('/team/timesheet/export'));
        $client->submit($form, [
            'state' => 1,
            'pageSize' => 25,
            'daterange' => $dateRange,
            'customer' => null,
        ]);

        $this->assertTrue($client->getResponse()->isSuccessful());

        $node = $client->getCrawler()->filter('body');
        $this->assertEquals('invoice_print', $node->getNode(0)->getAttribute('class'));

        $result = $node->filter('section.invoice table.table tbody tr');
        $this->assertEquals(10, count($result));
    }

    public function testCreateAction()
    {
        $client = $this->getClientForAuthenticatedUser(User::ROLE_TEAMLEAD);
        $this->request($client, '/team/timesheet/create');
        $this->assertTrue($client->getResponse()->isSuccessful());

        $form = $client->getCrawler()->filter('form[name=timesheet_edit_form]')->form();
        $client->submit($form, [
            'timesheet_edit_form' => [
                'description' => 'Testing is fun!',
                'project' => 1,
                'activity' => 1,
            ]
        ]);

        $this->assertIsRedirect($client, $this->createUrl('/team/timesheet/'));
        $client->followRedirect();
        $this->assertTrue($client->getResponse()->isSuccessful());
        $this->assertHasFlashSuccess($client);

        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        /** @var Timesheet $timesheet */
        $timesheet = $em->getRepository(Timesheet::class)->find(1);
        $this->assertInstanceOf(\DateTime::class, $timesheet->getBegin());
        $this->assertNull($timesheet->getEnd());
        $this->assertEquals('Testing is fun!', $timesheet->getDescription());
        $this->assertEquals(0, $timesheet->getRate());
        $this->assertNull($timesheet->getHourlyRate());
        $this->assertNull($timesheet->getFixedRate());
    }

    public function testDeleteActionIsNotAllowedForTeamlead()
    {
        $client = $this->getClientForAuthenticatedUser(User::ROLE_TEAMLEAD);

        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $fixture = new TimesheetFixtures();
        $fixture->setAmount(10);
        $fixture->setUser($this->getUserByRole($em, User::ROLE_USER));
        $fixture->setStartDate('2017-05-01');
        $this->importFixture($em, $fixture);

        $this->request($client, '/team/timesheet/1/delete');
        $this->assertFalse($client->getResponse()->isSuccessful());
    }

    public function testDeleteAction()
    {
        $client = $this->getClientForAuthenticatedUser(User::ROLE_ADMIN);

        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $fixture = new TimesheetFixtures();
        $fixture->setAmount(10);
        $fixture->setUser($this->getUserByRole($em, User::ROLE_USER));
        $fixture->setStartDate('2017-05-01');
        $this->importFixture($em, $fixture);

        $this->request($client, '/team/timesheet/1/edit');
        $this->assertTrue($client->getResponse()->isSuccessful());

        $this->request($client, '/team/timesheet/1/delete');
        $this->assertIsRedirect($client, $this->createUrl('/team/timesheet/page/1'));
        $client->followRedirect();
        $this->assertTrue($client->getResponse()->isSuccessful());
        $this->assertHasFlashSuccess($client);

        $this->request($client, '/team/timesheet/1/edit');
        $this->assertFalse($client->getResponse()->isSuccessful());
    }
}
