<?php

namespace Tests\Unit;

use App\Services\SallaAddressParser;
use PHPUnit\Framework\TestCase;

class SallaAddressParserTest extends TestCase
{
    public function test_incident_address_gives_building_postal_and_street_only(): void
    {
        $p = SallaAddressParser::parse('the address is 36 sayed abdelrahman aladwey giza fisal and postal code is 11511');

        $this->assertSame('36', $p['building_number']);
        $this->assertSame('11511', $p['postal_code']);
        $this->assertSame('sayed abdelrahman aladwey giza fisal', $p['address_line']);
        $this->assertNull($p['block']);
        $this->assertNull($p['district']);
    }

    public function test_labelled_block_and_district_are_extracted(): void
    {
        $p = SallaAddressParser::parse('block 5, district nasr city, postal code 11511, 12 tahrir street');

        $this->assertSame('5', $p['block']);
        $this->assertSame('nasr city', $p['district']);
        $this->assertSame('11511', $p['postal_code']);
    }

    public function test_unlabelled_text_never_invents_block_or_district(): void
    {
        $p = SallaAddressParser::parse('36 sayed abdelrahman giza');

        $this->assertNull($p['block']);
        $this->assertNull($p['district']);
    }

    public function test_fill_missing_keeps_values_already_saved(): void
    {
        $state = SallaAddressParser::fillMissing([
            'address' => '36 sayed abdelrahman giza fisal postal code 11511',
            'postal_code' => '99999',
        ]);

        $this->assertSame('99999', $state['postal_code']);
        $this->assertSame('36', $state['building_number']);
    }
}