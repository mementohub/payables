<?php

test('guests visiting the home page are redirected to login', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});
