<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
            $response = new JsonResponse(['status' => 'ok']);
        } catch (\Throwable) {
            $response = new JsonResponse(['status' => 'error'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
