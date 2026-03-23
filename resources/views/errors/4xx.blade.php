@include('errors.client', [
    'code' => $exception->getStatusCode(),
    'title' => 'Client Error',
    'message' => 'The request could not be completed because of a client-side access or request issue.',
    'hint' => 'Review the request, your account permissions, and the current page state before trying again.',
])
