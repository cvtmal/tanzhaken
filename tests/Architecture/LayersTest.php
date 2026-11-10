<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use Doctrine\ORM\EntityManagerInterface;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * phpat rules (run by phpstan, not PHPUnit): the layers inside every module (docs/rpi-cycles.md S2).
 */
final class LayersTest
{
    private const CONTROLLER = '/^App\\\\[A-Za-z]+\\\\Controller(\\\\|$)/';
    private const ENTITY = '/^App\\\\[A-Za-z]+\\\\Entity(\\\\|$)/';
    private const SERVICE = '/^App\\\\[A-Za-z]+\\\\Service(\\\\|$)/';
    private const NOT_FOR_ENTITIES = '/^App\\\\[A-Za-z]+\\\\(Controller|Repository|Service|ReadModel|Form|Twig|Console)(\\\\|$)/';

    public function test_only_controllers_use_controllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App'))
            ->excluding(Selector::inNamespace(self::CONTROLLER, true), Selector::inNamespace('App\Tests'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::CONTROLLER, true))
            ->because('Controllers are entry points; nothing else calls them.');
    }

    public function test_entities_stay_in_the_domain_layer(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::ENTITY, true))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NOT_FOR_ENTITIES, true))
            ->because('Entities may use entities, exceptions, security interfaces and Shared only.');
    }

    public function test_controllers_do_not_write_through_the_entity_manager(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::CONTROLLER, true))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(EntityManagerInterface::class))
            ->because('Writes go through one final service method per use case.');
    }

    public function test_services_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::SERVICE, true))
            ->should()->beFinal()
            ->because('Services are final (docs/rpi-cycles.md S2).');
    }
}
