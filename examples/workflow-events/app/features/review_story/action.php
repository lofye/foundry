<?php
declare(strict_types=1);

namespace App\Features\ReviewStory;

use Foundry\Auth\AuthContext;
use Foundry\Feature\FeatureAction;
use Foundry\Feature\FeatureServices;
use Foundry\Http\RequestContext;

final class Action implements FeatureAction
{
    #[\Override]
    public function handle(array $input, RequestContext $request, AuthContext $auth, FeatureServices $services): array
    {
        $services->events()->emit('story.review_requested', [
            'story_id' => (string) ($input['story_id'] ?? ''),
        ]);

        return [
            'feature' => 'review_story',
            'state' => 'review',
            'event' => 'story.review_requested',
        ];
    }
}
