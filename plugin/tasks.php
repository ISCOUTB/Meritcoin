<?php
// db/tasks.php - Tareas programadas del plugin MeritCoin
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_meritcoin\task\send_events',
        'blocking'  => 0,
        'minute'    => '*/5',   // cada 5 minutos
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
];
