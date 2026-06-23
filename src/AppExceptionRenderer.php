<?php declare(strict_types=1);

namespace App;

use Cake\Http\Response;
use Cake\Error\Renderer\WebExceptionRenderer;

/**
 * Application general exception renderer
 */
class AppExceptionRenderer extends WebExceptionRenderer
{
    public function render(): Response
    {
        $exception = $this->error;

        $statusCode = method_exists($exception, 'getCode')
        && is_numeric($exception->getCode())
        && $exception->getCode() >= 400
            ? (int)$exception->getCode()
            : 500;

        $response = new Response();

        return $response
            ->withType('application/json')
            ->withStatus($statusCode)
            ->withStringBody(json_encode([
                'success' => false,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTrace(),
            ]));
    }
}
