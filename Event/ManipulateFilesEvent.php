<?php

declare(strict_types=1);

namespace Ovrflo\ApiPlatformTypescriptGeneratorBundle\Event;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\EventDispatcher\Event;

final class ManipulateFilesEvent extends Event
{
    public function __construct(
        public string $outputDir,
        public array $files,
        public ?OutputInterface $output = null,
    ) {
    }
}
