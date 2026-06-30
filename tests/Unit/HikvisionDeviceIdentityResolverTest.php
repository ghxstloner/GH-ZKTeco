<?php

namespace Tests\Unit;

use App\Services\Hikvision\HikvisionDeviceIdentityResolver;
use Tests\TestCase;

class HikvisionDeviceIdentityResolverTest extends TestCase
{
    public function test_it_normalizes_padded_device_ids(): void
    {
        $identity = HikvisionDeviceIdentityResolver::resolve('00027001');

        $this->assertNotNull($identity);
        $this->assertSame('27001', $identity->canonicalDeviceId);
        $this->assertSame('27', $identity->empresaCodigo);
        $this->assertSame(1, $identity->deviceSequence);
    }

    public function test_it_normalizes_unpadded_device_ids(): void
    {
        $identity = HikvisionDeviceIdentityResolver::resolve('27001');

        $this->assertNotNull($identity);
        $this->assertSame('27001', $identity->canonicalDeviceId);
        $this->assertSame('27', $identity->empresaCodigo);
        $this->assertSame(1, $identity->deviceSequence);
    }

    public function test_it_normalizes_larger_company_codes(): void
    {
        $padded = HikvisionDeviceIdentityResolver::resolve('00152002');
        $plain = HikvisionDeviceIdentityResolver::resolve('152002');

        $this->assertSame('152002', $padded->canonicalDeviceId);
        $this->assertSame('152', $padded->empresaCodigo);
        $this->assertSame(2, $padded->deviceSequence);
        $this->assertSame('152002', $plain->canonicalDeviceId);
        $this->assertSame('152', $plain->empresaCodigo);
        $this->assertSame(2, $plain->deviceSequence);
    }

    public function test_it_rejects_sequence_zero(): void
    {
        $this->assertNull(HikvisionDeviceIdentityResolver::resolve('27000'));
    }

    public function test_it_rejects_company_mismatch(): void
    {
        $this->assertNull(HikvisionDeviceIdentityResolver::resolve('27001', '28'));
    }

    public function test_it_normalizes_mac_addresses(): void
    {
        $this->assertSame('88de39375b0a', HikvisionDeviceIdentityResolver::normalizeMacAddress('88:de:39:37:5b:0a'));
        $this->assertSame('mac:88de39375b0a', HikvisionDeviceIdentityResolver::physicalDeviceKey('88:de:39:37:5b:0a'));
    }
}
