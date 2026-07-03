<?php

namespace GenAI\Boot;

/**
 * The invocation context handed to every AutoConfig::supports()/run(): is this a
 * web request or a CLI call, the argv, and the run() options. Lets a front decide
 * whether it owns the invocation (web by SAPI; a CLI front by the first argument).
 *
 * Runtime class (PHP 5.3-safe).
 */
class Context
{
    private $cli;
    private $argv;
    private $options;

    /**
     * @param array $options the array passed to Kernel::run() (uriKey, basePath, ...)
     * @return Context
     */
    public static function detect($options = array())
    {
        $ctx = new self();
        $sapi = defined('PHP_SAPI') ? PHP_SAPI : php_sapi_name();
        $ctx->cli     = ($sapi === 'cli' || $sapi === 'phpdbg');
        $ctx->argv    = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
        $ctx->options = $options;

        return $ctx;
    }

    /** @return bool true for a command-line invocation, false for a web request. */
    public function isCli()
    {
        return $this->cli;
    }

    /** @return array the full $argv ([0] = script, [1] = verb/command, ...). */
    public function argv()
    {
        return $this->argv;
    }

    /** The nth argv value (0 = script name) or null. */
    public function arg($i)
    {
        return isset($this->argv[$i]) ? $this->argv[$i] : null;
    }

    /** @return array the options passed to Kernel::run(). */
    public function options()
    {
        return $this->options;
    }
}
