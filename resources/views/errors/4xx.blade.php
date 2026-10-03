@include('errors.page', [
    'code' => method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 400,
    'title' => 'Request could not be completed',
    'titleBn' => 'অনুরোধ সম্পন্ন করা যায়নি',
    'description' => 'The request could not be processed. Check the address or return to the workspace.',
    'descriptionBn' => 'অনুরোধটি প্রক্রিয়া করা যায়নি। ঠিকানা যাচাই করুন বা কর্মক্ষেত্রে ফিরে যান।',
    'suggestion' => 'If this seems unexpected, contact your administrator.',
    'suggestionBn' => 'এটি অপ্রত্যাশিত মনে হলে আপনার অ্যাডমিনের সঙ্গে যোগাযোগ করুন।',
])
