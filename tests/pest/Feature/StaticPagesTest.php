<?php

use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\withoutVite;

it('redirects the home page to the retired Data Services website', function () {
    $this->get(route('home'))->assertRedirect('https://dataservices.gfz-potsdam.de');
});

it('displays the about page', function () {
    withoutVite();
    $response = $this->get(route('about'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('about'));
});

it('displays the legal notice page', function () {
    withoutVite();
    $response = $this->get(route('legal-notice'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('legal-notice'));
});

it('displays the changelog page', function () {
    withoutVite();
    $response = $this->get(route('changelog'))->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('changelog'));
});
