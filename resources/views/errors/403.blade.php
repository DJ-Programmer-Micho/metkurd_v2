@include('errors.client', [
    'code' => 403,
    'title' => 'Forbidden',
    'message' => 'This service is not available for your current account, plan, or system status.',
    'hint' => 'The tool may be disabled globally, or your plan may not include the entitlement required for this page.',
])
