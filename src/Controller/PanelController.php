<?php declare(strict_types=1);

namespace App\Controller;

use App\Controller\AppController;

class PanelController extends AppController
{
    /**
     * Render the leaderboard dashboard shell.
     */
    public function index(): void
    {
        $request = $this->request;
        $apiBase = $request->scheme() . '://' . $request->host();
        $port    = $request->port();

        if ($port && !in_array((int)$port, [80, 443], true)) {
            $apiBase .= ':' . $port;
        }

        $this->set('apiBase', $apiBase);
        $this->set('title', 'Game Leaderboard — Panel');
    }
}
