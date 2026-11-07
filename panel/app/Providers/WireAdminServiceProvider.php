<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Where this application adopts the admin shell.
 *
 * Published and registered by `php artisan wire-admin:install`, and then it is
 * yours to edit. Nothing inside the package does what this file does: installing
 * `wire-admin` gives you a layout and a sidebar, and naming the layout — this
 * line — is what makes pages render inside them.
 *
 * The key is `component_layout`, not `layout`: Livewire 4 reads the former, and
 * the latter fails at render time as "No hint path defined for [layouts]", which
 * reads like a missing view rather than a wrong key.
 */
class WireAdminServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        config()->set('livewire.component_layout', 'components.layouts.admin');
    }
}
