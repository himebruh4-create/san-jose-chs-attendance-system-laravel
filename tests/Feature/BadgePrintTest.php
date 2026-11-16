<?php

namespace Tests\Feature;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ID badge printing (badgePrintDocument() in public/js/superadmin-personnel.js):
 * every print goes into the same reused print frame, so one print's script
 * must not break the next (it threw "Identifier 'badgeBarcodes' has already
 * been declared" on Print All after another badge print).
 */
class BadgePrintTest extends TestCase
{
    public function test_single_and_print_all_badges_print_one_after_another_in_the_same_frame(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('Node.js is needed to run public/js/superadmin-personnel.js.');
        }

        $process = new Process([$node, base_path('tests/Support/badge-print-documents.cjs')]);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true);

        $this->assertNull($result['error']);
        $this->assertSame(3, $result['printed']);
        $this->assertSame([
            ['#badge-barcode-0', '100001'],
            ['#badge-barcode-0', '100001'], ['#badge-barcode-1', '100002'], ['#badge-barcode-2', '100003'],
            ['#badge-barcode-0', '100001'], ['#badge-barcode-1', '100002'],
        ], $result['drawn']);
    }
}
