<?php

namespace GenAI\Boot;

/**
 * The contract a component implements to contribute a runtime "front" — a way the
 * app can be invoked (HTTP request, CLI command, queue consumer, ...). The Kernel
 * holds NO per-front knowledge any more: it just asks each installed AutoConfig
 * "do you handle this invocation?" and runs the first one that says yes.
 *
 * A component declares its AutoConfig in composer.json, exactly like processors:
 *
 *   "extra": { "genai": { "autoconfig": ["GenAI\\Web\\WebAutoConfig"] } }
 *
 * the Compiler bakes the list into Cache\System::getAutoConfigs(), and Kernel::run()
 * iterates them (highest priority() first).
 *
 * Front classes live in their own components (genai/web, genai/console,
 * genai/messaging) and `implements` this interface — genai/boot depends on NO front
 * (only genai/container + genai/property), so a front depending on genai/boot for
 * the contract is the proper SPI direction, not a cycle.
 *
 * Params stay untyped so implementations can keep them untyped too ($system is the
 * generated Cache\System; $context a GenAI\Boot\Context).
 *
 * Runtime contract (PHP 5.3-safe): no scalar hints.
 */
interface AutoConfig
{
    /** Higher runs first when more than one front could match. Web 0, CLI verbs >0, CLI catch-all <0. */
    public function priority();

    /** Compiled Cache\* classes this front needs (the Kernel asserts them before run()). */
    public function required();

    /** Should this front handle the current invocation? ($context is a GenAI\Boot\Context.) */
    public function supports($context);

    /** Build this front's runtime over the shared container and run it; return an exit code (or null). */
    public function run($container, $system, $context);
}
