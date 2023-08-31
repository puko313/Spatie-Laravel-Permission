<?php

namespace Spatie\Permission;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Contracts\Role as RoleContract;

class PermissionServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->offerPublishing();

        $this->registerMacroHelpers();

        $this->registerCommands();

        $this->registerModelBindings();

        $this->callAfterResolving(Gate::class, function (Gate $gate, Application $app) {
            if ($this->app->config['permission.register_permission_check_method']) {
                /** @var PermissionRegistrar $permissionLoader */
                $permissionLoader = $app->get(PermissionRegistrar::class);
                $permissionLoader->clearPermissionsCollection();
                $permissionLoader->registerPermissions($gate);
            }
        });

        $this->app->singleton(PermissionRegistrar::class);
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/permission.php',
            'permission'
        );

        $this->callAfterResolving('blade.compiler', function (BladeCompiler $bladeCompiler) {
            $this->registerBladeExtensions($bladeCompiler);
        });
    }

    protected function offerPublishing()
    {
        if (! function_exists('config_path')) {
            // function not available and 'publish' not relevant in Lumen
            return;
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/permission.php' => config_path('permission.php'),
        ], 'permission-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_permission_tables.php.stub' => $this->getMigrationFileName('create_permission_tables.php'),
        ], 'permission-migrations');
    }

    protected function registerCommands()
    {
        $this->commands([
            Commands\CacheReset::class,
            Commands\CreateRole::class,
            Commands\CreatePermission::class,
            Commands\Show::class,
            Commands\UpgradeForTeams::class,
        ]);
    }

    protected function registerModelBindings()
    {
        $this->app->bind(PermissionContract::class, function ($app) {
            $config = $app->config['permission.models'];

            return $app->make($config['permission']);
        });
        $this->app->bind(RoleContract::class, function ($app) {
            $config = $app->config['permission.models'];

            return $app->make($config['role']);
        });
    }

    public static function bladeMethodWrapper($method, $role, $guard = null)
    {
        return auth($guard)->check() && auth($guard)->user()->{$method}($role);
    }

    protected function registerBladeExtensions($bladeCompiler)
    {
        $bladeCompiler->directive('role', function ($arguments) {
            return "<?php if(\\Spatie\\Permission\\PermissionServiceProvider::bladeMethodWrapper('hasRole', {$arguments})): ?>";
        });
        $bladeCompiler->directive('elserole', function ($arguments) {
            return "<?php elseif(\\Spatie\\Permission\\PermissionServiceProvider::bladeMethodWrapper('hasRole', {$arguments})): ?>";
        });
        $bladeCompiler->directive('endrole', function () {
            return '<?php endif; ?>';
        });

        $bladeCompiler->directive('hasrole', function ($arguments) {
            return "<?php if(\\Spatie\\Permission\\PermissionServiceProvider::bladeMethodWrapper('hasRole', {$arguments})): ?>";
        });
        $bladeCompiler->directive('endhasrole', function () {
            return '<?php endif; ?>';
        });

        $bladeCompiler->directive('hasanyrole', function ($arguments) {
            return "<?php if(\\Spatie\\Permission\\PermissionServiceProvider::bladeMethodWrapper('hasAnyRole', {$arguments})): ?>";
        });
        $bladeCompiler->directive('endhasanyrole', function () {
            return '<?php endif; ?>';
        });

        $bladeCompiler->directive('hasallroles', function ($arguments) {
            return "<?php if(\\Spatie\\Permission\\PermissionServiceProvider::bladeMethodWrapper('hasAllRoles', {$arguments})): ?>";
        });
        $bladeCompiler->directive('endhasallroles', function () {
            return '<?php endif; ?>';
        });

        $bladeCompiler->directive('unlessrole', function ($arguments) {
            return "<?php if(! \\Spatie\\Permission\\PermissionServiceProvider::bladeMethodWrapper('hasRole', {$arguments})): ?>";
        });
        $bladeCompiler->directive('endunlessrole', function () {
            return '<?php endif; ?>';
        });

        $bladeCompiler->directive('hasexactroles', function ($arguments) {
            return "<?php if(\\Spatie\\Permission\\PermissionServiceProvider::bladeMethodWrapper('hasExactRoles', {$arguments})): ?>";
        });
        $bladeCompiler->directive('endhasexactroles', function () {
            return '<?php endif; ?>';
        });
    }

    protected function registerMacroHelpers()
    {
        if (! method_exists(Route::class, 'macro')) { // Lumen
            return;
        }

        Route::macro('role', function ($roles = []) {
            $roles = implode('|', Arr::wrap($roles));

            $this->middleware("role:$roles");

            return $this;
        });

        Route::macro('permission', function ($permissions = []) {
            $permissions = implode('|', Arr::wrap($permissions));

            $this->middleware("permission:$permissions");

            return $this;
        });
    }

    /**
     * Returns existing migration file if found, else uses the current timestamp.
     */
    protected function getMigrationFileName($migrationFileName): string
    {
        $timestamp = date('Y_m_d_His');

        $filesystem = $this->app->make(Filesystem::class);

        return Collection::make($this->app->databasePath().DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR)
            ->flatMap(function ($path) use ($filesystem, $migrationFileName) {
                return $filesystem->glob($path.'*_'.$migrationFileName);
            })
            ->push($this->app->databasePath()."/migrations/{$timestamp}_{$migrationFileName}")
            ->first();
    }
}
