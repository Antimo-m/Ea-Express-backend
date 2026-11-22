<?php

namespace App\Http\Controllers;

use App\Actions\SendFeedbackReport;
use App\Http\Requests\FeedbackReportRequest;
use App\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FeedbackController extends Controller
{
    public function create(): View
    {
        return view('feedback.create', ['categories' => FeedbackReportRequest::Categories]);
    }

    public function store(FeedbackReportRequest $request, SendFeedbackReport $send): RedirectResponse
    {
        $data = $request->safe()->only(['category', 'subject', 'message', 'location', 'client']);
        $source = $request->user()->role === UserRole::Admin ? 'Gestionale (Admin)' : 'Gestionale (Rider)';
        if (! $send->handle($request->user(), $source, $data, $request->file('attachments', []))) {
            return back()->withInput()->withErrors(['feedback' => SendFeedbackReport::Unavailable]);
        }

        return to_route('feedback.create')->with('feedback-sent', true);
    }
}
