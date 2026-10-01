<?php

return [
    'institution' => defined('UNIVERSITY_NAME') ? UNIVERSITY_NAME : 'Al Yamamah University',
    'colleges' => [
        'College of Business',
        'College of Engineering',
        'College of Law'
    ],
    'programs' => [
        ['code' => 'ACC', 'name' => 'Accounting', 'college' => 'College of Business', 'department' => 'Accounting & Finance Department', 'level' => 'Undergraduate'],
        ['code' => 'FIN', 'name' => 'Finance', 'college' => 'College of Business', 'department' => 'Accounting & Finance Department', 'level' => 'Undergraduate'],
        ['code' => 'MGT', 'name' => 'Management', 'college' => 'College of Business', 'department' => 'Management and Marketing Department', 'level' => 'Undergraduate'],
        ['code' => 'MKT', 'name' => 'Marketing', 'college' => 'College of Business', 'department' => 'Management and Marketing Department', 'level' => 'Undergraduate'],
        ['code' => 'MIS', 'name' => 'Management Information Systems', 'college' => 'College of Business', 'department' => 'Management Information Systems', 'level' => 'Undergraduate'],
        ['code' => 'AIA', 'name' => 'Architecture', 'college' => 'College of Engineering', 'department' => 'Architecture Department', 'level' => 'Undergraduate'],
        ['code' => 'CNE', 'name' => 'Computer Network Engineering', 'college' => 'College of Engineering', 'department' => 'Computer Engineering Department', 'level' => 'Undergraduate'],
        ['code' => 'SWE', 'name' => 'Software Engineering', 'college' => 'College of Engineering', 'department' => 'Computer Engineering Department', 'level' => 'Undergraduate'],
        ['code' => 'IE', 'name' => 'Industrial Engineering', 'college' => 'College of Engineering', 'department' => 'Industrial Engineering Department', 'level' => 'Undergraduate'],
        ['code' => 'LL.B', 'name' => 'Bachelor of Law', 'college' => 'College of Law', 'department' => 'Law Department', 'level' => 'Undergraduate'],
        ['code' => 'MCS', 'name' => 'Master in Cyber Security', 'college' => 'College of Engineering', 'department' => 'Computer Engineering Department', 'level' => 'Postgraduate'],
        ['code' => 'MBA', 'name' => 'Masters of Business Administration (MBA)', 'college' => 'College of Business', 'department' => 'Management and Marketing Department', 'level' => 'Postgraduate'],
        ['code' => 'EMBA', 'name' => 'Executive Masters of Business Administration (EMBA)', 'college' => 'College of Business', 'department' => 'Management and Marketing Department', 'level' => 'Postgraduate'],
        ['code' => 'MBL', 'name' => 'Masters of Business Law', 'college' => 'College of Law', 'department' => 'Law Department', 'level' => 'Postgraduate']
    ]
];
