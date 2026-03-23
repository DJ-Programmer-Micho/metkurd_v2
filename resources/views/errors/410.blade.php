@include('errors.client', [
    'code' => 410,
    'title' => 'Gone',
    'message' => 'This resource is no longer available and will not return.',
    'hint' => 'Download links and prepared render artifacts can expire after a certain amount of time.',
])
