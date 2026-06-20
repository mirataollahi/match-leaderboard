<?php declare(strict_types=1);

namespace App\Controller;

use Cake\Controller\Controller;
use Cake\Event\EventInterface;

/**
 * Error Handling Controller
 */
class ErrorController extends AppController
{
    /**
     * Initialization hook method.
     *
     * @return void
     */
    public function initialize(): void
    {
        // Only add parent::initialize() if you are confident your `AppController` is safe.
    }

    /**
     * beforeFilter callback.
     *
     * @param EventInterface<Controller> $event Event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
    }

    /**
     * beforeRender callback.
     *
     * @param EventInterface<Controller> $event Event.
     * @return void
     */
    public function beforeRender(EventInterface $event): void
    {
        parent::beforeRender($event);

        $this->viewBuilder()->setTemplatePath('Error');
    }

    /**
     * afterFilter callback.
     *
     * @param EventInterface<Controller> $event Event.
     * @return void
     */
    public function afterFilter(EventInterface $event): void
    {
    }
}
