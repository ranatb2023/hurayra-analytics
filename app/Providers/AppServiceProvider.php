<?php

namespace App\Providers;

use App\Services\DataFreshness;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The freshness strip sits on every page, so every page that uses the
        // app layout gets its figures. A missing table (fresh install, before
        // migrating) hides the strip rather than taking the page down.
        View::composer('layouts.app', function ($view) {
            try {
                $view->with('freshness', app(DataFreshness::class)->summary());
            } catch (Throwable) {
                $view->with('freshness', null);
            }
        });
    }
}
