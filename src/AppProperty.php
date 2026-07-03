<?php

namespace GenAI\Boot;

use GenAI\Property\AbstractProperty;
use GenAI\Property\Attribute\Property;
use GenAI\Property\Util\Map;

/**
 * Kernel-level HTTP config, bound from the app's [app] group in app.ini:
 *
 *   [app]
 *   uriKey   = REQUEST_URI       ; which $_SERVER key holds the request path
 *   basePath = /some/folder      ; prefix to strip before route matching
 *
 * The binding is optional (optional: true), so an app that doesn't provide
 * app.ini (or the [app] group) just gets the defaults below — nothing breaks.
 * Kernel::run() reads this after boot() and feeds it to Request::fromGlobals();
 * values passed explicitly to run() still take precedence.
 *
 * Runtime class (PHP 5.3-safe); the #[Property] line is a comment on 5.3.
 */
#[Property(group: 'app', optional: true)]
class AppProperty extends AbstractProperty
{
    private $uriKey;
    private $basePath;
    private $env;

    public function bindData(Map $data)
    {
        $this->uriKey   = $data->get('uriKey');
        $this->basePath = $data->get('basePath');
        $this->env      = $data->get('env');
    }

    /**
     * @return string the $_SERVER key for the request path (default REQUEST_URI)
     */
    public function getUriKey()
    {
        return ($this->uriKey !== null && $this->uriKey !== '') ? $this->uriKey : 'REQUEST_URI';
    }

    /**
     * @return string the base path to strip (default '' = none)
     */
    public function getBasePath()
    {
        return $this->basePath !== null ? $this->basePath : '';
    }

    /**
     * Application environment from [app] env — "dev" (default) or "prod".
     *
     * @return string lowercased; "dev" when unset
     */
    public function getEnv()
    {
        return ($this->env !== null && $this->env !== '') ? strtolower($this->env) : 'dev';
    }

    /**
     * @return bool true only when env is exactly "prod" (anything else => dev, so a
     *              typo fails safe to showing errors rather than hiding them)
     */
    public function isProd()
    {
        return $this->getEnv() === 'prod';
    }
}
