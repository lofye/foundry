<?php
declare(strict_types=1);

namespace App\Features\PublishStory;

use Foundry\Auth\AuthContext;
use Foundry\Feature\FeatureAction;
use Foundry\Feature\FeatureServices;
use Foundry\Http\RequestContext;

final class Action implements FeatureAction
{
    #[\Override]
    public function handle(array $input, RequestContext $request, AuthContext $auth, FeatureServices $services): array
    {
        $services->events()->emit('story.published', [
            'story_id' => (string) ($input['story_id'] ?? ''),
        ]);
        $services->jobs()->dispatch('refresh_story_feed', [
            'story_id' => (string) ($input['story_id'] ?? ''),
        ]);

        return [
            'feature' => 'publish_story',
            'state' => 'published',
            'event' => 'story.published',
        ];
    }
}
