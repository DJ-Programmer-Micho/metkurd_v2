@include('errors.client', [
    'code' => 405,
    'title' => 'Method Not Allowed',
    'message' => 'This endpoint does not accept the HTTP method used for the request.',
    'hint' => 'Return to the previous page and repeat the action through the normal application flow.',
])
