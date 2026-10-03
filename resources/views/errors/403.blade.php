@include('errors.page', [
    'code' => 403,
    'title' => 'Access denied',
    'titleBn' => 'প্রবেশাধিকার নেই',
    'description' => 'Your account does not have permission to open this page.',
    'descriptionBn' => 'এই পৃষ্ঠাটি খোলার অনুমতি আপনার অ্যাকাউন্টে নেই।',
    'suggestion' => 'If you need access, ask your company administrator to update your permissions.',
    'suggestionBn' => 'প্রবেশাধিকার প্রয়োজন হলে কোম্পানির অ্যাডমিনকে অনুমতি পরিবর্তন করতে বলুন।',
])
