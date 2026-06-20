<?php declare(strict_types=1);

namespace App\Controller;

use Cake\Controller\Controller;
use Cake\Http\Response;
use Cake\View\View;

/**
 * Web service document pages controller
 */
class DocController extends Controller
{
    /**
     * Sample test page request
     *
     * @return Response
     */
    public function testPage(): Response
    {
        $this->set('title', 'Home Page');
        return $this->render('/Doc/test'); // Note the leading slash

    }

    /**
     * Swagger api document index page
     *
     * @return Response
     */
    public function index(): Response
    {
        $this->disableAutoRender();
        $html = $this->fetchView()->render('Doc/swagger');

        return $this->response
            ->withType('text/html')
            ->withStringBody($html);
    }

    /**
     * Returns the raw OpenAPI YAML specification file
     *
     * @return Response
     */
    public function spec(): Response
    {
        $specPath = WWW_ROOT . 'swagger' . DS . 'openapi.yaml';
        if (!file_exists($specPath)) {
            return $this->response
                ->withStatus(404)
                ->withStringBody('openapi.yaml not found');
        }

        return $this->response
            ->withType('application/yaml')
            ->withStringBody((string)file_get_contents($specPath));
    }

    /**
     * Builds and returns a View instance for manual rendering.
     */
    private function fetchView(): View
    {
        return new View($this->request, $this->response, $this->getEventManager());
    }
}
