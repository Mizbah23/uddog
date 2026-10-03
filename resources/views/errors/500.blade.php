@include('errors.page', [
    'code' => 500,
    'title' => 'Something went wrong',
    'titleBn' => 'কিছু একটা সমস্যা হয়েছে',
    'description' => 'We could not complete this request. Please return to the workspace or try again shortly.',
    'descriptionBn' => 'এই অনুরোধটি সম্পন্ন করা যায়নি। কর্মক্ষেত্রে ফিরে যান বা কিছুক্ষণ পরে আবার চেষ্টা করুন।',
    'suggestion' => 'If the problem continues, contact your administrator with the time it occurred.',
    'suggestionBn' => 'সমস্যাটি চলতে থাকলে কখন ঘটেছে তা জানিয়ে অ্যাডমিনের সঙ্গে যোগাযোগ করুন।',
])
