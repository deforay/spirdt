<?php

declare(strict_types=1);

namespace App\Bootstrap;

use DI\Container;

/**
 * A domain-grouped set of container bindings, walked once at boot.
 *
 * Add a new service by extending the matching provider — never reach back
 * into Bootstrap to set() a binding inline. One place per domain keeps the
 * wiring greppable.
 */
interface ServiceProvider
{
    public static function register(Container $container): void;
}
