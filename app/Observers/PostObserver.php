<?php

namespace App\Observers;

use App\Models\Post;
use App\Services\FakeEngagementService;

class PostObserver
{
    public function __construct(private FakeEngagementService $fakeEngagement) {}

    /**
     * Handle the Post "created" event.
     */
    public function created(Post $post): void
    {
        $this->fakeEngagement->seedForPost($post);
    }
}
