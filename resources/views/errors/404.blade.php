@include('errors.client', [
    'code' => 404,
    'title' => 'Not Found',
    'message' => 'The page or file you requested could not be found.',
    'hint' => 'The link may be outdated, the item may have been removed, or the address may be incorrect.',
])
