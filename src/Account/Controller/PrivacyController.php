<?php

declare(strict_types=1);

namespace App\Account\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public privacy statement (PRIV-1, #18). The data controller (gate G5) and the storage region
 * (D-046) come from env; outside prod every value carries a "Placeholder" badge, and an empty value
 * shows "Not configured" in every environment.
 */
final class PrivacyController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'APP_OPERATOR_NAME')]
        private readonly string $operatorName,
        #[Autowire(env: 'APP_OPERATOR_ADDRESS')]
        private readonly string $operatorAddress,
        #[Autowire(env: 'APP_OPERATOR_EMAIL')]
        private readonly string $operatorEmail,
        #[Autowire(env: 'APP_HOSTING_REGION')]
        private readonly string $hostingRegion,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    #[Route('/privacy', name: 'privacy', methods: ['GET'])]
    public function show(): Response
    {
        return $this->render('account/privacy.html.twig', [
            'operator_name' => trim($this->operatorName),
            'operator_address' => trim($this->operatorAddress),
            'operator_email' => trim($this->operatorEmail),
            'hosting_region' => trim($this->hostingRegion),
            'placeholders' => 'prod' !== $this->environment,
        ]);
    }
}
