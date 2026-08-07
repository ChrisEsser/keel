<?php
// Loads every Keel class and reports anything it references that cannot be resolved.
// Run: php tests/resolve.php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__) . '/src';
$classes = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') continue;
    $rel = substr($file->getPathname(), strlen($root) + 1, -4);
    $classes[] = 'Framework\\' . str_replace('/', '\\', $rel);
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
