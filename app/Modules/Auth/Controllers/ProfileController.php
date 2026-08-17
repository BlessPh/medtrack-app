<?php

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Requests\UpdateProfileRequest;
use App\Modules\Auth\Resources\UserResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProfileController
{
    public function update(UpdateProfileRequest $request): UserResource
    {
        $data = $request->validated();
        $userFields = Arr::only($data, ['name', 'phone']);
        $profileFields = Arr::except($data, ['name', 'phone']);

        DB::transaction(function () use ($request, $userFields, $profileFields): void {
            $request->user()->update($userFields);
            $request->user()->profile()->updateOrCreate([], $profileFields);
        });

        return new UserResource($request->user()->fresh()->load('profile'));
    }
}
