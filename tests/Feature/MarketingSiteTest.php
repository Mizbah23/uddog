<?php

namespace Tests\Feature;

use Tests\TestCase;

class MarketingSiteTest extends TestCase
{
    public function test_central_homepage_renders_indexable_marketing_content(): void
    {
        config([
            'tenancy.enabled' => true,
            'tenancy.base_domain' => 'test',
            'tenancy.central_host' => 'test',
            'marketing.url' => 'https://uddog-soft.com',
        ]);

        $response = $this->get('http://test/');

        $response
            ->assertOk()
            ->assertViewIs('marketing.home')
            ->assertSee('<html lang="bn">', false)
            ->assertSee('<title>Uddog | ব্যাবসা পরিচালনার সফটওয়্যার</title>', false)
            ->assertSee('আপনার ব্যবসা চলুক')
            ->assertSee('Starter')
            ->assertSee('Standard')
            ->assertSee('Premium')
            ->assertSee('৳২০,০০০')
            ->assertSee('৳৩০,০০০')
            ->assertSee('৳৫০,০০০')
            ->assertSee('বিস্তারিত জানুন')
            ->assertSee('মেসেজ দিন')
            ->assertSee('https://wa.me/8801630066059?text=', false)
            ->assertSee('uddog-soft-logo.png', false)
            ->assertDontSee('Open your workspace')
            ->assertDontSee('href="http://test/app"', false)
            ->assertSee('<link rel="canonical" href="https://uddog-soft.com/">', false)
            ->assertSee('"@context":"https://schema.org"', false);

        $this->assertSame(3, substr_count($response->getContent(), 'class="plan-contact"'));
    }

    public function test_workspace_stays_available_at_app_path_and_is_not_indexable(): void
    {
        config([
            'tenancy.enabled' => true,
            'tenancy.base_domain' => 'test',
            'tenancy.central_host' => 'test',
        ]);

        $this->get('http://test/app')
            ->assertOk()
            ->assertViewIs('app')
            ->assertSee('noindex,nofollow');
    }

    public function test_workspace_pages_have_direct_hash_free_urls(): void
    {
        config([
            'tenancy.enabled' => true,
            'tenancy.base_domain' => 'test',
            'tenancy.central_host' => 'test',
        ]);

        foreach (['dashboard', 'purchase', 'sale', 'reports', 'clients', 'profile'] as $page) {
            $this->get("http://test/{$page}")
                ->assertOk()
                ->assertViewIs('app');
        }

        $this->get('http://test/unknown-workspace-page')->assertNotFound();
    }

    public function test_www_homepage_redirects_to_the_canonical_domain(): void
    {
        config([
            'tenancy.enabled' => true,
            'tenancy.base_domain' => 'test',
            'tenancy.central_host' => 'test',
            'marketing.url' => 'https://uddog-soft.com',
        ]);

        $this->get('http://www.test/')
            ->assertStatus(301)
            ->assertRedirect('https://uddog-soft.com/');
    }

    public function test_sitemap_lists_public_homepage_and_hides_it_on_tenant_hosts(): void
    {
        config([
            'tenancy.enabled' => true,
            'tenancy.base_domain' => 'test',
            'tenancy.central_host' => 'test',
            'marketing.url' => 'https://uddog-soft.com',
        ]);

        $this->get('http://test/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>https://uddog-soft.com/</loc>', false);

        $this->get('http://demo.test/sitemap.xml')->assertNotFound();
    }

    public function test_public_robots_file_keeps_workspace_paths_out_of_crawlers(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Disallow: /app', $robots);
        $this->assertStringContainsString('Disallow: /api/', $robots);
        $this->assertStringContainsString('Sitemap: https://uddog-soft.com/sitemap.xml', $robots);
    }

    public function test_public_pricing_compares_package_limits_and_separates_renewal_fees_and_paid_ecommerce_addon(): void
    {
        config(['tenancy.enabled' => false]);
        $this->get('/')->assertOk()->assertSeeInOrder([
            'Starter', '৳২০,০০০', '৳৪,০০০', '১টি', '২ জন', '১,০০০টি',
            'Standard', '৳৩০,০০০', '৳৭,০০০', '২টি', '৫ জন', '৫,০০০টি',
            'Premium', '৳৫০,০০০', '৳১২,০০০', '৫টি', '২০ জন', 'সীমাহীন',
        ])->assertSee('বার্ষিক নবায়ন ফি')
            ->assertSee('ই-কমার্স মার্কেটপ্লেস মূল প্যাকেজের অন্তর্ভুক্ত নয়।')
            ->assertSee('ঐচ্ছিক পেইড অ্যাড-অন · সব প্যাকেজের সঙ্গে')
            ->assertSee('অ্যাড-অনের মূল্য জানুন')
            ->assertSee('ক্যাশ অন ডেলিভারি অর্ডার অনুরোধ')
            ->assertSee(rawurlencode('আসসালামু আলাইকুম, আমার ব্যবসার জন্য নিজস্ব ই-কমার্স মার্কেটপ্লেস অ্যাড-অনের মূল্য ও বিস্তারিত জানতে চাই।'), false);
    }
}
