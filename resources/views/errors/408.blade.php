@include('errors.client', [
    'code' => 408,
    'title' => 'Request Timeout',
    'message' => 'The request took too long and the server stopped waiting for it.',
    'hint' => 'Check your network connection and retry the request when the connection is stable.',
])
