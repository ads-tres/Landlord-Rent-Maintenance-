<?php

return [
    'nothing_owed' => 'Nothing is overdue on this lease.',
    'plan_total' => 'The instalments must add up to what is overdue: :owed.',
    'plan_broken' => 'Payment plan broken: an instalment is more than a week late.',
    'sms' => [
        'reminder' => 'Hello, a friendly reminder: rent of :amount for :unit is :days days overdue. Please pay when you can, or call to agree a date. :url - :landlord via :app',
        'notice' => 'Notice: rent of :amount for :unit is :days days overdue. Please pay within 7 days or contact your landlord to agree a payment plan. :url - :landlord via :app',
        'plan' => 'Payment plan agreed for :unit: :count instalments, first :amount on :date. You will get a reminder the day before. - :app',
        'instalment' => 'Reminder: your payment plan instalment of :amount for :unit is due tomorrow, :date. - :app',
    ],
    'letter' => [
        'date' => 'Date: :date',
        'to' => 'To: :tenant, :place',
        'title' => 'Formal demand for unpaid rent',
        'intro' => 'Under the lease that started on :start for :place, the following amounts are unpaid:',
        'item' => 'Item',
        'due' => 'Due',
        'amount' => 'Amount',
        'rent' => 'Rent for :period',
        'utility' => 'Utility share for :period',
        'total' => 'Total unpaid',
        'demand' => 'You are required to pay :total by :deadline.',
        'pay_online' => 'You can pay online at :url.',
        'plan' => 'If you cannot pay the full amount, contact the landlord (:phone) before that date to agree a payment plan.',
        'legal' => 'If the amount is not paid or a plan agreed by that date, the landlord may take the matter further under the lease and the law. This letter is a record of the amount owed.',
        'signed' => 'Landlord: :name',
    ],
];
