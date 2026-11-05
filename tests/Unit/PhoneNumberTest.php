<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public static function validNumbers(): array
    {
        return [
            ['0911234567', '+251911234567'],
            ['0911 234 567', '+251911234567'],
            ['911234567', '+251911234567'],
            ['251911234567', '+251911234567'],
            ['+251-911-234-567', '+251911234567'],
            ['0711234567', '+251711234567'], // Safaricom Ethiopia
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_it_normalizes_ethiopian_mobile_numbers(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    public function test_it_rejects_invalid_numbers(): void
    {
        foreach (['', '12345', '0111234567', '+1 202 555 0100', '09112345678'] as $input) {
            $this->assertNull(PhoneNumber::normalize($input), $input);
        }
    }
}
