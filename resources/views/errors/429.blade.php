@include('errors.client', [
    'code' => 429,
    'title' => 'Too Many Requests',
    'message' => 'Too many requests were sent in a short time window.',
    'hint' => 'Wait a moment before retrying so the application can recover and process requests normally.',
])
