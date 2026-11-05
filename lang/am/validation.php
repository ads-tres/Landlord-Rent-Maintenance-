<?php

// Amharic overrides; anything missing falls back to lang/en/validation.php.
return [
    'accepted' => ':attribute መቀበል አለበት።',
    'digits' => ':attribute :digits አሃዝ መሆን አለበት።',
    'ethiopian_phone' => 'የኢትዮጵያ ሞባይል ቁጥር ያስገቡ፤ ለምሳሌ 0911 234 567።',
    'in' => 'የተመረጠው :attribute ትክክል አይደለም።',
    'integer' => ':attribute ቁጥር መሆን አለበት።',
    'max' => [
        'numeric' => ':attribute ከ:max መብለጥ የለበትም።',
        'string' => ':attribute ከ:max ፊደላት መብለጥ የለበትም።',
    ],
    'min' => [
        'numeric' => ':attribute ቢያንስ :min መሆን አለበት።',
        'string' => ':attribute ቢያንስ :min ፊደላት መሆን አለበት።',
    ],
    'required' => ':attribute ያስፈልጋል።',
    'string' => ':attribute ጽሑፍ መሆን አለበት።',
    'unique' => ':attribute አስቀድሞ ተይዟል።',

    'attributes' => [
        'phone' => 'ስልክ ቁጥር',
        'code' => 'ኮድ',
        'name' => 'ስም',
        'full_name' => 'ሙሉ ስም',
        'unit_count' => 'የቤቶች ብዛት',
        'label' => 'የቤት መለያ',
        'privacy_notice_shown' => 'የግላዊነት ማሳሰቢያ',
    ],
];
