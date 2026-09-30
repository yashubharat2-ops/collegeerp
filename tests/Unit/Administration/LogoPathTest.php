<?php

namespace Tests\Unit\Administration;

use App\Services\Settings\InstitutionalSettingsService;
use PHPUnit\Framework\TestCase;

class LogoPathTest extends TestCase
{
    public function test_only_server_generated_paths_in_the_same_college_branding_directory_are_allowed(): void
    {
        $service = new InstitutionalSettingsService;
        $name = str_repeat('a', 40);
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $extension) {
            $this->assertTrue($service->isLogoPath('colleges/7/branding/'.$name.'.'.$extension, 7));
        }
        foreach ([
            null, '', 'colleges/8/branding/'.$name.'.png',
            'colleges/7/branding/../'.$name.'.png',
            'colleges/7/branding/'.$name.'.svg',
            'colleges/7/branding/'.$name.'.php',
            'colleges/7/branding/'.$name.'.png/secret',
            'colleges/7/branding/'.$name.".png\n",
            'https://example.org/'.$name.'.png',
        ] as $path) {
            $this->assertFalse($service->isLogoPath($path, 7));
        }
    }
}
