<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Config;

use Amtgard\IdP\Persistence\Common\Repositories\UserPolicyClaimRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginClientRepository;
use Amtgard\IdP\Services\ClientIamMetadataService;
use Amtgard\IdP\Services\ClientIamPolicyService;
use Amtgard\IdP\Tests\Utility\Log\IdpLoggingTestFactory;
use Amtgard\IdP\Utility\Log\IdpLogging;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;

/**
 * Builder services are empty when PHP-DI constructs them with new.
 * These factories must set the repositories or the first IAM write fatal-errors.
 */
final class ClientIamServiceWiringTest extends TestCase
{
    public function testPolicyServiceFactorySetsTheClaimRepository(): void
    {
        $repository = $this->createStub(UserPolicyClaimRepository::class);
        $service = $this->definitions()[ClientIamPolicyService::class]($this->containerFor([
            UserPolicyClaimRepository::class => $repository,
        ]));

        $this->assertSame(
            $repository,
            (new ReflectionProperty(ClientIamPolicyService::class, 'policyClaimRepository'))->getValue($service)
        );
    }

    public function testMetadataServiceFactorySetsTheMetadataRepository(): void
    {
        $repository = $this->createStub(UserLoginClientRepository::class);
        $service = $this->definitions()[ClientIamMetadataService::class]($this->containerFor([
            UserLoginClientRepository::class => $repository,
        ]));

        $this->assertSame(
            $repository,
            (new ReflectionProperty(ClientIamMetadataService::class, 'metadataRepository'))->getValue($service)
        );
    }

    /**
     * @return array<class-string, callable>
     */
    private function definitions(): array
    {
        /** @var array<class-string, callable> $definitions */
        $definitions = require dirname(__DIR__, 2) . '/config/container.php';

        return $definitions;
    }

    /**
     * @param array<class-string, object> $services
     */
    private function containerFor(array $services): ContainerInterface
    {
        $idpLogging = IdpLoggingTestFactory::create();
        $services[IdpLogging::class] = $idpLogging;

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            fn (string $id) => $services[$id] ?? throw new \RuntimeException('Unexpected service: ' . $id)
        );

        return $container;
    }
}
