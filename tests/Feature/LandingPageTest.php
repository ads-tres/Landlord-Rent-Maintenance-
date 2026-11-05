<?php

namespace Tests\Feature;

use App\Models\WaitlistSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_renders_in_amharic_and_english(): void
    {
        $this->get('/?lang=am')->assertOk()->assertSee('ኪራይ፣ ውል እና ጥገና', false);
        $this->get('/?lang=en')->assertOk()->assertSee('Rent, leases and repairs');
    }

    public function test_landing_page_stays_small(): void
    {
        $html = $this->get('/?lang=am')->getContent();

        $this->assertLessThan(100 * 1024, strlen($html));
    }

    public function test_visitor_can_join_the_waitlist(): void
    {
        $this->post('/waitlist', [
            'phone' => '0911 234 567',
            'unit_count' => 4,
            'current_tracking' => 'notebook',
            'biggest_problem' => 'Late rent',
            'monthly_price_birr' => 200,
            'source' => 'telegram-1',
        ])->assertRedirect()->assertSessionHas('joined', true);

        $this->assertDatabaseHas('waitlist_signups', [
            'phone' => '+251911234567',
            'unit_count' => 4,
            'monthly_price_birr' => 200,
            'source' => 'telegram-1',
        ]);
    }

    public function test_signing_up_twice_updates_the_same_row(): void
    {
        $this->post('/waitlist', ['phone' => '0911234567', 'unit_count' => 2]);
        $this->post('/waitlist', ['phone' => '+251911234567', 'unit_count' => 5]);

        $this->assertSame(1, WaitlistSignup::query()->count());
        $this->assertSame(5, (int) WaitlistSignup::query()->value('unit_count'));
    }

    public function test_waitlist_validates_input(): void
    {
        $this->post('/waitlist', ['phone' => 'abc'])->assertSessionHasErrors(['phone', 'unit_count']);
        $this->post('/waitlist', ['phone' => '0911234567', 'unit_count' => 2, 'website' => 'spam'])
            ->assertSessionHasErrors('website');

        $this->assertSame(0, WaitlistSignup::query()->count());
    }
}
