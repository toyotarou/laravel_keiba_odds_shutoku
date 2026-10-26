<?php
return [
    // Off until installed in an isolated non-public verification environment.
    'enabled' => filter_var(env('BAGANRIKI_R122_JOBS', false), FILTER_VALIDATE_BOOL),
    'private' => filter_var(env('BAGANRIKI_R122_PRIVATE', true), FILTER_VALIDATE_BOOL),
    // Empty denies all. Maximum 12 unique exact race_id values. No implicit replacement races.
    'race_ids' => array_values(array_unique(array_filter(array_map('trim',
        explode(',', (string) env('BAGANRIKI_R122_RACE_IDS', '')))))),
];

