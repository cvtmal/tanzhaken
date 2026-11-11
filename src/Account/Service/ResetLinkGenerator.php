<?php

declare(strict_types=1);

namespace App\Account\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Builds the absolute reset link from DEFAULT_URI, never from the request's Host header, so a
 * forged Host cannot point the mailed link at another site.
 */
final class ResetLinkGenerator
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        #[Autowire(env: 'DEFAULT_URI')]
        private readonly string $defaultUri,
    ) {
    }

    public function forToken(string $plainToken): string
    {
        $saved = $this->router->getContext();
        $this->router->setContext(RequestContext::fromUri($this->defaultUri));

        try {
            return $this->router->generate('password_reset', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL);
        } finally {
            $this->router->setContext($saved);
        }
    }
}
