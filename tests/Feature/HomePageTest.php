<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_marketing_page_covers_the_product_and_pricing(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('drafts your replies.')
            ->assertSee('Everything that needs you, in one ranked queue.')
            ->assertSee('Programs that progress from what clients actually lift.')
            ->assertSee('Bring your clients. We’ll be honest about the rest.', false)
            ->assertSee('$39')
            ->assertSee('What a coach with 21 clients pays')
            ->assertSee('Competitor prices as published')
            ->assertSee(route('register'));
    }

    public function test_every_screenshot_on_the_page_exists(): void
    {
        $html = $this->get(route('home'))->getContent();
        preg_match_all('#/images/home/([a-z-]+\.webp)#', $html, $m);

        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $file) {
            $this->assertFileExists(public_path('images/home/'.$file));
        }
    }
}
