@include('errors.client', [
    'code' => 409,
    'title' => 'Conflict',
    'message' => 'The request could not be completed because it conflicts with the current resource state.',
    'hint' => 'Refresh the page to sync the latest state, then try the action again.',
])
