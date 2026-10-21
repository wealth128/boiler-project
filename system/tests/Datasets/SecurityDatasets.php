<?php

/*
 * Datasets shared by the Step 7a security tests.
 */

dataset('bad delete reasons', [
    'no reason' => [[], 'reason'],
    'empty reason' => [['reason' => ''], 'reason'],
    'reason not on the list' => [['reason' => 'Because I said so'], 'reason'],
    'reason from another list' => [['reason' => 'Wrong month'], 'reason'],
    'reason a list' => [['reason' => ['Duplicate entry']], 'reason'],
    'reason a number' => [['reason' => 5], 'reason'],
    'Other without text' => [['reason' => 'Other (specify)'], 'reason_other'],
    'Other with 9 characters' => [['reason' => 'Other (specify)', 'reason_other' => '123456789'], 'reason_other'],
    'Other with 201 characters' => [['reason' => 'Other (specify)', 'reason_other' => str_repeat('x', 201)], 'reason_other'],
    'Other text a list' => [['reason' => 'Other (specify)', 'reason_other' => ['long enough text here']], 'reason_other'],
    'Other text only spaces' => [['reason' => 'Other (specify)', 'reason_other' => str_repeat(' ', 20)], 'reason_other'],
]);
