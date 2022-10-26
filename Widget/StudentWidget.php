<?php

/*
 * This file is part of the DemoBundle for Kimai 2.
 * All rights reserved by Kevin Papst (www.kevinpapst.de).
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WerkstudentBundle\Widget;

use App\Entity\User;
use App\Repository\Query\UserQuery;
use App\Repository\Query\TimesheetQuery;
use App\Repository\UserRepository;
use App\Repository\TimesheetRepository;
use App\Widget\Type\SimpleWidget;
use App\Widget\Type\UserWidget;

use KimaiPlugin\WerkstudentBundle\Repository\WerkSheetRepository;
use phpDocumentor\Reflection\Types\Null_;

class StudentWidget extends SimpleWidget implements UserWidget
{
    /**
     * @var UserRepository
     */
    private $userrep;
    /**
     * @var WerkSheetRepository
     */
    private $werksheetrep;

    //public function __construct(UserRepository $userrepository, TimesheetRepository $sheetrep, WerkSheetRepository $werkrep)
    public function __construct(WerkSheetRepository $werkrep, UserRepository $userrepository)
    {
        $this->userrep = $userrepository;
        //$this->sheetrepo = $sheetrep;
        $this->werksheetrep = $werkrep;

        $this->setId('StudentWidget');
        $this->setTitle('Student vacation widget');
        $this->setOptions([
            'user' => null,
            'id' => '',
        ]);
    }

    public function setUser(User $user): void
    {
        $this->setOption('user', $user);
    }

    public function getOptions(array $options = []): array
    {
        $options = parent::getOptions($options);

        if (empty($options['id'])) {
            $options['id'] = 'StudentWidget';
        }

        return $options;
    }

    public function calculateVacationAvailable($workingweeks, $worked, $vacation)
    {
        $employee_vac_seconds = 192 * 3600; // 691200;
        //$student_avg_week_s = $worked / $workingweeks;
        $employee_work_week_s = 40 * 3600; //144000;
        
        $vacperyear = $employee_vac_seconds * ($worked / $workingweeks) / $employee_work_week_s;
        $formustring = "(employee_vacation_seconds x student_avg_week_s / employee_work_week_s) - vacation_taken_s = vacation_available_seconds";
        $calcstring = sprintf("%u x (%u / %.1f) / %u = %u seconds per year -> %.2f hours per year", $employee_vac_seconds, $worked, $workingweeks, $employee_work_week_s, $vacperyear, $vacperyear / 3600);
        $vacavail = $vacperyear - $vacation;
        $calcstring2 = sprintf("seconds_per_year - vacation_already_taken = vacation_available -> %u - %u = %u", $vacperyear, $vacation, $vacavail);
        return [$vacavail, $formustring, $calcstring, $calcstring2];
    }

    public function capVacationByMonth($workingdays, $vacavail)
    {
        if($workingdays < 180)
        {
            $vacavail = ($vacavail / 12) * floor($workingdays / 30);
        }
        return $vacavail;
    }

    public function getData(array $options = [])
    {
        $options = $this->getOptions($options);
        /** @var User $user */
        $user = $options['user'];
        
        $worked = $this->werksheetrep->getSecondsWorked($user);
        $vacation = $this->werksheetrep->getSecondsVacationTaken($user);
        $workingdays = $user->getRegisteredAt()->diff(date_create('now'))->days;
        $workingweeks = round($workingdays / 7, 1);
        $calcavail = $this->calculateVacationAvailable($workingweeks, $worked, $vacation);
        $vacavail = $calcavail[0];

        $vacation_capped = $this->capVacationByMonth($workingdays, $vacavail);
        $vachours = floor($vacation_capped / 3600);
        $vacminutes = round(($vacation_capped - $vachours * 3600) / 60);

        $vac_cap_exp = null;
        if($vacation_capped != $vacavail)
        {
            $vac_cap_exp = sprintf("Since your working time is less than 6 Month, the available vacation got capped at %u month", floor($workingdays / 30));
        }

        return [
            'worked' => round($worked / 3600, 2),
            'vacation' => round($vacation / 3600, 2),
            'days_working' => $workingdays,
            'weeks_working' => $workingweeks,
            'vacation_hours' => $vachours,
            'vacation_minutes' => $vacminutes,
            'detailedcalc' => $calcavail,
            'cap_explenation' => $vac_cap_exp
        ];
    }

    public function getTemplateName(): string
    {
        return '@Werkstudent/studentvacwidget.html.twig';
    }
}
