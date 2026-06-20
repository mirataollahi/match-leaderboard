<?php declare(strict_types=1);

namespace App\Controller;

use App\Service\MatchReportService;
use Cake\Http\Exception\BadRequestException;
use Throwable;
use App\Exception\RequestIdConflictException;
use App\Exception\UserNotFoundException;
use App\Exception\ValidationException;
use App\Service\MatchReportValidator;
use Cake\Http\Response;

class MatchesController extends BaseApiController
{
    /**
     * Handle create match report api request
     *
     * @param MatchReportValidator $matchReportValidator
     * @param MatchReportService $matchReportService
     * @return Response
     * @throws Throwable
     */
    public function report(
        MatchReportValidator $matchReportValidator,
        MatchReportService   $matchReportService
    ): Response
    {
        $this->request->allowMethod(['post']);

        // Rate limiting: max 5 requests per 10 seconds per user_id + IP
        $this->checkRateLimit();

        try {
            // Parse and validate the request body
            $rawData = $this->request->getData();

            if (empty($rawData)) {
                throw new BadRequestException('Request body must be valid JSON.');
            }

            $validatedData = $matchReportValidator->validate($rawData);
            $result = $matchReportService->process($validatedData);

            return $this->successResponse(
                $result,
                $result['duplicate'] ? 200 : 201
            );

        } catch (ValidationException $e) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                '',
                422,
                ['details' => $e->getErrors()]
            );

        } catch (UserNotFoundException $e) {
            return $this->errorResponse(
                'USER_NOT_FOUND',
                $e->getMessage(),
                404
            );

        } catch (RequestIdConflictException $e) {
            return $this->errorResponse(
                'REQUEST_ID_CONFLICT',
                '',
                409
            );

        } catch (Throwable $e) {
            return $this->handleException($e, 'MatchesController');
        }
    }
}
