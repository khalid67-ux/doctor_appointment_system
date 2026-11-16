<?php

return [
    'from_email' => getenv('MAIL_FROM') ?: 'medibook.help@gmail.com',
    'from_name'  => 'MediBook Support',
    'to_email'   => getenv('MAIL_TO') ?: 'medibook.help@gmail.com',
];
