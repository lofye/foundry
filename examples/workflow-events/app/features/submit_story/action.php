<?php
declare(strict_types=1);

namespace App\Features\SubmitStory;

use Foundry\Auth\AuthContext;
use Foundry\Feature\FeatureAction;
use Foundry\Feature\FeatureServices;
use Foundry\Http\RequestContext;

final class Action implements FeatureAction
{
    #[\Override]
    public function handle(array $input, RequestContext $request, AuthContext $auth, FeatureServices $services): array
    {
        $services->events()->emit('story.submitted', [
            'title' => (string) ($input['title'] ?? ''),
        ]);

        return [
            'feature' => 'submit_story',
            'state' => 'review',
            'event' => 'story.submitted',
        ];
    }
}
