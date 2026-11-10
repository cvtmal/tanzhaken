<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Credits\Entity\CreditGrant;
use Symfony\Component\Clock\Clock;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<CreditGrant>
 */
final class CreditGrantFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return CreditGrant::class;
    }

    public function voided(): self
    {
        return $this->afterInstantiate(static function (CreditGrant $grant): void {
            $grant->void(Clock::get()->now());
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'enrollment' => EnrollmentFactory::new(),
            'now' => Clock::get()->now(),
            'quantity' => CreditGrant::DEFAULT_QUANTITY,
        ];
    }
}
