@include('errors.client', [
    'code' => 402,
    'title' => 'Payment Required',
    'message' => 'This action needs an active plan, credits, or billing access before it can continue.',
    'hint' => 'Review your subscription, plan entitlements, or available credits and try again.',
])
