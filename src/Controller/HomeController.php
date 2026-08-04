<?php

namespace Dynart\Dpress\Controller;

use Dynart\Micro\Attribute\Route;

/**
 * A placeholder front page
 *
 * Replaced by the content controller in Phase 2; for now it is somewhere for a login to land.
 */
class HomeController extends AbstractController {

    #[Route('GET', '/')]
    public function index(): string {
        return $this->render('dpress:home', ['title' => '']);
    }
}
