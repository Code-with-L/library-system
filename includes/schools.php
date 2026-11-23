<?php
/** MMUST schools offered as department choices. */
function mmust_schools(): array
{
    return [
        'School of Agriculture and Veterinary Sciences (SAVET)',
        'School of Arts and Social Sciences (SASS)',
        'School of Business and Economics (SOBE)',
        'School of Computing and Informatics (SCI)',
        'School of Disaster Management and Humanitarian Assistance (SDMHA)',
        'School of Education (SEDU)',
        'School of Engineering and the Built Environment (SEBE)',
        'School of Medicine (SOM)',
        'School of Natural Sciences (SONAS)',
        'School of Nursing, Midwifery and Paramedical Sciences (SONMAPS)',
        'School of Public Health, Biomedical Sciences & Technology (SPHBST)',
    ];
}

function mmust_school_options(string $selected = ''): string
{
    $html = '<option value="">Select a school</option>';
    foreach (mmust_schools() as $school) {
        $html .= '<option value="' . e($school) . '"'
            . ($selected === $school ? ' selected' : '') . '>' . e($school) . '</option>';
    }
    return $html;
}
