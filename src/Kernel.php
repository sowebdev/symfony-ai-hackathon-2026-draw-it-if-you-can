<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @return list<string> An array of allowed values for APP_ENV; smoke: the browser test against a scripted model (make smoke)
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test', 'smoke'];
    }
}
