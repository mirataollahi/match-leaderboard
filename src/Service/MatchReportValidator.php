<?php declare(strict_types=1);

namespace App\Service;


use App\Exception\ValidationException;

/**
 * MatchReportValidator
 */
class MatchReportValidator
{
    /** @var list<string>  Accepted values for the `result` field. */
    private const VALID_RESULTS = ['win', 'lose', 'draw'];

    /**
     * Validate the incoming payload and return a normalized copy.
     *
     * @param array<string, mixed> $data  Raw request body parsed from JSON.
     * @return array<string, mixed>  Validated and normalised data.
     * @throws ValidationException  When one or more fields fail validation.
     */
    public function validate(array $data): array
    {
        $errors = [];

        // request_id
        if (empty($data['request_id'])) {
            $errors['request_id'] = 'request_id is required.';
        } elseif (!is_string($data['request_id']) || strlen($data['request_id']) > 64) {
            $errors['request_id'] = 'request_id must be a string of at most 64 characters.';
        }

        // user_id
        if (!isset($data['user_id']) || !is_numeric($data['user_id']) || (int)$data['user_id'] <= 0) {
            $errors['user_id'] = 'user_id must be a positive integer.';
        }

        // match_id
        if (!isset($data['match_id']) || (string)$data['match_id'] === '') {
            $errors['match_id'] = 'match_id is required.';
        }

        // result
        $result = isset($data['result']) ? strtolower(trim((string)$data['result'])) : '';
        if (!in_array($result, self::VALID_RESULTS, true)) {
            $errors['result'] = 'result must be one of: win, lose, draw.';
        }

        // score delta
        if (!isset($data['score_delta']) || !is_numeric($data['score_delta'])) {
            $errors['score_delta'] = 'score_delta must be an integer.';
        }

        // reported at
        if (empty($data['reported_at']) || !is_numeric($data['reported_at']) || (int)$data['reported_at'] <= 0) {
            $errors['reported_at'] = 'reported_at must be a valid Unix timestamp.';
        } elseif ((int)$data['reported_at'] > time() + 300) {
            // Allow 5-minute future drift to accommodate clock skew
            $errors['reported_at'] = 'reported_at cannot be more than 5 minutes in the future.';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        // ── Normalise ─────────────────────────────────────────
        // "lose" (API spec wording) → "loss" (DB canonical value used by the entity)
        $normalised        = $data;
        $normalised['result']      = ($result === 'lose') ? 'loss' : $result;
        $normalised['user_id']     = (int)$data['user_id'];
        $normalised['score_delta'] = (int)$data['score_delta'];
        $normalised['reported_at'] = (int)$data['reported_at'];
        $normalised['match_id']    = (string)$data['match_id'];

        return $normalised;
    }
}
