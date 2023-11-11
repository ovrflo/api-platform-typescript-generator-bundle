<?php

declare(strict_types=1);

namespace Ovrflo\ApiPlatformTypescriptGeneratorBundle;

use Ovrflo\ApiPlatformTypescriptGeneratorBundle\DependencyInjection\OvrfloApiPlatformTypescriptGeneratorExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class OvrfloApiPlatformTypescriptGeneratorBundle extends Bundle
{
    public function getContainerExtension(): OvrfloApiPlatformTypescriptGeneratorExtension
    {
        return new OvrfloApiPlatformTypescriptGeneratorExtension();
    }
}
