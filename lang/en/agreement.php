<?php

return [
    'title' => 'Residential lease agreement',
    'years' => '{1} 1 year|[2,*] :count years',
    'months' => '{1} 1 month|[2,*] :count months',
    'bedrooms' => '(:count bedroom(s))',
    'due_day' => [
        'gregorian' => 'on or before day :day of each month',
        'ethiopian' => 'on or before day :day of each Ethiopian month',
    ],
    'online' => ', or online through the tenant\'s payment link',
    'kinds' => [
        'electricity' => 'electricity',
        'water' => 'water',
    ],
    'meter' => ':name (:kind), split :method',
    'split' => [
        'equal' => 'equally between the homes on the meter',
        'percent' => 'by the agreed shares (this home: :percent%)',
        'submeter' => 'by each home\'s sub-meter reading',
    ],
    'clauses' => [
        'parties' => [
            'title' => 'The parties',
            'text' => "This residential lease agreement is made between:\nLandlord: :landlord, phone :landlord_phone, ID no. :landlord_id, address :landlord_address.\nTenant: :tenant, phone :tenant_phone, ID no. :tenant_id, address :tenant_address.",
        ],
        'home' => [
            'title' => 'The home',
            'text' => 'The landlord rents to the tenant the home :unit:bedrooms at :property (:location, house no. :address), for use as a residence.',
        ],
        'term' => [
            'title' => 'Term',
            'text' => 'The lease runs from :start to :end (:length).',
        ],
        'rent' => [
            'title' => 'Rent and payment',
            'text' => 'The monthly rent is :rent. The tenant pays it :due. Rent can be paid in cash, by Telebirr or by bank transfer:online. The landlord gives a receipt for every payment (by SMS or Telegram, with a receipt link).',
        ],
        'deposit' => [
            'title' => 'Deposit',
            'text' => 'The tenant has paid a deposit of :deposit. The landlord holds it for the length of the lease and returns it when the tenant moves out, less any unpaid rent or utility shares and the cost of repairing damage beyond normal wear and tear, with a written statement of any deductions.',
        ],
        'no_deposit' => [
            'title' => 'Deposit',
            'text' => 'No deposit has been paid.',
        ],
        'shared_utilities' => [
            'title' => 'Electricity and water',
            'text' => 'The home shares these meters with other homes: :meters. The tenant pays their share of each bill together with the rent, with a written breakdown. Services the home has on its own meter are paid by the tenant directly.',
        ],
        'own_utilities' => [
            'title' => 'Electricity and water',
            'text' => 'The tenant pays for the electricity, water and other services used in the home directly, unless the parties agree otherwise in writing.',
        ],
        'rent_changes' => [
            'title' => 'Changes to the rent',
            'text' => 'The rent can only be changed in line with the rent law: at most once a year, by no more than the limit set for that year (currently :cap%), and with advance written notice to the tenant.',
        ],
        'use' => [
            'title' => 'Use of the home',
            'text' => 'The tenant uses the home only as a residence, keeps it in good order, and does not sublet it or hand it to anyone else without the landlord\'s written consent. The landlord respects the tenant\'s privacy and enters the home only after giving notice, except in an emergency.',
        ],
        'repairs' => [
            'title' => 'Repairs',
            'text' => 'The tenant reports problems promptly, by phone or through the repair link the landlord provides. The landlord repairs the structure, roof, plumbing, wiring and the landlord\'s fixtures within a reasonable time. The tenant pays for damage that they, their family or their guests cause beyond normal wear and tear.',
        ],
        'condition' => [
            'title' => 'Condition of the home at the start',
            'text' => "Condition of the home when the tenant moved in:\n:condition",
        ],
        'ending' => [
            'title' => 'End of the lease',
            'text' => 'The lease ends at the end of the term unless both parties renew it in writing. Either party may end it earlier only as this agreement and the rent law allow, by giving the other party written notice. When the tenant moves out, the parties check the condition of the home together and the landlord settles the deposit as set out above.',
        ],
        'registration' => [
            'title' => 'Registration',
            'text' => 'The parties will register this agreement with the woreda or sub-city office as the rent law requires. Each party keeps a signed copy.',
        ],
        'extra_terms' => [
            'title' => 'Additional terms',
            'text' => ':terms',
        ],
    ],
    'signatures' => 'Signatures',
    'signed_on' => 'Signed on: :date',
    'landlord' => 'Landlord',
    'tenant' => 'Tenant',
    'witness' => 'Witness :n',
    'name' => 'Name',
    'signature' => 'Signature',
    'checklist' => [
        'title' => 'Registering the agreement',
        'items' => [
            'Make enough copies: one for each party and one for the office. Both parties and the two witnesses sign every page of every copy.',
            'Take the ID (kebele ID or Fayda) of the landlord, the tenant and the witnesses.',
            'If the office asks for it, take proof that the landlord owns the home or may rent it out (for example the title deed).',
            'Ask for the registration number and write it on your copies. Record it in the app: lease > Lease agreement > Record registration.',
            'Requirements can differ between offices, so check with your woreda office before you go.',
        ],
    ],
    'disclaimer' => 'Prepared with :app from the lease details. Check it against the current rent law, and get legal advice if you are unsure about anything.',
    'too_many_photos' => 'A lease can have at most 10 photos of the signed agreement.',
];
