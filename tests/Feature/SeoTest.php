<?php

it('redirects old root docs urls to the docs', function (): void {
    $this->get('/introduction')->assertMovedPermanently()->assertRedirect('/docs/introduction.html');
    $this->get('/how-to-track-a-custom-event')->assertRedirect('/docs/how-to-track-a-custom-event.html');
});

it('keeps auth pages out of the search index in production', function (): void {
    config(['app.app_type' => 'saas']);
    app()['env'] = 'production';

    $this->get('/login')->assertOk()->assertSee('<meta name="robots" content="noindex">', false);
    $this->get('/imprint')->assertOk()->assertDontSee('name="robots"', false);
});
