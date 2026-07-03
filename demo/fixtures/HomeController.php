<?php

namespace Demo;

use GenAI\Web\Attribute\Route;
use GenAI\Web\Attribute\Controller;
use GenAI\Web\View\ModelAndView;

/**
 * The whole app: one controller. No build wiring — the Kernel discovers every
 * installed component's processor from composer and compiles this for us.
 *
 * Runtime class (PHP 5.3-safe); the #[...] lines are comments on 5.3.
 */
#[Controller]
class HomeController
{
    #[Route('GET', '/hello/{name}')]
    public function hello($name, ModelAndView $model)
    {
        $model->add('name', $name);

        return 'hello';
    }
}
