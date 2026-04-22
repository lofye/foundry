<?php

declare(strict_types=1);

namespace Foundry\Generate;

interface InteractiveGenerateReviewer
{
    public function review(InteractiveGenerateReviewRequest $request): InteractiveGenerateReviewResult;
}
