<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Resources\Mobile\MobileJobResource;
use App\Services\Mobile\MobileJobSubmissionException;
use App\Services\Mobile\MobileJobSubmissionService;
use App\Services\Mobile\MobileJobStatusSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileJobsController extends MobileApiController
{
    public function __construct(
        protected MobileJobSubmissionService $submissions,
        protected MobileJobStatusSyncService $statusSync,
    ) {
    }

    public function index(Request $request, string $app)
    {
        $this->appContext($request, $app);

        $jobs = app(\App\Services\Mobile\MobileAppCatalog::class)
            ->jobsQuery($this->customer($request), $app)
            ->with([
                'tool:id,code,name',
                'toolAction:id,tool_code,full_code,name',
            ])
            ->orderByDesc('created_at')
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 15))));

        return MobileJobResource::collection($jobs);
    }

    public function show(Request $request, string $app, string $jobId): MobileJobResource
    {
        $this->appContext($request, $app);

        $job = app(\App\Services\Mobile\MobileAppCatalog::class)
            ->jobsQuery($this->customer($request), $app)
            ->with([
                'tool:id,code,name',
                'toolAction:id,tool_code,full_code,name',
            ])
            ->where('id', $jobId)
            ->firstOrFail();

        abort_unless($this->customer($request)->canAccessMlJob($job), 403, 'You do not have access to this job.');

        $job = $this->statusSync->refresh($job);

        return new MobileJobResource($job);
    }

    public function store(Request $request, string $app): JsonResponse
    {
        $context = $this->appContext($request, $app);

        try {
            $result = $this->submissions->submit($request, $this->customer($request), $app);
        } catch (MobileJobSubmissionException $e) {
            $payload = [
                'message' => $e->getMessage(),
            ];

            if ($e->payload() !== []) {
                $payload['errors'] = $e->payload();
            }

            return response()->json($payload, $e->status());
        }

        $job = $result['job'];

        return response()->json([
            'message' => $result['message'],
            'data' => [
                'job' => new MobileJobResource($job),
                'next_actions' => [
                    'status_url' => route('api.mobile.apps.jobs.show', [
                        'app' => (string) $context['slug'],
                        'jobId' => (string) $job->id,
                    ]),
                    'jobs_url' => route('api.mobile.apps.jobs.index', [
                        'app' => (string) $context['slug'],
                    ]),
                    'files_url' => route('api.mobile.apps.files.index', [
                        'app' => (string) $context['slug'],
                    ]),
                    'recommended_poll_interval_seconds' => 5,
                ],
            ],
        ], 201);
    }
}
