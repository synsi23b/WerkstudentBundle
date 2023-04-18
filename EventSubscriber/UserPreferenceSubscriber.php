<?php

/*
 * This file is part of the DemoBundle for Kimai 2.
 * All rights reserved by Kevin Papst (www.kevinpapst.de).
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WerkstudentBundle\EventSubscriber;

use App\Entity\UserPreference;
use App\Event\UserPreferenceEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Validator\Constraints\Range;

class UserPreferenceSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            UserPreferenceEvent::class => ['loadUserPreferences', 200],
        ];
    }

    public function loadUserPreferences(UserPreferenceEvent $event): void
    {
        $event->addPreference(
            (new UserPreference())
                ->setName('is_werkstudent')
                ->setValue(0)
                ->setOrder(900)
                ->setType(CheckboxType::class)
                ->setEnabled(true)
                ->setOptions(['help' => 'Check this to assign the user to the Werkstudent group'])
                ->setSection('Werkstudent')
        );
    }
}
