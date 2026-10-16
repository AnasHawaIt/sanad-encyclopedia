<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpaShellTest extends TestCase
{
    public function test_spa_shell_is_served_for_frontend_paths(): void
    {
        $this->withoutVite();

        $this->get('/')->assertOk()->assertSee('<div id="app"></div>', false);
        $this->get('/books')->assertOk();
    }

    public function test_unknown_api_paths_are_not_swallowed_by_the_spa(): void
    {
        $this->getJson('/api/v1/does-not-exist')->assertNotFound();
    }
}
