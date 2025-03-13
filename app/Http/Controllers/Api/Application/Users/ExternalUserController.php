<?php

namespace App\Http\Controllers\Api\Application\Users;

use App\Models\User;
use App\Transformers\Api\Application\UserTransformer;
use App\Http\Controllers\Api\Application\ApplicationApiController;
use App\Http\Requests\Api\Application\Users\GetExternalUserRequest;
use Dedoc\Scramble\Attributes\Group;

#[Group('User', weight: 1)]
class ExternalUserController extends ApplicationApiController
{
    /**
     * View user (external id)
     *
     * Retrieve a specific user from the database using their external ID.
     */
    public function index(GetExternalUserRequest $request, string $external_id): array
    {
        $user = User::query()->where('external_id', $external_id)->firstOrFail();

        return $this->fractal->item($user)
            ->transformWith($this->getTransformer(UserTransformer::class))
            ->toArray();
    }
}
