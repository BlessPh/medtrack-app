<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{publicId}', fn ($user, string $publicId): bool => hash_equals($user->public_id, $publicId), ['guards' => ['api']]);
