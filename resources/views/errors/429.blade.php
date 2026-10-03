@include('errors.page', [
    'code' => 429,
    'title' => 'Please slow down',
    'titleBn' => 'অনুগ্রহ করে একটু অপেক্ষা করুন',
    'description' => 'Too many requests were sent in a short time. Wait a moment before trying again.',
    'descriptionBn' => 'অল্প সময়ে অনেক অনুরোধ পাঠানো হয়েছে। আবার চেষ্টা করার আগে কিছুক্ষণ অপেক্ষা করুন।',
    'suggestion' => 'If this keeps happening, contact your administrator.',
    'suggestionBn' => 'এটি বারবার ঘটলে আপনার অ্যাডমিনের সঙ্গে যোগাযোগ করুন।',
])
