<?php
// Loads every class in src/ and reports anything it references that cannot be resolved.
// Run: php tests/resolve.php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

// The namespace comes out of the file rather than off the path, because src/ holds two of them:
// Framework\ for what you cloned and App\ for what you wrote, interleaved in the same directories.
// Deriving it from the path would mean guessing a prefix, and guessing wrong reads as "class not
// found at its PSR-4 path" -- the exact message you would then go looking for a real bug behind.
$root = dirname(__DIR__) . '/src';
$classes = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') continue;
    $source = (string) file_get_contents($file->getPathname());
    if (!preg_match('/^namespace\s+([^;]+);/m', $source, $m)) continue;
    $classes[] = trim($m[1]) . '\\' . $file->getBasename('.php');
}
sort($classes);

$problems = [];
foreach ($classes as $class) {
    try {
        if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
            $problems[] = "$class :: not found at its PSR-4 path";
            continue;
        }
        $rc = new ReflectionClass($class);

        // Constructor + method parameter types.
        foreach ($rc->getMethods() as $m) {
            if ($m->getDeclaringClass()->getName() !== $class) continue;
            foreach ($m->getParameters() as $p) {
                $t = $p->getType();
                if ($t instanceof ReflectionNamedType && !$t->isBuiltin()) {
                    $n = $t->getName();
                    if (!in_array($n, ['self', 'static', 'parent'], true)
                        && !class_exists($n) && !interface_exists($n) && !enum_exists($n)) {
                        $problems[] = "$class::{$m->getName()}(\${$p->getName()}) :: unknown type $n";
                    }
                }
            }
            $rt = $m->getReturnType();
            if ($rt instanceof ReflectionNamedType && !$rt->isBuiltin()) {
                $n = $rt->getName();
                if ($n !== 'static' && $n !== 'self' && $n !== 'parent' && !class_exists($n) && !interface_exists($n) && !enum_exists($n)) {
                    $problems[] = "$class::{$m->getName()}() :: unknown return type $n";
                }
            }
        }

        // Typed property types.
        foreach ($rc->getProperties() as $p) {
            if ($p->getDeclaringClass()->getName() !== $class) continue;
            $t = $p->getType();
            if ($t instanceof ReflectionNamedType && !$t->isBuiltin()) {
                $n = $t->getName();
                if (!class_exists($n) && !interface_exists($n) && !enum_exists($n)) {
                    $problems[] = "$class::\${$p->getName()} :: unknown type $n";
                }
            }
        }

        if ($parent = $rc->getParentClass()) { /* loading it already proved it resolves */ }
    } catch (\Throwable $e) {
        $problems[] = "$class :: " . get_class($e) . ': ' . $e->getMessage();
    }
}

echo count($classes) . " classes loaded\n";
if ($problems === []) {
    echo "OK - every referenced type resolves\n";
    exit(0);
}
echo count($problems) . " problem(s):\n";
foreach ($problems as $p) echo "  - $p\n";
exit(1);
