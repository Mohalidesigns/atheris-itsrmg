<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * In Inertia 2.3 `form.transform()` returns undefined, so `form.transform(...).post(...)`
 * throws and the form never submits (P2 build, Pages/Ea/Surveys.jsx in P2.1).
 */
class InertiaTransformChainTest extends TestCase
{
    public function test_no_page_chains_a_call_onto_form_transform(): void
    {
        $offenders = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js')));

        foreach ($files as $file) {
            if (! in_array($file->getExtension(), ['js', 'jsx'], true)) {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            $offset = 0;
            while (($pos = strpos($src, '.transform(', $offset)) !== false) {
                $i = $pos + strlen('.transform(');
                $depth = 1;
                for ($len = strlen($src); $i < $len && $depth > 0; $i++) {
                    $depth += $src[$i] === '(' ? 1 : ($src[$i] === ')' ? -1 : 0);
                }
                if (preg_match('/\G\s*\./', $src, $m, 0, $i)) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.(substr_count($src, "\n", 0, $pos) + 1);
                }
                $offset = $pos + 1;
            }
        }

        $this->assertSame([], $offenders, 'form.transform() returns undefined in Inertia 2.3; call it as its own statement.');
    }
}
