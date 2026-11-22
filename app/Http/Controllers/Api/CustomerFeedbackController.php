<?php

namespace App\Http\Controllers\Api;

use App\Actions\SendFeedbackReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\FeedbackReportRequest;
use Illuminate\Http\JsonResponse;

class CustomerFeedbackController extends Controller
{
    public function __invoke(FeedbackReportRequest $request, SendFeedbackReport $send): JsonResponse
    {
        $data = $request->safe()->only(['category', 'subject', 'message', 'location', 'client']);
        if (! $send->handle($request->user(), 'Portale clienti', $data, $request->file('attachments', []))) {
            return response()->json(['message' => SendFeedbackReport::Unavailable], 503);
        }

        return response()->json(['message' => 'Segnalazione inviata. Grazie, la leggeremo al più presto.']);
    }
}
