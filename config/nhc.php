<?php

/*
| Staff roles. A person has one NHC ID for life; a role belongs to a job at one
| hospital (facility_memberships), so the same person can be a doctor at one
| hospital and a consultant visitor at another. The app opens the dashboard
| for the role at the hospital they picked. Every role can message colleagues.
*/

return [
    // Where "Join NHC" enquiries from the website are emailed. Empty: saved only.
    'enquiries_to' => env('NHC_ENQUIRIES_TO'),

    // The NHC website: emails link to it and show its logo (public/logo.png there).
    'web_url' => rtrim(env('NHC_WEB_URL', 'https://nhc.hospify.africa'), '/'),

    'roles' => [
        'admin' => [
            'label' => 'Admin',
            'dashboard' => 'admin',
            'permissions' => ['staff.invite', 'staff.manage', 'features.manage', 'patients.onboard', 'patients.view', 'records.request', 'records.release', 'reports.view', 'messages.send', 'pharmacy.catalogue', 'pharmacy.orders', 'care.manage', 'schedule.manage', 'insurance.plans', 'insurance.claims', 'insurance.confirm'],
        ],
        'doctor' => [
            'label' => 'Doctor',
            'dashboard' => 'clinical',
            'licence' => 'MDCN',
            'permissions' => ['patients.view', 'records.read', 'records.write', 'records.request', 'prescriptions.write', 'lab.request', 'referrals.write', 'messages.send', 'care.manage', 'insurance.confirm'],
        ],
        'nurse' => [
            'label' => 'Nurse',
            'dashboard' => 'nursing',
            'licence' => 'NMCN',
            'permissions' => ['patients.view', 'patients.onboard', 'records.read', 'vitals.write', 'messages.send'],
        ],
        'midwife' => [
            'label' => 'Midwife',
            'dashboard' => 'nursing',
            'licence' => 'NMCN',
            'permissions' => ['patients.view', 'records.read', 'vitals.write', 'messages.send'],
        ],
        'pharmacist' => [
            'label' => 'Pharmacist',
            'dashboard' => 'pharmacy',
            'licence' => 'PCN',
            'permissions' => ['patients.view', 'patients.onboard', 'prescriptions.read', 'prescriptions.dispense', 'pharmacy.catalogue', 'pharmacy.orders', 'messages.send'],
        ],
        'pharmacy_technician' => [
            'label' => 'Pharmacy technician',
            'dashboard' => 'pharmacy',
            'licence' => 'PCN',
            'permissions' => ['patients.view', 'prescriptions.read', 'pharmacy.orders', 'messages.send'],
        ],
        'dentist' => [
            'label' => 'Dentist',
            'dashboard' => 'clinical',
            'licence' => 'MDCN',
            'permissions' => ['patients.view', 'records.read', 'records.write', 'records.request', 'prescriptions.write', 'lab.request', 'referrals.write', 'messages.send', 'care.manage'],
        ],
        'optometrist' => [
            'label' => 'Optometrist',
            'dashboard' => 'clinical',
            'licence' => 'ODORBN',
            'permissions' => ['patients.view', 'records.read', 'records.write', 'records.request', 'prescriptions.write', 'referrals.write', 'messages.send', 'care.manage'],
        ],
        // Works solo (Hospify Pro) or in a hospital or physiotherapy clinic: sessions,
        // treatment plans and referrals, no prescriptions.
        'physiotherapist' => [
            'label' => 'Physiotherapist',
            'dashboard' => 'clinical',
            'licence' => 'MRTB',
            'permissions' => ['patients.view', 'patients.onboard', 'records.read', 'records.write', 'records.request', 'referrals.write', 'messages.send', 'care.manage'],
        ],
        'lab_scientist' => [
            'label' => 'Medical lab scientist',
            'dashboard' => 'laboratory',
            'licence' => 'MLSCN',
            'permissions' => ['patients.view', 'lab.read', 'lab.results', 'messages.send', 'care.manage'],
        ],
        'radiographer' => [
            'label' => 'Radiographer',
            'dashboard' => 'radiology',
            'licence' => 'RRBN',
            'permissions' => ['patients.view', 'imaging.read', 'imaging.results', 'messages.send', 'care.manage'],
        ],
        'front_desk' => [
            'label' => 'Front desk',
            'dashboard' => 'front_desk',
            'permissions' => ['patients.onboard', 'patients.view', 'appointments.manage', 'messages.send', 'care.manage', 'schedule.manage', 'insurance.confirm'],
        ],
        'records_officer' => [
            'label' => 'Records officer',
            'dashboard' => 'front_desk',
            'permissions' => ['patients.onboard', 'patients.view', 'records.request', 'records.release', 'appointments.manage', 'messages.send', 'insurance.confirm'],
        ],
        'cashier' => [
            'label' => 'Cashier',
            'dashboard' => 'billing',
            'permissions' => ['patients.view', 'billing.manage', 'messages.send'],
        ],
        // Insurers (spec 0002): sets up plans with the admin, works the claims.
        'claims_officer' => [
            'label' => 'Claims officer',
            'dashboard' => 'claims',
            'permissions' => ['insurance.claims', 'messages.send'],
        ],
    ],

    /*
     * Facility types. The type picks the roles a facility can have, the
     * features it's offered (true: on from the start; false: offered, off until
     * an admin switches it on) and what the portal calls the people it serves.
     * Core features are always on. Features a type doesn't list are hidden.
     */
    'facility_types' => [
        'solo' => [
            'label' => 'Solo professional', 'people' => 'Patients',
            'roles' => ['admin', 'doctor', 'pharmacist', 'physiotherapist'],
            'features' => ['clinical_records' => true, 'prescriptions' => true, 'appointments' => true, 'messaging' => true],
        ],
        'hospital' => [
            'label' => 'Hospital', 'people' => 'Patients',
            'roles' => ['admin', 'doctor', 'nurse', 'midwife', 'pharmacist', 'pharmacy_technician', 'lab_scientist', 'radiographer', 'dentist', 'optometrist', 'physiotherapist', 'front_desk', 'records_officer', 'cashier'],
            'features' => ['clinical_records' => true, 'prescriptions' => true, 'record_sharing' => true, 'appointments' => true, 'wards' => true, 'billing' => true, 'messaging' => true],
        ],
        'clinic' => [
            'label' => 'Clinic', 'people' => 'Patients',
            'roles' => ['admin', 'doctor', 'nurse', 'pharmacist', 'lab_scientist', 'front_desk', 'records_officer', 'cashier'],
            'features' => ['clinical_records' => true, 'prescriptions' => true, 'record_sharing' => true, 'appointments' => true, 'billing' => true, 'messaging' => true],
        ],
        'primary_health_centre' => [
            'label' => 'Primary health centre', 'people' => 'Patients',
            'roles' => ['admin', 'doctor', 'nurse', 'midwife', 'pharmacy_technician', 'front_desk', 'records_officer', 'cashier'],
            'features' => ['clinical_records' => true, 'prescriptions' => true, 'record_sharing' => true, 'appointments' => true, 'wards' => false, 'billing' => true, 'messaging' => true],
        ],
        'maternity_home' => [
            'label' => 'Maternity home', 'people' => 'Patients',
            'roles' => ['admin', 'doctor', 'midwife', 'nurse', 'front_desk', 'records_officer', 'cashier'],
            'features' => ['clinical_records' => true, 'prescriptions' => true, 'record_sharing' => true, 'appointments' => true, 'wards' => true,
                'billing' => true, 'messaging' => true],
        ],
        'pharmacy' => [
            'label' => 'Pharmacy', 'people' => 'Customers',
            'roles' => ['admin', 'pharmacist', 'pharmacy_technician', 'front_desk', 'cashier'],
            'features' => ['billing' => true, 'messaging' => true],
        ],
        'laboratory' => [
            'label' => 'Laboratory', 'people' => 'Patients',
            'roles' => ['admin', 'lab_scientist', 'front_desk', 'records_officer', 'cashier'],
            'features' => ['record_sharing' => true, 'appointments' => true, 'billing' => true, 'messaging' => true],
        ],
        'diagnostic_centre' => [
            'label' => 'Diagnostic centre', 'people' => 'Patients',
            'roles' => ['admin', 'radiographer', 'lab_scientist', 'doctor', 'front_desk', 'records_officer', 'cashier'],
            'features' => ['record_sharing' => true, 'appointments' => true, 'billing' => true, 'messaging' => true],
        ],
        'dental_clinic' => [
            'label' => 'Dental clinic', 'people' => 'Patients',
            'roles' => ['admin', 'dentist', 'nurse', 'front_desk', 'records_officer', 'cashier'],
            'features' => ['clinical_records' => true, 'prescriptions' => true, 'record_sharing' => true, 'appointments' => true, 'billing' => true, 'messaging' => true],
        ],
        'optometry_clinic' => [
            'label' => 'Optometry clinic', 'people' => 'Patients',
            'roles' => ['admin', 'optometrist', 'front_desk', 'records_officer', 'cashier'],
            'features' => ['clinical_records' => true, 'prescriptions' => true, 'record_sharing' => true, 'appointments' => true, 'billing' => true, 'messaging' => true],
        ],
        'physiotherapy_clinic' => [
            'label' => 'Physiotherapy clinic', 'people' => 'Patients',
            'roles' => ['admin', 'physiotherapist', 'nurse', 'front_desk', 'records_officer', 'cashier'],
            'features' => ['clinical_records' => true, 'record_sharing' => true, 'appointments' => true,
                'billing' => true, 'messaging' => true],
        ],
    ],

    // What another hospital can ask for (Record sharing). The patient and the
    // hospital holding the records can each narrow a request, never widen it.
    'record_types' => [
        'summary' => 'Summary: allergies, conditions, current medicines, blood group',
        'consultations' => 'Consultation notes',
        'prescriptions' => 'Prescriptions',
        'lab_results' => 'Lab results',
        'imaging' => 'Imaging and reports',
        'discharge_summaries' => 'Discharge summaries',
        'vaccinations' => 'Vaccinations',
    ],

    // How long view access lasts, at most, and by default.
    'record_access' => ['max_days' => 365, 'default_days' => 30],

    // Accepted at onboarding. The front desk sees the original in person.
    'id_documents' => [
        'nin' => ['label' => 'National ID (NIN)', 'pattern' => '/^\d{11}$/'],
        'voters_card' => ['label' => "Voter's card (PVC)", 'pattern' => '/^[A-Z0-9]{19}$/'],
        'drivers_licence' => ['label' => "Driver's licence", 'pattern' => '/^[A-Z0-9]{8,15}$/'],
        'passport' => ['label' => 'International passport', 'pattern' => '/^[A-Z]\d{8}$/'],
    ],
];
