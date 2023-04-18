<?php

/*
 * This file is part of the DemoBundle for Kimai 2.
 * All rights reserved by Kevin Papst (www.kevinpapst.de).
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WerkstudentBundle\EventSubscriber;

use App\Event\DashboardEvent;
use App\Widget\Type\CompoundRow;
use KimaiPlugin\WerkstudentBundle\Widget\StudentWidget;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class DashboardSubscriber implements EventSubscriberInterface
{
    private $widget;

    public function __construct(StudentWidget $widget)
    {
        $this->widget = $widget;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            //DashboardEvent::class => ['onDashboardEvent', 90],
        ];
    }

    public function onDashboardEvent(DashboardEvent $event): void
    {
        $section = new CompoundRow();
        $section->setOrder(5);
        $this->widget->setUser($event->getUser());
        $section->addWidget($this->widget);

        $event->addSection($section);
    }
}
