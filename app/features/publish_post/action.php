<?php
declare(strict_types=1);

namespace App\Features\PublishPost;

use Forge\Auth\AuthContext;
use Forge\Feature\FeatureAction;
use Forge\Feature\FeatureServices;
use Forge\Http\RequestContext;

final class Action implements FeatureAction
{
    public function handle(array $input, RequestContext $request, AuthContext $auth, FeatureServices $services): array
    {
        $userId = $auth->userId();
        if ($userId === null) {
            throw new \RuntimeException('Authenticated user not found.');
        }

        $status = (($input['publish_now'] ?? false) === true) ? 'published' : 'draft';
        $postId = bin2hex(random_bytes(16));
        $createdAt = gmdate('c');

        $services->events()->emit('post.created', [
            'post_id' => $postId,
            'author_id' => $userId,
            'status' => $status,
        ]);

        $services->jobs()->dispatch('notify_followers', [
            'post_id' => $postId,
        ]);

        return [
            'id' => $postId,
            'title' => (string) $input['title'],
            'slug' => (string) $input['slug'],
            'status' => $status,
            'created_at' => $createdAt,
        ];
    }
}
