<?php

namespace GenAI\Boot;

use GenAI\Attribute\Context;
use GenAI\Attribute\Scanner;

/**
 * The BUILD-TIME (PHP 8) half of the kernel — Composer-based package
 * auto-discovery, Laravel style. Kept separate from the runtime Kernel so the
 * two never mix: this file is only ever loaded by the build step (the
 * vendor/bin/genai-compile CLI), never on the PHP 5.3 runtime.
 *
 * Each component declares its build-time integration in its composer.json:
 *
 *   "extra": { "genai": { "processors": ["GenAI\\Web\\Processor\\RouteProcessor"] } }
 *
 * compile() reads vendor/composer/installed.json, collects those processors from
 * EVERY installed package, and registers them — so adding a feature is just
 * `composer require genai/...`; the build script never changes and never names a
 * processor namespace. (Same idea as Laravel reading extra.laravel.providers.)
 *
 * Bundles (the auto-configure pattern) go one step further: a package that wires
 * up a library declares the namespace holding its #[Configuration]/#[Bean] under
 *
 *   "extra": { "genai": { "scan": ["GenAI\\Bundle\\Pdo"] } }
 *
 * and compile() scans those too (before the app), so the library's beans land in
 * the container with no app wiring.
 *
 *   $compiler = new Compiler(__DIR__, $loader);  // or via Kernel::compile()
 *   $compiler->compile(array('App'));
 *
 * Requires the genai/attribute scanner (PHP 8). The files it writes are PHP
 * 5.3-safe; the runtime Kernel loads those.
 */
class Compiler
{
    /** @var string project root (holds vendor/, cache/, config/) */
    private $root;

    /** @var string */
    private $cacheDir;

    /** @var string */
    private $configDir;

    /** @var string */
    private $vendorDir;

    /** @var \Composer\Autoload\ClassLoader|null */
    private $loader;

    /**
     * @param string $root   project root directory
     * @param object $loader optional Composer ClassLoader (else loaded from vendor/)
     */
    public function __construct($root, $loader = null)
    {
        $this->root      = rtrim($root, '/\\');
        $this->cacheDir  = $this->root . '/cache';
        $this->configDir = $this->root . '/config';
        $this->vendorDir = $this->root . '/vendor';
        $this->loader    = $loader;
    }

    /**
     * Auto-register every installed component's processors, scan the bundle and
     * app namespaces for targets, and write the compiled files.
     *
     * @param array $appNamespaces namespaces holding the app's annotated classes
     * @param array $parameters    flat config for #[Value('${...}')], optional
     * @return void
     */
    public function compile($appNamespaces, $parameters = array())
    {
        if ($this->loader === null) {
            $this->loader = require $this->vendorDir . '/autoload.php';
        }

        // Ensure the cache dir exists and is writable, with a clear error if not
        // (the processors' file_put_contents would otherwise fail opaquely).
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0777, true) && !is_dir($this->cacheDir)) {
            throw new \RuntimeException('Cannot create cache directory "' . $this->cacheDir . '".');
        }
        if (!is_writable($this->cacheDir)) {
            throw new \RuntimeException('Cache directory "' . $this->cacheDir . '" is not writable.');
        }

        $scanner = new Scanner($this->loader);
        foreach ($this->discoverProcessors() as $processorClass) {
            $scanner->addProcessor(new $processorClass());
        }

        // Bundles (extra.genai.scan) are scanned BEFORE the app, so an app's own
        // bean defining the same id collides loudly with a bundle default rather
        // than silently shadowing it.
        $namespaces = array_merge($this->discoverScanNamespaces(), $appNamespaces);
        $scanner->scan($namespaces);
        $scanner->compile(new Context($this->configDir, $this->cacheDir, $parameters));

        $this->writeSystem();
    }

    /**
     * Write cache/System.php — a tiny Cache\System value object that centralizes the
     * runtime values the Kernel would otherwise hold as magic strings:
     *   - the templates directory (resolved relative to THIS file, so the compiled
     *     cache/ stays portable from the build box (PHP 8) to the runtime (PHP 5.3)
     *     even if the absolute path differs, and lets the runtime Kernel drop its
     *     $root constructor argument),
     *   - the compiled serializer class (Cache\Dto) — or null when genai/dto isn't
     *     installed, so the Kernel needs no class_exists() probe,
     *   - the AppProperty bean id, for the HTTP options lookup.
     *
     * Whether a serializer exists is known here: the DtoProcessor (a suggest) has
     * already run by now, so we bake the class name only if it produced Dto.php.
     *
     * @return void
     */
    private function writeSystem()
    {
        $serializer = is_file($this->cacheDir . '/Dto.php') ? "'Cache\\\\Dto'" : 'null';

        // Container registrars (loadInto classes), baked so the Kernel never names
        // them as strings. Cache\Properties is always present (genai/property is a
        // hard dependency); Cache\Mappers only when genai/sql-mapper compiled one.
        $loaders = array("'Cache\\\\Properties'");
        if (is_file($this->cacheDir . '/Mappers.php')) {
            $loaders[] = "'Cache\\\\Mappers'";
        }
        $containerLoaders = 'array(' . implode(', ', $loaders) . ')';

        // Runtime "fronts": every installed component's extra.genai.autoconfig class.
        // Sorted by priority() HERE, at build time (highest first), so the runtime
        // Kernel needs no usort — it just tries them in order and takes the first whose
        // supports() matches. priority() is a fixed property of the class (each front is
        // no-arg constructible and priority() is pure); supports() stays per-invocation.
        $classes = $this->discoverExtra('autoconfig');
        usort($classes, function ($a, $b) {
            $pa = (int) (new $a())->priority();
            $pb = (int) (new $b())->priority();
            return $pb - $pa;
        });
        $fronts = array();
        foreach ($classes as $class) {
            $fronts[] = "'" . str_replace('\\', '\\\\', $class) . "'";
        }
        $autoConfigs = 'array(' . implode(', ', $fronts) . ')';

        $source = "<?php\n\n"
            . "namespace Cache;\n\n"
            . "// Generated by GenAI\\Boot\\Compiler - do not edit by hand.\n"
            . "// Runtime values the Kernel needs, centralized so they aren't magic\n"
            . "// strings in the runtime. Paths resolve relative to this file\n"
            . "// (dirname(__DIR__) = project root) so the compiled cache/ is portable.\n\n"
            . "class System\n"
            . "{\n"
            . "    private \$templates;\n"
            . "    private \$serializerClass;\n"
            . "    private \$appPropertyId;\n"
            . "    private \$containerLoaders;\n"
            . "    private \$autoConfigs;\n\n"
            . "    public function __construct()\n"
            . "    {\n"
            . "        \$this->templates        = dirname(__DIR__) . '/templates';\n"
            . "        \$this->serializerClass  = " . $serializer . ";\n"
            . "        \$this->appPropertyId    = 'GenAI\\\\Boot\\\\AppProperty';\n"
            . "        \$this->containerLoaders = " . $containerLoaders . ";\n"
            . "        \$this->autoConfigs      = " . $autoConfigs . ";\n"
            . "    }\n\n"
            . "    public function getTemplates()\n"
            . "    {\n"
            . "        return \$this->templates;\n"
            . "    }\n\n"
            . "    public function getSerializerClass()\n"
            . "    {\n"
            . "        return \$this->serializerClass;\n"
            . "    }\n\n"
            . "    public function getAppPropertyId()\n"
            . "    {\n"
            . "        return \$this->appPropertyId;\n"
            . "    }\n\n"
            . "    public function getContainerLoaders()\n"
            . "    {\n"
            . "        return \$this->containerLoaders;\n"
            . "    }\n\n"
            . "    public function getAutoConfigs()\n"
            . "    {\n"
            . "        return \$this->autoConfigs;\n"
            . "    }\n"
            . "}\n";

        $path  = $this->cacheDir . '/System.php';
        $bytes = @file_put_contents($path, $source);
        if ($bytes === false) {
            throw new \RuntimeException('Could not write system info to "' . $path . '".');
        }
    }

    /**
     * Processor class names declared by every installed package under
     * extra.genai.processors (e.g. RouteProcessor, ComponentProcessor).
     *
     * @return array unique list of processor FQCNs
     */
    public function discoverProcessors()
    {
        return $this->discoverExtra('processors');
    }

    /**
     * Namespaces declared by installed bundles under extra.genai.scan — packages
     * that ship #[Configuration]/#[Bean] wiring for a library (the "bundle" /
     * auto-configure pattern).
     *
     * @return array unique list of namespaces
     */
    public function discoverScanNamespaces()
    {
        return $this->discoverExtra('scan');
    }

    /**
     * Read a string-list from extra.genai.<key> across every installed package.
     *
     * @param string $key
     * @return array unique values, in install order
     * @throws \RuntimeException If installed.json is missing.
     */
    private function discoverExtra($key)
    {
        $file = $this->vendorDir . '/composer/installed.json';
        if (!is_file($file)) {
            throw new \RuntimeException(
                'Cannot find "' . $file . '". Run `composer install` before compiling.'
            );
        }

        $data = json_decode(file_get_contents($file), true);
        // Composer 2 wraps packages in a "packages" key; Composer 1 is a flat list.
        $packages = isset($data['packages']) ? $data['packages'] : $data;

        $values = array();
        foreach ($packages as $package) {
            if (isset($package['extra']['genai'][$key])) {
                foreach ($package['extra']['genai'][$key] as $value) {
                    $values[$value] = true; // key-dedupe across packages
                }
            }
        }

        return array_keys($values);
    }
}
