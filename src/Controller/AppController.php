<?php declare(strict_types=1);

namespace App\Controller;

use Cake\Controller\Controller;
use Exception;
use Cake\Event\EventInterface;

class AppController extends Controller
{
    /**
     * Initialization controller hook method
     *
     * @throws Exception
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent('Flash');

        $this->response = $this->response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    }

    /**
     * Before filter callback.
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        // Handle CORS preflight requests
        if ($this->request->is('options')) {
            $this->response = $this->response
                ->withStatus(204)
                ->withHeader('Access-Control-Max-Age', '86400');
            $this->response = $this->response->withStringBody('');
        }
    }

    /**
     * Handle OPTIONS requests for CORS.
     */
    public function options(): void
    {
        //
    }
}
