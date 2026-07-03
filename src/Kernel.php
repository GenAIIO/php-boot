<?php

namespace GenAI\Boot;

use GenAI\Container\Container;

/**
 * The application kernel — the RUNTIME (PHP 5.3) entry point. It loads the
 * compiled files and serves requests. The build-time half (scanning, compiling)
 * lives in GenAI\Boot\Compiler, which this file never references except through
 * the thin compile() delegate below — so nothing PHP-8-only is loaded at runtime.
 *
 * One compile feeds two runtime fronts off the same compiled container — HTTP
 * (boot/run) and CLI (console), like Symfony's HttpKernel + Console Application:
 *
 *   // build (PHP 8) — front-agnostic; produces routes.php, web.php, commands.php, ...
 *   (new Kernel())->compile(__DIR__, array('App'), $loader);   // -> delegates to Compiler
 *
 *   // runtime (PHP 5.3) — no path needed; paths come from the compiled Cache\System
 *   (new Kernel())->run();                        // HTTP: boot + dispatch + emit
 *   exit((new Kernel())->console()->run($argv));  // CLI: run a #[Command]
 */
class Kernel
{
    /** @var \GenAI\Container\Container|null the booted container (for run()) */
    private $container = null;

    /** @var \Cache\System|null the compiled value registry (set by boot()) */
    private $system = null;

    /**
     * BUILD-TIME (PHP 8). Convenience delegate to GenAI\Boot\Compiler — the only
     * place this runtime class touches build code. The project root and Composer
     * loader are compile-only concerns, so they're passed here, not to the
     * constructor (the runtime reads what it needs from the compiled Cache\System).
     * The loader falls back to vendor/autoload.php when omitted. Returns the
     * Compiler so a build script can inspect what was discovered.
     *
     *   $compiler = (new Kernel())->compile(__DIR__, array('App'), $loader);
     *
     * @param string $root          project root (holds vendor/, cache/, config/, templates/)
     * @param array  $appNamespaces namespaces holding the app's annotated classes
     * @param object $loader        optional Composer ClassLoader
     * @param array  $parameters    flat config for #[Value('${...}')], optional
     * @return Compiler
     */
    public function compile($root, $appNamespaces, $loader = null, $parameters = array())
    {
        $compiler = new Compiler($root, $loader);
        $compiler->compile($appNamespaces, $parameters);

        return $compiler;
    }

    /**
     * Assert the given compiled Cache\* classes exist (autoloaded from cache/);
     * if any are missing the app hasn't been compiled, so fail with one clear
     * message instead of a cryptic "class not found" deeper in.
     *
     * @param array $classes fully-qualified Cache\* class names
     * @return void
     * @throws \RuntimeException listing what's missing.
     */
    private function requireCompiled($classes)
    {
        $missing = array();
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                $missing[] = $class;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                'The app is not compiled (missing ' . implode(', ', $missing)
                . '). Run: composer compile  (or vendor/bin/genai-compile --namespace=App)'
            );
        }
    }

    /**
     * RUNTIME (PHP 5.3), shared by both fronts: the compiled container subclass
     * plus the #[Property] config beans. Callers requireCompiled() these first.
     *
     * @return Container
     */
    private function buildContainer()
    {
        if ($this->system === null) {
            $this->system = new \Cache\System();
        }

        $container = new \Cache\Container();   // self-populating subclass

        // Each registrar augments the container with extra beans (Cache\Properties
        // for #[Property] config, Cache\Mappers for #[Mapper] SQL mappers, ...).
        // The list is baked into Cache\System at compile, so optional ones appear
        // only when their component compiled something — no class_exists() here.
        foreach ($this->system->getContainerLoaders() as $loader) {
            call_user_func(array($loader, 'loadInto'), $container);
        }

        $this->container = $container;

        return $container;
    }

    /**
     * The single entry point for every front. Builds the shared container, then asks
     * each installed AutoConfig (web / CLI / messaging / ...) whether it handles this
     * invocation — highest priority() first — and runs the first match. The same one
     * line in EVERY entry script, web or CLI:
     *
     *   (new Kernel())->run();   // public/index.php AND bin/app
     *
     * No exit() at the call site: a CLI front terminates the process itself with its
     * own exit code (it knows it must), while the web front just emits and returns.
     *
     * Which front handles it is decided by what's installed (composer) + the context
     * (web SAPI vs argv), NOT by the Kernel — the Kernel no longer names Cache\Web /
     * Cache\Commands / Cache\Consumers. Adding a front is just installing its component.
     *
     * @param array $options passed through to the front (web: uriKey/basePath).
     * @return mixed the front's return (web returns 0); CLI fronts exit() before returning.
     */
    public function run($options = array())
    {
        // Establish the process correlation baseline FIRST — before anything below can
        // fail — so even a build/boot error is logged under an id. Each front refines
        // it from its own carrier (HTTP header / env / per message). See Runtime.
        Runtime::id();

        $this->requireCompiled(array('Cache\\Container', 'Cache\\Properties', 'Cache\\System'));

        $container = $this->buildContainer();   // also sets $this->system
        $context   = Context::detect($options);

        foreach ($this->loadFronts() as $front) {
            if ($front->supports($context)) {
                $this->requireCompiled($front->required());

                return $front->run($container, $this->system, $context);
            }
        }

        // Nothing handled it — a fatal CLI invocation should still report non-zero.
        fwrite(STDERR, "No front handled this invocation (is the right component installed?).\n");
        if ($context->isCli()) {
            exit(1);
        }

        return 1;
    }

    /**
     * Instantiate the compiled AutoConfig fronts. The list in Cache\System is already
     * ordered by priority() (highest first) — the Compiler sorted it at build time —
     * so the runtime just instantiates them in that order; no sort here.
     *
     * @return array AutoConfig instances, highest priority first
     */
    private function loadFronts()
    {
        $fronts = array();
        foreach ($this->system->getAutoConfigs() as $class) {
            $fronts[] = new $class();
        }

        return $fronts;
    }

    /**
     * The booted container (available after boot()/run()). Lets an app resolve a
     * bean directly — e.g. a #[Property] config object by its class name.
     *
     * @return \GenAI\Container\Container|null
     */
    public function container()
    {
        return $this->container;
    }
}
