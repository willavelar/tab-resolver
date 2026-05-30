<?php

it('redirects root to login for unauthenticated users', function () {
    $response = $this->get('/');

    $response->assertRedirect('/login');
});
