<?php

declare(strict_types=1);

namespace Onelo\Tests\Features;

use Onelo\Features\ArrayStore;
use Onelo\Features\FileStore;
use Onelo\Features\SnapshotStore;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_file($this->tmp)) {
            @unlink($this->tmp);
        }
    }

    /** @return array<string, SnapshotStore> */
    private function stores(): array
    {
        $this->tmp = sys_get_temp_dir() . '/onelo_feat_' . uniqid('', true) . '.json';

        return ['array' => new ArrayStore(), 'file' => new FileStore($this->tmp)];
    }

    public function testEmptyStoreFailsClosed(): void
    {
        foreach ($this->stores() as $kind => $store) {
            self::assertSame(0, $store->configVersion(), $kind);
            self::assertSame('hidden', $store->getStatus('anything'), $kind);
            self::assertSame(['status' => 'hidden'], $store->getState('anything'), $kind);
            self::assertSame([], $store->snapshot(), $kind);
        }
    }

    public function testReplaceAllAndRead(): void
    {
        foreach ($this->stores() as $kind => $store) {
            $store->replaceAll([
                'export' => ['status' => 'upsell', 'required_plan_label' => 'Pro'],
                'beta-x' => ['status' => 'beta'],
            ], 7);
            self::assertSame(7, $store->configVersion(), $kind);
            self::assertSame('upsell', $store->getStatus('export'), $kind);
            self::assertSame('Pro', $store->getState('export')['required_plan_label'], $kind);
            self::assertSame(['export' => 'upsell', 'beta-x' => 'beta'], $store->snapshot(), $kind);
        }
    }

    public function testReplaceAllNormalizesBareStrings(): void
    {
        foreach ($this->stores() as $kind => $store) {
            $store->replaceAll(['flag' => 'enabled'], 1);
            self::assertSame(['status' => 'enabled'], $store->getState('flag'), $kind);
            self::assertSame('enabled', $store->getStatus('flag'), $kind);
        }
    }

    public function testFileStoreCrossInstanceVisibility(): void
    {
        // The whole point: a SEPARATE reader (the FPM worker) sees what a writer
        // (the listener process) persisted.
        $this->tmp = sys_get_temp_dir() . '/onelo_feat_' . uniqid('', true) . '.json';
        $writer = new FileStore($this->tmp);
        $writer->replaceAll(['x' => ['status' => 'enabled']], 3);

        $reader = new FileStore($this->tmp); // fresh instance = different "process"
        self::assertSame(3, $reader->configVersion());
        self::assertSame('enabled', $reader->getStatus('x'));
    }

    public function testFileStoreToleratesCorruptFile(): void
    {
        $this->tmp = sys_get_temp_dir() . '/onelo_feat_' . uniqid('', true) . '.json';
        file_put_contents($this->tmp, '{not valid json');
        $store = new FileStore($this->tmp);
        self::assertSame(0, $store->configVersion());       // fail-closed, no throw
        self::assertSame('hidden', $store->getStatus('x'));
    }
}
