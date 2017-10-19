<?php

/*
 * This file is part of the Eventum (Issue Tracking System) package.
 *
 * @copyright (c) Eventum Team
 * @license GNU General Public License, version 2 or later (GPL-2+)
 *
 * For the full copyright and license information,
 * please see the COPYING and AUTHORS files
 * that were distributed with this source code.
 */

namespace Eventum\Test\Db;

use Date_Helper;
use Eventum\Db\Doctrine;
use Eventum\Model\Entity;
use Eventum\Model\Repository\ProjectRepository;
use Eventum\Model\Repository\UserRepository;
use Eventum\Test\TestCase;

/**
 * TODO: datetime and timezone: http://docs.doctrine-project.org/projects/doctrine-orm/en/latest/cookbook/working-with-datetime.html
 *
 * @group db
 */
class DoctrineTest extends TestCase
{
    public function testFindAll()
    {
        $repo = $this->getEntityManager()->getRepository(Entity\Project::class);
        $projects = $repo->findAll();

        /**
         * @var Entity\Project $project
         */
        foreach ($projects as $project) {
            printf("#%d: %s\n", $project->getId(), $project->getTitle());
        }
    }

    public function test2()
    {
        $em = $this->getEntityManager();
        $repo = $em->getRepository(Entity\Commit::class);
        $items = $repo->findBy([], null, 10);

        /**
         * @var Entity\Commit $item
         */
        foreach ($items as $item) {
            printf("* %s %s\n", $item->getId(), trim($item->getMessage()));
        }
    }

    public function test3()
    {
        $em = $this->getEntityManager();
        $repo = $em->getRepository(Entity\Commit::class);
        $qb = $repo->createQueryBuilder('commit');

        $qb->setMaxResults(10);

        $query = $qb->getQuery();
        $items = $query->getArrayResult();

        print_r($items);
    }

    /**
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function test4()
    {
        $em = $this->getEntityManager();

        $issue_id = 1;
        $changeset = uniqid('z1', true);
        $ci = Entity\Commit::create()
            ->setScmName('cvs')
            ->setAuthorName('Au Thor')
            ->setCommitDate(Date_Helper::getDateTime())
            ->setChangeset($changeset)
            ->setMessage('Mes-Sage');
        $em->persist($ci);
        $em->flush();

        $cf = Entity\CommitFile::create()
            ->setCommitId($ci->getId())
            ->setFilename('file');
        $em->persist($cf);
        $em->flush();

        $isc = Entity\IssueCommit::create()
            ->setCommitId($ci->getId())
            ->setIssueId($issue_id);
        $em->persist($isc);
        $em->flush();

        printf(
            "ci: %d\ncf: %d\nisc: %d\n",
            $ci->getId(), $cf->getId(), $isc->getId()
        );
    }

    public function testDeleteByQuery()
    {
        $issue_id = 13;
        $associated_issue_id = 12;
        $em = $this->getEntityManager();
        $qb = $em->createQueryBuilder();
        $qb->delete(Entity\IssueAssociation::class, 'ia');

        $expr = $qb->expr();
        $left = $expr->andX('ia.isa_issue_id = :isa_issue_id', 'ia.isa_associated_id = :isa_associated_id');
        $right = $expr->andX('ia.isa_issue_id = :isa_associated_id', 'ia.isa_associated_id = :isa_issue_id');
        $qb->where(
            $expr->orX()
                ->add($left)
                ->add($right)
        );

        $qb->setParameter('isa_issue_id', $issue_id);
        $qb->setParameter('isa_associated_id', $associated_issue_id);
        $query = $qb->getQuery();
        $query->execute();
    }

    /**
     * @throws \Doctrine\ORM\EntityNotFoundException
     */
    public function testProjectStatusId()
    {
        /** @var ProjectRepository $repo */
        $repo = $this->getEntityManager()->getRepository(Entity\Project::class);
        $prj_id = 1;
        $status_id = $repo->findById($prj_id)->getInitialStatusId();
        dump($status_id);

        $status_id = Doctrine::getProjectRepository()->findById($prj_id)->getInitialStatusId();
        dump($status_id);
    }

    public function testUserModel()
    {
        $repo = $this->getEntityManager()->getRepository(Entity\User::class);
        $items = $repo->findBy([], null, 1);

        dump($items);
    }

    public function testUserRepository()
    {
        /** @var UserRepository $repo */
        $repo = $this->getEntityManager()->getRepository(Entity\User::class);

        $user = $repo->findOneByCustomerContactId(1);
        dump($user);
        $user = $repo->findByContactId(1);
        dump($user);

        // find by id
        $user = $repo->find(-1);
        dump($user);

        // query for a single product matching the given name and price
        $user = $repo->findOneBy(
            ['status' => 'active', 'parCode' => 0]
        );

        // query for multiple products matching the given name, ordered by price
        $users = $repo->findBy(
            ['status' => 'inactive'],
            ['id' => 'ASC']
        );
    }
}
