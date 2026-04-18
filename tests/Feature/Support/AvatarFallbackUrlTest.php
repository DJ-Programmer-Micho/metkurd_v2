<?php

use App\Support\AvatarFallbackUrl;

it('resolves a stable local avatar fallback instead of the remote userImg singleton', function () {
    $url = app(AvatarFallbackUrl::class)->customer();

    expect($url)->toContain('/admin/images/users/user-dummy-img.jpg')
        ->and($url)->not->toBe((string) app('userImg'));
});
