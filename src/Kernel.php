<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // S3: every timestamp is UTC, whatever the host's date.timezone says.
        date_default_timezone_set('UTC');

        parent::boot();
    }
}
