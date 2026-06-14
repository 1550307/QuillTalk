<?php
// lang.php

$translations = [
    'en' => [
        // --- UNIVERSAL NAVIGATION / HEADER ---
        'sign_up' => 'Signup',
        'login' => 'Login',
        
        // --- INDEX.PHP (HOME PAGE) CONTENT ---
        'slogan' => 'Speak freely. Connect deeply.',
        'email_address' => 'Email address',
        'create_account' => 'Create Account',
        'already_account' => 'Already have an account?',
        'why_choose' => 'Why choose Quill?',
        'reason_ui_title' => 'Simple, Clean UI',
        'reason_ui_text' => 'Chat without distractions. Our sleek interface keeps your conversations front and center.',
        'reason_secure_title' => 'Secure Passwords',
        'reason_secure_text' => 'Quill uses password hashing, meaning that your password isn\'t stored on our servers.',
        'reason_speed_title' => 'Built for Speed',
        'reason_speed_text' => 'Quill loads instantly and keeps your chats synced in real-time across devices.',
        'reason_free_title' => 'Free Forever',
        'reason_free_text' => 'No subscriptions, no paywalls. Just open Quill and start chatting.',
        'reason_mobile_title' => 'Mobile Friendly',
        'reason_mobile_text' => 'Designed to look and feel great on phones, tablets, and desktops.',
        'footer_slogan' => 'Made for real conversations.',
        
        // --- LOGIN.PHP CONTENT ---
        'login_title' => 'Login',
        'login_with_microsoft' => 'Continue with Microsoft',
        'login_with_passkeys' => 'Continue with Passkeys',
        'login_with_google' => 'Continue with Google',
        'or_sign_in_manually' => 'Or sign in manually:',
        'username' => 'Username',
        'password' => 'Password',
        'forgot_password' => 'Forgot password?',
        'dont_have_account' => "Don't have an account?",
        'invalid_credentials' => 'Invalid credentials',
        'remember_me' => 'Remember Me?',
        
        // --- SIGNUP.PHP CONTENT ---
        'signup_title' => 'Sign Up',
        'signup_with_microsoft' => 'Sign up with Microsoft',
        'signup_with_passkeys' => 'Sign up with Passkeys',
        'signup_with_google' => 'Sign up with Google',
        'error_username_taken' => 'That username is already taken.',
        'error_email_exists' => 'An account with this email already exists.',
        'error_duplicate_entry' => 'Duplicate entry. Please try a different value.',
        'error_database' => 'Database error:',
        'error_mail' => 'Mail error:',
        'agree' => 'I agree to the ',
        'privacy' => 'Privacy Policy',
        'and' => 'and the',
        'terms' => 'Terms of Service',
        
        // --- VERIFY.PHP CONTENT (NEW) ---
        'verify_title' => 'Verify Your Email',
        'enter_code_placeholder' => 'Enter your 6-digit code',
        'verify_button' => 'Verify',
        'error_invalid_code' => 'Invalid verification code.',
        'success_verified' => 'Email verified! You can now', // used before the <a> tag for login

        // --- CHAT INTERFACE CONTENT (NEW) ---
        'app_title' => 'QuillTalk',
        'select_contact' => 'Select a contact',
        'type_message' => 'Type a message…',
        'send_button' => 'Send',
        'contacts_title' => 'Contacts',
        'search_users_placeholder' => 'Search users…',
        'search_button' => 'Search',
        'switch_user' => 'Switch User (You are logged in as %s)', // %s will be username
        'missing_token' => 'Missing token.',
        'invalid_session' => 'Invalid session.',
        'call_button_alt' => 'Call button',
        'end_call_button' => 'End Call',
        'searching_users' => 'Searching users...',
        'send_request_to' => 'Send Friend Request to'
    ],
    'ar' => [
        // --- UNIVERSAL NAVIGATION / HEADER ---
        'sign_up' => 'التسجيل',
        'login' => 'تسجيل الدخول',
        
        // --- INDEX.PHP (HOME PAGE) CONTENT ---
        'slogan' => 'تحدث بحرية. تواصل بعمق.',
        'email_address' => 'عنوان البريد الإلكتروني',
        'create_account' => 'إنشاء حساب',
        'already_account' => 'هل لديك حساب بالفعل؟',
        'why_choose' => 'لماذا تختار كويل؟',
        'reason_ui_title' => 'واجهة بسيطة ونظيفة',
        'reason_ui_text' => 'الدردشة دون تشتيت. واجهتنا الأنيقة تبقي محادثاتك في المقدمة والوسط.',
        'reason_secure_title' => 'كلمات مرور آمنة',
        'reason_secure_text' => 'يستخدم كويل تجزئة كلمة المرور، مما يعني أن كلمة المرور الخاصة بك لا يتم تخزينها على خوادمنا.',
        'reason_speed_title' => 'مصمم للسرعة',
        'reason_speed_text' => 'يتم تحميل كويل على الفور ويحافظ على مزامنة محادثاتك في الوقت الفعلي عبر الأجهزة.',
        'reason_free_title' => 'مجاني إلى الأبد',
        'reason_free_text' => 'لا اشتراكات، ولا جدران دفع. ما عليك سوى فتح كويل والبدء في الدردشة.',
        'reason_mobile_title' => 'مناسب للجوال',
        'reason_mobile_text' => 'مصمم ليبدو وشعوراً رائعين على الهواتف والأجهزة اللوحية وأجهزة الكمبيوتر المكتبية.',
        'footer_slogan' => 'صنع للمحادثات الحقيقية.',
        
        // --- LOGIN.PHP CONTENT ---
        'login_title' => 'تسجيل الدخول',
        'login_with_facebook' => 'تسجيل الدخول بفيسبوك',
        'login_with_twitter' => 'تسجيل الدخول بتويتر',
        'login_with_google' => 'تسجيل الدخول بجوجل',
        'or_sign_in_manually' => 'أو سجل الدخول يدوياً:',
        'username' => 'اسم المستخدم',
        'password' => 'كلمة المرور',
        'forgot_password' => 'هل نسيت كلمة المرور؟',
        'dont_have_account' => 'ألا تمتلك حساباً؟',
        'invalid_credentials' => 'بيانات الاعتماد غير صالحة',
        'remember_me' => 'نتذكرك في المستقبل؟',
        
        // --- SIGNUP.PHP CONTENT ---
        'signup_title' => 'التسجيل',
        'signup_with_facebook' => 'التسجيل بفيسبوك',
        'signup_with_twitter' => 'التسجيل بتويتر',
        'signup_with_google' => 'التسجيل بجوجل',
        'error_username_taken' => 'اسم المستخدم هذا محجوز بالفعل.',
        'error_email_exists' => 'يوجد حساب بهذا البريد الإلكتروني بالفعل.',
        'error_duplicate_entry' => 'إدخال مكرر. يرجى محاولة قيمة مختلفة.',
        'error_database' => 'خطأ في قاعدة البيانات:',
        'error_mail' => 'خطأ في البريد:',
        'agree' => 'أوافق على',
        'privacy' => 'سياسة الخصوصية',
        'and' => 'و',
        'terms' => 'شروط الخدمة',

        // --- VERIFY.PHP CONTENT (NEW) ---
        'verify_title' => 'تحقق من بريدك الإلكتروني',
        'enter_code_placeholder' => 'أدخل الرمز المكون من 6 أرقام',
        'verify_button' => 'تحقق',
        'error_invalid_code' => 'رمز التحقق غير صالح.',
        'success_verified' => 'تم التحقق من البريد الإلكتروني! يمكنك الآن',

        // --- CHAT INTERFACE CONTENT (NEW) ---
        'app_title' => 'كويل توك',
        'select_contact' => 'اختر جهة اتصال',
        'type_message' => 'اكتب رسالة…',
        'send_button' => 'إرسال',
        'contacts_title' => 'جهات الاتصال',
        'search_users_placeholder' => 'البحث عن مستخدمين…',
        'search_button' => 'بحث',
        'switch_user' => 'تبديل المستخدم (أنت مسجل الدخول باسم %s)',
        'missing_token' => 'الرمز مفقود.',
        'invalid_session' => 'الجلسة غير صالحة.',
        'call_button_alt' => 'زر الاتصال',
        'end_call_button' => 'إنهاء المكالمة',
        'searching_users' => 'جاري البحث عن المستخدمين...',
        'send_request_to' => 'إرسال طلب صداقة إلى'
    ],
];

function __($key) {
    global $translations, $lang;
    
    // Check for translation in the current language
    if (isset($translations[$lang]) && isset($translations[$lang][$key])) {
        return $translations[$lang][$key];
    }
    
    // Fallback to English
    if (isset($translations['en'][$key])) {
        return $translations['en'][$key];
    }
    
    // Return the key if no translation is found (for easy debugging)
    return $key; 
}
