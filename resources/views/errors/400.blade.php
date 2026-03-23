@include('errors.client', [
    'code' => 400,
    'title' => 'Bad Request',
    'message' => 'The request could not be understood in its current form.',
    'hint' => 'Check the submitted data, refresh the page, and try again with a valid request.',
])
