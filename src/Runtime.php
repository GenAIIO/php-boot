<?php

namespace GenAI\Boot;

/**
 * The process-wide correlation id (a "request id" BASELINE), established as early as
 * possible in Kernel::run() — before requireCompiled()/buildContainer() can fail — so
 * EVERY log line in the process can carry an id, including ones emitted before a front
 * has bound a transport-specific id (or when boot itself errors out).
 *
 * Deliberately transport-agnostic and dependency-free: boot owns this primitive; the
 * fronts REFINE it (web adopts the inbound header, console an env var, the worker a
 * fresh id per message). An optional tracing package (genai/trace) ADOPTS this baseline
 * by reading the neutral $_SERVER slot below — so there is no boot -> trace code edge
 * (the same string-keyed decoupling the kernel uses for AppProperty).
 *
 * Runtime class (PHP 5.3-safe).
 */
class Runtime
{
    /** Neutral slot the baseline is published to, for optional adopters to read. */
    const ID_KEY = 'GENAI_TRACE_BASELINE';

    /** @var string */
    private static $id = '';

    /**
     * The process correlation id, generating + publishing one on first use. Adopts an
     * upstream-seeded $_SERVER[ID_KEY] when present (so a wrapper / parent process can
     * propagate one in); otherwise generates a 128-bit hex id.
     *
     * @return string
     */
    public static function id()
    {
        if (self::$id === '') {
            self::$id = (isset($_SERVER[self::ID_KEY]) && $_SERVER[self::ID_KEY] !== '')
                ? (string) $_SERVER[self::ID_KEY]
                : self::generate();
            $_SERVER[self::ID_KEY] = self::$id;   // publish for optional adopters (genai/trace)
        }

        return self::$id;
    }

    /** A 32-char hex id (128 bits). Prefers a CSPRNG, falls back gracefully. */
    private static function generate()
    {
        if (function_exists('random_bytes')) {
            $bytes = random_bytes(16);
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes(16);
        } else {
            $bytes = '';
            for ($i = 0; $i < 16; $i++) {
                $bytes .= chr(mt_rand(0, 255));
            }
        }

        return bin2hex($bytes);
    }
}
