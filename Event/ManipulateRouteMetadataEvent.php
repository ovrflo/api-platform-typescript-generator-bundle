<?php

declare(strict_types=1);

namespace Ovrflo\ApiPlatformTypescriptGeneratorBundle\Event;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\Route;
use Symfony\Contracts\EventDispatcher\Event;

final class ManipulateRouteMetadataEvent extends Event
{
    public function __construct(
        public readonly string $name,
        public readonly Route $route,
        public string $typescriptName,
        public array $config,
        public bool $shouldGenerate = true,
        public ?OutputInterface $output = null,
    ) {
    }

    public function skipGeneration(bool $skip = true): void
    {
        $this->shouldGenerate = !$skip;
        $this->stopPropagation();
    }
}
