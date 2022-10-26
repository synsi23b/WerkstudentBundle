<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WerkstudentBundle\Repository;

use App\Entity\User;
use App\Entity\Timesheet;
use App\Repository\Query\TimesheetQuery;
use App\Repository\TimesheetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\DBAL\Types\Types;

class WerkSheetRepository extends TimesheetRepository
{
    public static function createWerkSheetRepository(EntityManagerInterface $em): WerkSheetRepository
    {
        /** @psalm-param ClassMetadata<T> $class */
        // public function __construct(EntityManagerInterface $em, ClassMetadata $class)
        // {
        //     $this->_entityName = $class->name;
        //     $this->_em         = $em;
        //     $this->_class      = $class;
        // }
        return new WerkSheetRepository($em, $em->getClassMetadata(Timesheet::class));
    }

    public function getHolidays(User $user): float
    {
        return 25;
    }

    public function getSecondsWorked(User $user): float
    {
        $qr = new TimesheetQuery();
        $qr->setUser($user)
           ->setState(TimesheetQuery::STATE_STOPPED);
        $qb = $this->getQueryBuilderForQuery($qr);
        $qb->select('COALESCE(SUM(t.duration), 0)');
        # TODO dont hardcode activity ID
        $qb->andWhere("t.activity <> 11");
        $qb->getQuery()->getSingleScalarResult();
        /** @phpstan-ignore-next-line  */
        $result = $qb->getQuery()->getSingleScalarResult();

        return empty($result) ? 0 : $result;
    }

    public function getSecondsVacationTaken(User $user): float
    {
        $qr = new TimesheetQuery();
        $qr->setUser($user)
           ->setState(TimesheetQuery::STATE_STOPPED);
        $qb = $this->getQueryBuilderForQuery($qr);
        $qb->select('COALESCE(SUM(t.duration), 0)');
        # TODO dont hardcode activity ID
        $qb->andWhere("t.activity = 11");
        $qb->getQuery()->getSingleScalarResult();
        /** @phpstan-ignore-next-line  */
        $result = $qb->getQuery()->getSingleScalarResult();

        return empty($result) ? 0 : $result;
    }

    private function getQueryBuilderForQuery(TimesheetQuery $query): QueryBuilder
    {
        $qb = $this->getEntityManager()->createQueryBuilder();

        $requiresProject = false;
        $requiresCustomer = false;
        $requiresActivity = false;

        $qb
            ->select('t')
            ->from(Timesheet::class, 't')
        ;

        $orderBy = $query->getOrderBy();
        switch ($orderBy) {
            case 'project':
                $orderBy = 'p.name';
                $requiresProject = true;
                break;
            case 'customer':
                $requiresCustomer = true;
                $orderBy = 'c.name';
                break;
            case 'activity':
                $requiresActivity = true;
                $orderBy = 'a.name';
                break;
            default:
                $orderBy = 't.' . $orderBy;
                break;
        }

        $qb->addOrderBy($orderBy, $query->getOrder());

        $user = [];
        if (null !== $query->getUser()) {
            $user[] = $query->getUser();
        }

        $user = array_merge($user, $query->getUsers());

        if (empty($user) && null !== ($currentUser = $query->getCurrentUser()) && !$currentUser->canSeeAllData()) {
            // make sure that the user himself is in the list of users, if he is part of a team
            // if teams are used and the user is not a teamlead, the list of users would be empty and then leading to NOT limit the select by user IDs
            $user[] = $currentUser;

            foreach ($currentUser->getTeams() as $team) {
                if ($currentUser->isTeamleadOf($team)) {
                    $query->addTeam($team);
                }
            }
        }

        if (!empty($query->getTeams())) {
            foreach ($query->getTeams() as $team) {
                foreach ($team->getUsers() as $teamUser) {
                    $user[] = $teamUser;
                }
            }
        }

        $user = array_map(function ($user) {
            if ($user instanceof User) {
                return $user->getId();
            }

            return $user;
        }, $user);
        $user = array_unique($user);

        if (!empty($user)) {
            $qb->andWhere($qb->expr()->in('t.user', $user));
        }

        if (null !== $query->getBegin()) {
            $qb->andWhere($qb->expr()->gte('t.begin', ':begin'))
                ->setParameter('begin', $query->getBegin());
        }

        if ($query->isRunning()) {
            $qb->andWhere($qb->expr()->isNull('t.end'));
        } elseif ($query->isStopped()) {
            $qb->andWhere($qb->expr()->isNotNull('t.end'));
        }

        if (null !== $query->getEnd()) {
            $qb->andWhere($qb->expr()->lte('t.begin', ':end'))
                ->setParameter('end', $query->getEnd());
        }

        if ($query->isExported()) {
            $qb->andWhere('t.exported = :exported')->setParameter('exported', true, Types::BOOLEAN);
        } elseif ($query->isNotExported()) {
            $qb->andWhere('t.exported = :exported')->setParameter('exported', false, Types::BOOLEAN);
        }

        if ($query->isBillable()) {
            $qb->andWhere('t.billable = :billable')->setParameter('billable', true, Types::BOOLEAN);
        } elseif ($query->isNotBillable()) {
            $qb->andWhere('t.billable = :billable')->setParameter('billable', false, Types::BOOLEAN);
        }

        if (null !== $query->getModifiedAfter()) {
            $qb->andWhere($qb->expr()->gte('t.modifiedAt', ':modified_at'))
                ->setParameter('modified_at', $query->getModifiedAfter());
        }

        if ($query->hasActivities()) {
            $qb->andWhere($qb->expr()->in('t.activity', ':activity'))
                ->setParameter('activity', $query->getActivities());
        }

        if ($query->hasProjects()) {
            $qb->andWhere($qb->expr()->in('t.project', ':project'))
                ->setParameter('project', $query->getProjects());
        } elseif ($query->hasCustomers()) {
            $requiresCustomer = true;
            $qb->andWhere($qb->expr()->in('p.customer', ':customer'))
                ->setParameter('customer', $query->getCustomers());
        }

        $tags = $query->getTags();
        if (!empty($tags)) {
            $qb->andWhere($qb->expr()->isMemberOf(':tags', 't.tags'))
                ->setParameter('tags', $query->getTags());
        }

        $requiresTeams = $this->addPermissionCriteria($qb, $query->getCurrentUser(), $query->getTeams());

        //$this->addSearchTerm($qb, $query);

        if ($requiresCustomer || $requiresProject || $requiresTeams) {
            $qb->leftJoin('t.project', 'p');
        }

        if ($requiresCustomer || $requiresTeams) {
            $qb->leftJoin('p.customer', 'c');
        }

        if ($requiresActivity) {
            $qb->leftJoin('t.activity', 'a');
        }

        if ($query->getMaxResults() !== null) {
            $qb->setMaxResults($query->getMaxResults());
        }

        return $qb;
    }

    /**
     * This method causes me some headaches ...
     *
     * Activity permissions are currently not checked (which would be easy to add)
     *
     * Especially the following question is still un-answered!
     *
     * Should a teamlead:
     * 1. see all records of his team-members, even if they recorded times for projects invisible to him
     * 2. only see records for projects which can be accessed by hom (current situation)
     */
    private function addPermissionCriteria(QueryBuilder $qb, ?User $user = null, array $teams = []): bool
    {
        // make sure that all queries without a user see all projects
        if (null === $user && empty($teams)) {
            return false;
        }

        // make sure that admins see all timesheet records
        if (null !== $user && $user->canSeeAllData()) {
            return false;
        }

        if (null !== $user) {
            $teams = array_merge($teams, $user->getTeams());
        }

        if (empty($teams)) {
            $qb->andWhere('SIZE(c.teams) = 0');
            $qb->andWhere('SIZE(p.teams) = 0');

            return true;
        }

        $orProject = $qb->expr()->orX(
            'SIZE(p.teams) = 0',
            $qb->expr()->isMemberOf(':teams', 'p.teams')
        );
        $qb->andWhere($orProject);

        $orCustomer = $qb->expr()->orX(
            'SIZE(c.teams) = 0',
            $qb->expr()->isMemberOf(':teams', 'c.teams')
        );
        $qb->andWhere($orCustomer);

        $ids = array_values(array_unique(array_map(function (Team $team) {
            return $team->getId();
        }, $teams)));

        $qb->setParameter('teams', $ids);

        return true;
    }
}
  