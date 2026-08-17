<?php

namespace App\Providers;

use App\Models\Message;
use App\Models\Post;
use App\Observers\MessageObserver;
use App\Observers\PostObserver;
use Illuminate\Support\ServiceProvider;

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
        Post::observe(PostObserver::class);
        Message::observe(MessageObserver::class);
    }
}
