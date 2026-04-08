<?php

test('root redirects guests to the localized landing home page', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('landing.home', ['locale' => config('app.locale')]));
});
