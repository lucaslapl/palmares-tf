<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CountryFlagTest extends TestCase
{
    #[Test]
    public function builds_etf2l_flag_url_for_country_names(): void
    {
        $this->assertSame(
            'https://etf2l.org/images/flags/Netherlands.gif',
            country_flag_url('Netherlands'),
        );
        $this->assertSame(
            'https://etf2l.org/images/flags/European.gif',
            country_flag_url('European'),
        );
        $this->assertSame(
            'https://etf2l.org/images/flags/United%20States.gif',
            country_flag_url('United States'),
        );
        $this->assertSame(
            'https://etf2l.org/images/flags/AU.gif',
            country_flag_url('AU'),
        );
    }

    #[Test]
    public function returns_null_for_invalid_values(): void
    {
        $this->assertNull(country_flag_url(''));
        $this->assertNull(country_flag_url('   '));
        $this->assertNull(country_flag_url('République;</script>'));
        $this->assertNull(country_flag_url("Pays\nSecond Line"));
    }
}
