<?php

declare(strict_types=1);

namespace Ovrflo\ApiPlatformTypescriptGeneratorBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class ManipulateMetadataEvent extends Event
{
    public function __construct(
        public string $outputDir,
        public array $types,
        public array $operations,
        public array $files,
    ) {
    }
}
