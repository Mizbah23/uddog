@include('errors.page', [
    'code' => method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500,
    'title' => 'Service problem',
    'titleBn' => 'সেবায় সমস্যা হয়েছে',
    'description' => 'The service could not complete this request right now. Please try again shortly.',
    'descriptionBn' => 'সেবাটি এখন এই অনুরোধ সম্পন্ন করতে পারছে না। কিছুক্ষণ পরে আবার চেষ্টা করুন।',
    'suggestion' => 'If the problem continues, contact your administrator.',
    'suggestionBn' => 'সমস্যাটি চলতে থাকলে আপনার অ্যাডমিনের সঙ্গে যোগাযোগ করুন।',
])
